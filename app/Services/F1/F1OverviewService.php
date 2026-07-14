<?php

namespace App\Services\F1;

use App\Integrations\OpenF1\OpenF1Integration;
use App\Jobs\F1\SyncF1SessionDetailJob;
use App\Models\F1\F1ChampionshipDriver;
use App\Models\F1\F1ChampionshipTeam;
use App\Models\F1\F1Driver;
use App\Models\F1\F1Lap;
use App\Models\F1\F1Meeting;
use App\Models\F1\F1Overtake;
use App\Models\F1\F1Pit;
use App\Models\F1\F1Position;
use App\Models\F1\F1RaceControl;
use App\Models\F1\F1Session;
use App\Models\F1\F1SessionResult;
use App\Models\F1\F1StartingGrid;
use App\Models\F1\F1Stint;
use App\Models\F1\F1SyncRun;
use App\Models\F1\F1Weather;
use Illuminate\Support\Facades\Cache;

class F1OverviewService
{
    public function status(): array
    {
        $year = (int) now('UTC')->year;
        $lastByJob = F1SyncRun::query()
            ->where('status', F1SyncRun::STATUS_OK)
            ->orderByDesc('finished_at')
            ->limit(20)
            ->get()
            ->unique('job')
            ->mapWithKeys(fn (F1SyncRun $run) => [
                $run->job => $run->finished_at?->toIso8601String(),
            ]);

        return [
            'provider' => OpenF1Integration::PROVIDER,
            'year' => $year,
            'meeting_count' => F1Meeting::query()->where('year', $year)->count(),
            'session_count' => F1Session::query()->where('year', $year)->count(),
            'detail_pending' => F1Session::query()
                ->whereNull('detail_synced_at')
                ->whereNotNull('date_end')
                ->where('date_end', '<=', now('UTC')->subMinutes(
                    (int) config('services.openf1.sync.live_buffer_minutes', 35),
                ))
                ->count(),
            'last_ok_by_job' => $lastByJob,
            'last_failed' => F1SyncRun::query()
                ->where('status', F1SyncRun::STATUS_FAILED)
                ->orderByDesc('finished_at')
                ->first([
                    'job',
                    'error',
                    'finished_at',
                ]),
            'live_tracking' => false,
            'rate_limit' => config('services.rate_limits.openf1'),
        ];
    }

