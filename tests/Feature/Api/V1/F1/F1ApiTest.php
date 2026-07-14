<?php

namespace Tests\Feature\Api\V1\F1;

use App\Jobs\F1\SyncF1ChampionshipJob;
use App\Jobs\F1\SyncF1SeasonJob;
use App\Jobs\F1\SyncF1SessionDetailJob;
use App\Models\F1\F1ChampionshipDriver;
use App\Models\F1\F1ChampionshipTeam;
use App\Models\F1\F1Driver;
use App\Models\F1\F1Meeting;
use App\Models\F1\F1Session;
use App\Models\F1\F1SessionResult;
use App\Models\User;
use App\Services\F1\F1HomeService;
use App\Services\F1\F1SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class F1ApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openf1.base_url' => 'https://api.openf1.org/v1',
            'services.rate_limits.openf1' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
        ]);
    }

    public function test_status_requires_authentication(): void
    {
        $this->getJson('/api/v1/f1/status')->assertUnauthorized();
    }

    public function test_status_home_season_and_standings(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $meeting = F1Meeting::query()->create([
            'meeting_key' => 1200,
            'year' => now('UTC')->year,
            'meeting_name' => 'Test Grand Prix',
            'circuit_short_name' => 'Test',
            'country_name' => 'Testland',
            'date_start' => now('UTC')->addDays(7),
            'date_end' => now('UTC')->addDays(9),
        ]);

        F1Session::query()->create([
            'session_key' => 9000,
            'meeting_key' => $meeting->meeting_key,
            'year' => $meeting->year,
            'session_name' => 'Race',
            'session_type' => 'Race',
            'date_start' => now('UTC')->subDays(2),
            'date_end' => now('UTC')->subDays(2)->addHours(2),
            'detail_synced_at' => now(),
        ]);

        F1Driver::query()->create([
            'meeting_key' => $meeting->meeting_key,
            'session_key' => 9000,
            'driver_number' => 1,
            'full_name' => 'Max VERSTAPPEN',
            'name_acronym' => 'VER',
            'team_name' => 'Red Bull Racing',
            'team_colour' => '3671C6',
        ]);

        F1ChampionshipDriver::query()->create([
            'meeting_key' => $meeting->meeting_key,
            'session_key' => 9000,
            'year' => $meeting->year,
            'driver_number' => 1,
            'position_current' => 1,
            'points_current' => 100,
        ]);

        F1ChampionshipTeam::query()->create([
            'meeting_key' => $meeting->meeting_key,
            'session_key' => 9000,
            'year' => $meeting->year,
            'team_name' => 'Red Bull Racing',
            'position_current' => 1,
            'points_current' => 200,
        ]);

        app(F1HomeService::class)->rebuild();

        $this->getJson('/api/v1/f1/status')
            ->assertOk()
            ->assertJsonPath('provider', 'openf1')
            ->assertJsonPath('live_tracking', false);

        $this->getJson('/api/v1/f1/home')
            ->assertOk()
            ->assertJsonPath('live_tracking', false)
            ->assertJsonStructure(['next_meeting', 'standings_drivers_top']);

        $this->getJson('/api/v1/f1/season')
            ->assertOk()
            ->assertJsonPath('meetings.0.meeting_name', 'Test Grand Prix');

        $this->getJson('/api/v1/f1/standings')
            ->assertOk()
            ->assertJsonPath('drivers.0.driver_number', 1)
            ->assertJsonPath('teams.0.team_name', 'Red Bull Racing');
    }

    public function test_sync_queues_jobs(): void
    {
        Bus::fake();

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/f1/sync', ['type' => 'all'])
            ->assertOk()
            ->assertJsonPath('message', 'F1 sync queued.');

        Bus::assertDispatched(SyncF1SeasonJob::class);
        Bus::assertDispatched(SyncF1ChampionshipJob::class);
        Bus::assertDispatched(SyncF1SessionDetailJob::class);
    }

    public function test_session_detail_and_analysis(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        F1Meeting::query()->create([
            'meeting_key' => 1219,
            'year' => 2023,
            'meeting_name' => 'Singapore Grand Prix',
            'date_start' => now('UTC')->subDays(10),
            'date_end' => now('UTC')->subDays(8),
        ]);

        F1Session::query()->create([
            'session_key' => 9165,
            'meeting_key' => 1219,
            'year' => 2023,
            'session_name' => 'Race',
            'session_type' => 'Race',
            'date_start' => now('UTC')->subDays(8),
            'date_end' => now('UTC')->subDays(8)->addHours(2),
            'detail_synced_at' => now(),
        ]);

        F1SessionResult::query()->create([
            'meeting_key' => 1219,
            'session_key' => 9165,
            'driver_number' => 1,
            'position' => 1,
            'duration' => [5241.123],
            'gap_to_leader' => [0],
            'number_of_laps' => 62,
        ]);

        $this->getJson('/api/v1/f1/sessions/9165')
            ->assertOk()
            ->assertJsonPath('detail_synced', true)
            ->assertJsonPath('results.0.position', 1);

        $this->getJson('/api/v1/f1/sessions/9165/analysis')
            ->assertOk()
            ->assertJsonPath('detail_synced', true)
            ->assertJsonStructure(['laps', 'pits', 'stints', 'positions']);
    }

    public function test_season_sync_persists_meetings_and_sessions(): void
    {
        Http::fake([
            'api.openf1.org/v1/meetings*' => Http::response([
                [
                    'meeting_key' => 1296,
                    'year' => 2026,
                    'meeting_name' => 'Singapore Grand Prix',
                    'circuit_short_name' => 'Singapore',
                    'country_name' => 'Singapore',
                    'date_start' => '2026-10-09T09:30:00+00:00',
                    'date_end' => '2026-10-11T14:00:00+00:00',
                    'is_cancelled' => false,
                ],
            ]),
            'api.openf1.org/v1/sessions*' => Http::response([
                [
                    'session_key' => 9901,
                    'meeting_key' => 1296,
                    'year' => 2026,
                    'session_name' => 'Race',
                    'session_type' => 'Race',
                    'date_start' => '2026-10-11T12:00:00+00:00',
                    'date_end' => '2026-10-11T14:00:00+00:00',
                    'is_cancelled' => false,
                ],
            ]),
        ]);

        $run = app(F1SyncService::class)->syncSeason(2026);

        $this->assertSame('ok', $run->status);
        $this->assertDatabaseHas('f1_meetings', [
            'meeting_key' => 1296,
            'meeting_name' => 'Singapore Grand Prix',
        ]);
        $this->assertDatabaseHas('f1_sessions', [
            'session_key' => 9901,
            'session_name' => 'Race',
        ]);
    }

    public function test_replay_status_for_historical_session(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        F1Session::query()->create([
            'session_key' => 9165,
            'meeting_key' => 1219,
            'year' => 2023,
            'session_name' => 'Race',
            'session_type' => 'Race',
            'date_start' => now('UTC')->subDays(3),
            'date_end' => now('UTC')->subDays(3)->addHours(2),
            'replay_status' => null,
        ]);

        $this->getJson('/api/v1/f1/sessions/9165/replay/status')
            ->assertOk()
            ->assertJsonPath('available', true);
    }
}
