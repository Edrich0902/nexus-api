<?php

namespace Tests\Feature\Api\V1\F1;

use App\Models\F1\F1LocationSample;
use App\Models\F1\F1Session;
use App\Services\F1\F1ReplayService;
use App\Services\F1\F1SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class F1JobResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openf1.base_url' => 'https://api.openf1.org/v1',
            'services.openf1.sync.telemetry_chunk_seconds' => 120,
            'services.openf1.sync.location_hz' => 1.0,
            'services.rate_limits.openf1' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
        ]);
    }

    public function test_session_detail_treats_missing_starting_grid_as_empty(): void
    {
        F1Session::query()->create([
            'session_key' => 9165,
            'meeting_key' => 1219,
            'year' => 2023,
            'session_name' => 'Race',
            'session_type' => 'Race',
            'date_start' => now('UTC')->subDays(3),
            'date_end' => now('UTC')->subDays(3)->addHours(2),
        ]);

        Http::fake([
            'api.openf1.org/v1/drivers*' => Http::response([
                [
                    'meeting_key' => 1219,
                    'session_key' => 9165,
                    'driver_number' => 1,
                    'full_name' => 'Max VERSTAPPEN',
                    'name_acronym' => 'VER',
                    'team_name' => 'Red Bull Racing',
                    'team_colour' => '3671C6',
                ],
            ]),
            'api.openf1.org/v1/session_result*' => Http::response([
                [
                    'meeting_key' => 1219,
                    'session_key' => 9165,
                    'driver_number' => 1,
                    'position' => 1,
                    'duration' => 5000.1,
                    'gap_to_leader' => 0,
                    'number_of_laps' => 62,
                    'dnf' => false,
                    'dns' => false,
                    'dsq' => false,
                ],
            ]),
            'api.openf1.org/v1/starting_grid*' => Http::response(['detail' => 'Not found'], 404),
            'api.openf1.org/v1/laps*' => Http::response([]),
            'api.openf1.org/v1/pit*' => Http::response([]),
            'api.openf1.org/v1/stints*' => Http::response([]),
            'api.openf1.org/v1/position*' => Http::response([]),
            'api.openf1.org/v1/race_control*' => Http::response([]),
            'api.openf1.org/v1/weather*' => Http::response([]),
            'api.openf1.org/v1/overtakes*' => Http::response([]),
        ]);

        $run = app(F1SyncService::class)->syncSessionDetail(9165);

        $this->assertSame('ok', $run->status);
        $this->assertDatabaseHas('f1_sessions', [
            'session_key' => 9165,
        ]);
        $this->assertNotNull(
            F1Session::query()->where('session_key', 9165)->value('detail_synced_at'),
        );
        $this->assertDatabaseHas('f1_session_results', [
            'session_key' => 9165,
            'driver_number' => 1,
            'position' => 1,
        ]);
    }

    public function test_replay_splits_oversized_location_windows(): void
    {
        $start = now('UTC')->subDays(2)->startOfMinute();
        $end = $start->copy()->addMinutes(2);

        F1Session::query()->create([
            'session_key' => 9165,
            'meeting_key' => 1219,
            'year' => 2023,
            'session_name' => 'Race',
            'session_type' => 'Race',
            'date_start' => $start,
            'date_end' => $end,
        ]);

        \App\Models\F1\F1Driver::query()->create([
            'session_key' => 9165,
            'meeting_key' => 1219,
            'driver_number' => 1,
            'full_name' => 'Max VERSTAPPEN',
            'name_acronym' => 'VER',
            'team_name' => 'Red Bull Racing',
            'team_colour' => '3671C6',
        ]);

        Http::fake(function (\Illuminate\Http\Client\Request $request) {
            $url = $request->url();
            if (! str_contains($url, '/location')) {
                return Http::response([], 404);
            }

            // Full 2-minute window is "too large"; short windows succeed.
            if (preg_match('/date(?:%3E|>)=([^&]+).*date(?:%3C|<)=([^&]+)/', $url, $m)) {
                $from = urldecode($m[1]);
                $to = urldecode($m[2]);
                $seconds = abs(strtotime($to) - strtotime($from));
                if ($seconds > 70) {
                    return Http::response(['detail' => 'Payload too large'], 422);
                }

                return Http::response([
                    [
                        'date' => $from,
                        'driver_number' => 1,
                        'session_key' => 9165,
                        'meeting_key' => 1219,
                        'x' => 100,
                        'y' => 200,
                        'z' => 0,
                    ],
                ]);
            }

            return Http::response(['detail' => 'Payload too large'], 422);
        });

        $run = app(F1ReplayService::class)->syncDriverLocation(
            F1Session::query()->where('session_key', 9165)->firstOrFail(),
            1,
        );
        app(F1ReplayService::class)->markReplayReady(
            F1Session::query()->where('session_key', 9165)->firstOrFail(),
        );

        $this->assertSame('ok', $run->status);
        $this->assertGreaterThan(0, F1LocationSample::query()->where('session_key', 9165)->count());
        $this->assertDatabaseHas('f1_sessions', [
            'session_key' => 9165,
            'replay_status' => 'ready',
        ]);
    }
}