    public function season(?int $year = null): array
    {
        $year ??= (int) now('UTC')->year;

        $meetings = F1Meeting::query()
            ->where('year', $year)
            ->orderBy('date_start')
            ->with(['sessions' => fn ($q) => $q->orderBy('date_start')])
            ->get()
            ->map(fn (F1Meeting $m) => [
                'meeting_key' => $m->meeting_key,
                'meeting_name' => $m->meeting_name,
                'meeting_official_name' => $m->meeting_official_name,
                'circuit_short_name' => $m->circuit_short_name,
                'circuit_image' => $m->circuit_image,
                'country_name' => $m->country_name,
                'country_flag' => $m->country_flag,
                'location' => $m->location,
                'date_start' => $m->date_start?->toIso8601String(),
                'date_end' => $m->date_end?->toIso8601String(),
                'is_cancelled' => $m->is_cancelled,
                'year' => $m->year,
                'sessions' => $m->sessions->map(fn (F1Session $s) => $this->mapSessionSummary($s))->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'year' => $year,
            'meetings' => $meetings,
        ];
    }

    public function standings(?int $year = null): array
    {
        $year ??= (int) now('UTC')->year;

        $sessionKey = F1ChampionshipDriver::query()
            ->where('year', $year)
            ->max('session_key');

        if (! $sessionKey) {
            return [
                'year' => $year,
                'session_key' => null,
                'drivers' => [],
                'teams' => [],
            ];
        }

        $driverMeta = $this->latestDriverMeta($year);

        $drivers = F1ChampionshipDriver::query()
            ->where('session_key', $sessionKey)
            ->orderBy('position_current')
            ->get()
            ->map(fn (F1ChampionshipDriver $row) => [
                'driver_number' => $row->driver_number,
                'position' => $row->position_current,
                'position_start' => $row->position_start,
                'points' => $row->points_current,
                'points_start' => $row->points_start,
                'name' => $driverMeta[$row->driver_number]['name'] ?? null,
                'team_name' => $driverMeta[$row->driver_number]['team_name'] ?? null,
                'team_colour' => $driverMeta[$row->driver_number]['team_colour'] ?? null,
                'name_acronym' => $driverMeta[$row->driver_number]['name_acronym'] ?? null,
                'headshot_url' => $driverMeta[$row->driver_number]['headshot_url'] ?? null,
            ])
            ->values()
            ->all();

        $teams = F1ChampionshipTeam::query()
            ->where('session_key', $sessionKey)
            ->orderBy('position_current')
            ->get()
            ->map(fn (F1ChampionshipTeam $row) => [
                'team_name' => $row->team_name,
                'position' => $row->position_current,
                'position_start' => $row->position_start,
                'points' => $row->points_current,
                'points_start' => $row->points_start,
            ])
            ->values()
            ->all();

        return [
            'year' => $year,
            'session_key' => (int) $sessionKey,
            'drivers' => $drivers,
            'teams' => $teams,
        ];
    }

    public function meeting(int $meetingKey): array
    {
        $meeting = F1Meeting::query()
            ->where('meeting_key', $meetingKey)
            ->with(['sessions' => fn ($q) => $q->orderBy('date_start')])
            ->firstOrFail();

        return [
            'meeting_key' => $meeting->meeting_key,
            'meeting_name' => $meeting->meeting_name,
            'meeting_official_name' => $meeting->meeting_official_name,
            'circuit_short_name' => $meeting->circuit_short_name,
            'circuit_image' => $meeting->circuit_image,
            'circuit_type' => $meeting->circuit_type,
            'country_name' => $meeting->country_name,
            'country_flag' => $meeting->country_flag,
            'location' => $meeting->location,
            'gmt_offset' => $meeting->gmt_offset,
            'date_start' => $meeting->date_start?->toIso8601String(),
            'date_end' => $meeting->date_end?->toIso8601String(),
            'is_cancelled' => $meeting->is_cancelled,
            'year' => $meeting->year,
            'sessions' => $meeting->sessions->map(fn (F1Session $s) => $this->mapSessionSummary($s))->values()->all(),
        ];
    }

    public function session(int $sessionKey): array
    {
        $session = F1Session::query()->where('session_key', $sessionKey)->firstOrFail();

        if ($session->detail_synced_at === null && $session->isHistoricallyAvailable()) {
            $this->enqueueDetailSync($sessionKey);
        }

        $drivers = F1Driver::query()
            ->where('session_key', $sessionKey)
            ->orderBy('driver_number')
            ->get()
            ->map(fn (F1Driver $d) => [
                'driver_number' => $d->driver_number,
                'broadcast_name' => $d->broadcast_name,
                'full_name' => $d->full_name,
                'name_acronym' => $d->name_acronym,
                'team_name' => $d->team_name,
                'team_colour' => $d->team_colour,
                'headshot_url' => $d->headshot_url,
            ])
            ->values()
            ->all();

        $results = F1SessionResult::query()
            ->where('session_key', $sessionKey)
            ->orderBy('position')
            ->get()
            ->map(fn (F1SessionResult $r) => [
                'driver_number' => $r->driver_number,
                'position' => $r->position,
                'duration' => $r->duration,
                'gap_to_leader' => $r->gap_to_leader,
                'number_of_laps' => $r->number_of_laps,
                'dnf' => $r->dnf,
                'dns' => $r->dns,
                'dsq' => $r->dsq,
            ])
            ->values()
            ->all();

        $grid = F1StartingGrid::query()
            ->where('session_key', $sessionKey)
            ->orderBy('position')
            ->get()
            ->map(fn (F1StartingGrid $g) => [
                'driver_number' => $g->driver_number,
                'position' => $g->position,
                'lap_duration' => $g->lap_duration,
            ])
            ->values()
            ->all();

        return [
            'session' => $this->mapSessionSummary($session),
            'meeting' => $this->meetingBrief($session->meeting_key),
            'detail_synced' => $session->detail_synced_at !== null,
            'detail_available' => $session->isHistoricallyAvailable(),
            'replay_status' => $session->replay_status,
            'drivers' => $drivers,
            'results' => $results,
            'starting_grid' => $grid,
        ];
    }

    public function analysis(int $sessionKey): array
    {
        $session = F1Session::query()->where('session_key', $sessionKey)->firstOrFail();

        if ($session->detail_synced_at === null) {
            if ($session->isHistoricallyAvailable()) {
                $this->enqueueDetailSync($sessionKey);
            }

            return [
                'session_key' => $sessionKey,
                'detail_synced' => false,
                'detail_available' => $session->isHistoricallyAvailable(),
                'laps' => [],
                'pits' => [],
                'stints' => [],
                'positions' => [],
                'race_control' => [],
                'weather' => [],
                'overtakes' => [],
            ];
        }

        return [
            'session_key' => $sessionKey,
            'detail_synced' => true,
            'detail_available' => true,
            'laps' => F1Lap::query()
                ->where('session_key', $sessionKey)
                ->orderBy('driver_number')
                ->orderBy('lap_number')
                ->get([
                    'driver_number',
                    'lap_number',
                    'lap_duration',
                    'duration_sector_1',
                    'duration_sector_2',
                    'duration_sector_3',
                    'i1_speed',
                    'i2_speed',
                    'st_speed',
                    'is_pit_out_lap',
                ])
                ->toArray(),
            'pits' => F1Pit::query()
                ->where('session_key', $sessionKey)
                ->orderBy('date')
                ->get([
                    'driver_number',
                    'date',
                    'lap_number',
                    'lane_duration',
                    'stop_duration',
                ])
                ->map(fn (F1Pit $p) => [
                    'driver_number' => $p->driver_number,
                    'date' => $p->date?->toIso8601String(),
                    'lap_number' => $p->lap_number,
                    'lane_duration' => $p->lane_duration,
                    'stop_duration' => $p->stop_duration,
                ])
                ->values()
                ->all(),
            'stints' => F1Stint::query()
                ->where('session_key', $sessionKey)
                ->orderBy('driver_number')
                ->orderBy('stint_number')
                ->get([
                    'driver_number',
                    'stint_number',
                    'compound',
                    'lap_start',
                    'lap_end',
                    'tyre_age_at_start',
                ])
                ->toArray(),
            'positions' => F1Position::query()
                ->where('session_key', $sessionKey)
                ->orderBy('date')
                ->get(['driver_number', 'date', 'position'])
                ->map(fn (F1Position $p) => [
                    'driver_number' => $p->driver_number,
                    'date' => $p->date?->toIso8601String(),
                    'position' => $p->position,
                ])
                ->values()
                ->all(),
            'race_control' => F1RaceControl::query()
                ->where('session_key', $sessionKey)
                ->orderBy('date')
                ->get()
                ->map(fn (F1RaceControl $r) => [
                    'date' => $r->date?->toIso8601String(),
                    'category' => $r->category,
                    'flag' => $r->flag,
                    'scope' => $r->scope,
                    'driver_number' => $r->driver_number,
                    'lap_number' => $r->lap_number,
                    'message' => $r->message,
                ])
                ->values()
                ->all(),
            'weather' => F1Weather::query()
                ->where('session_key', $sessionKey)
                ->orderBy('date')
                ->get()
                ->map(fn (F1Weather $w) => [
                    'date' => $w->date?->toIso8601String(),
                    'air_temperature' => $w->air_temperature,
                    'track_temperature' => $w->track_temperature,
                    'humidity' => $w->humidity,
                    'rainfall' => $w->rainfall,
                    'wind_speed' => $w->wind_speed,
                ])
                ->values()
                ->all(),
            'overtakes' => F1Overtake::query()
                ->where('session_key', $sessionKey)
                ->orderBy('date')
                ->get()
                ->map(fn (F1Overtake $o) => [
                    'date' => $o->date?->toIso8601String(),
                    'overtaking_driver_number' => $o->overtaking_driver_number,
                    'overtaken_driver_number' => $o->overtaken_driver_number,
                    'position' => $o->position,
                ])
                ->values()
                ->all(),
        ];
    }

    private function enqueueDetailSync(int $sessionKey): void
    {
        // Debounce: one enqueue per session every few minutes, not on every page load.
        if (Cache::add('f1-detail-dispatch-'.$sessionKey, 1, now()->addMinutes(5))) {
            SyncF1SessionDetailJob::dispatch($sessionKey);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSessionSummary(F1Session $session): array
    {
        return [
            'session_key' => $session->session_key,
            'meeting_key' => $session->meeting_key,
            'session_name' => $session->session_name,
            'session_type' => $session->session_type,
            'date_start' => $session->date_start?->toIso8601String(),
            'date_end' => $session->date_end?->toIso8601String(),
            'is_cancelled' => $session->is_cancelled,
            'detail_synced' => $session->detail_synced_at !== null,
            'replay_status' => $session->replay_status,
            'historically_available' => $session->isHistoricallyAvailable(),
            'year' => $session->year,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function meetingBrief(int $meetingKey): ?array
    {
        $meeting = F1Meeting::query()->where('meeting_key', $meetingKey)->first();
        if ($meeting === null) {
            return null;
        }

        return [
            'meeting_key' => $meeting->meeting_key,
            'meeting_name' => $meeting->meeting_name,
            'circuit_short_name' => $meeting->circuit_short_name,
            'circuit_image' => $meeting->circuit_image,
            'country_name' => $meeting->country_name,
            'country_flag' => $meeting->country_flag,
            'location' => $meeting->location,
            'year' => $meeting->year,
        ];
    }

    /**
     * @return array<int, array{name: ?string, team_name: ?string, team_colour: ?string, name_acronym: ?string, headshot_url: ?string}>
     */
    private function latestDriverMeta(int $year): array
    {
        $meta = [];

        F1Driver::query()
            ->whereIn('session_key', F1Session::query()->where('year', $year)->select('session_key'))
            ->orderByDesc('session_key')
            ->get()
            ->each(function (F1Driver $driver) use (&$meta): void {
                if (isset($meta[$driver->driver_number])) {
                    return;
                }
                $meta[$driver->driver_number] = [
                    'name' => $driver->full_name ?? $driver->broadcast_name,
                    'team_name' => $driver->team_name,
                    'team_colour' => $driver->team_colour,
                    'name_acronym' => $driver->name_acronym,
                    'headshot_url' => $driver->headshot_url,
                ];
            });

        return $meta;
    }
}
