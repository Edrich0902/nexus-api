<?php

namespace App\Services\F1;

use App\Integrations\OpenF1\OpenF1Integration;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class F1SyncService
{
    public function __construct(
        private readonly OpenF1Integration $openf1,
        private readonly F1HomeService $home,
    ) {}

    public function syncSeason(?int $year = null): F1SyncRun
    {
        $year ??= (int) now('UTC')->year;

        return $this->run('season', function (F1SyncRun $run) use ($year): void {
            $meetings = $this->openf1->meetings(['year' => $year]);
            $run->increment('calls_used');

            foreach ($meetings as $row) {
                $this->upsertMeeting($row);
            }

            $sessions = $this->openf1->sessions(['year' => $year]);
            $run->increment('calls_used');

            foreach ($sessions as $row) {
                $this->upsertSession($row);
            }
        });
    }

    public function syncChampionship(?int $year = null): F1SyncRun
    {
        $year ??= (int) now('UTC')->year;

        return $this->run('championship', function (F1SyncRun $run) use ($year): void {
            $race = F1Session::query()
                ->where('year', $year)
                ->where(function ($q) {
                    $q->where('session_type', 'Race')
                        ->orWhere('session_name', 'Race');
                })
                ->whereNotNull('date_end')
                ->where('date_end', '<', now('UTC')->subMinutes(
                    (int) config('services.openf1.sync.live_buffer_minutes', 35),
                ))
                ->orderByDesc('date_end')
                ->first();

            if ($race === null) {
                return;
            }

            $drivers = $this->openf1->championshipDrivers(['session_key' => $race->session_key]);
            $run->increment('calls_used');

            foreach ($drivers as $row) {
                F1ChampionshipDriver::query()->updateOrCreate(
                    [
                        'session_key' => (int) ($row['session_key'] ?? $race->session_key),
                        'driver_number' => (int) ($row['driver_number'] ?? 0),
                    ],
                    [
                        'meeting_key' => (int) ($row['meeting_key'] ?? $race->meeting_key),
                        'year' => $year,
                        'position_current' => isset($row['position_current']) ? (int) $row['position_current'] : null,
                        'position_start' => isset($row['position_start']) ? (int) $row['position_start'] : null,
                        'points_current' => isset($row['points_current']) ? (float) $row['points_current'] : null,
                        'points_start' => isset($row['points_start']) ? (float) $row['points_start'] : null,
                    ],
                );
            }

            $teams = $this->openf1->championshipTeams(['session_key' => $race->session_key]);
            $run->increment('calls_used');

            foreach ($teams as $row) {
                $teamName = (string) ($row['team_name'] ?? '');
                if ($teamName === '') {
                    continue;
                }

                F1ChampionshipTeam::query()->updateOrCreate(
                    [
                        'session_key' => (int) ($row['session_key'] ?? $race->session_key),
                        'team_name' => $teamName,
                    ],
                    [
                        'meeting_key' => (int) ($row['meeting_key'] ?? $race->meeting_key),
                        'year' => $year,
                        'position_current' => isset($row['position_current']) ? (int) $row['position_current'] : null,
                        'position_start' => isset($row['position_start']) ? (int) $row['position_start'] : null,
                        'points_current' => isset($row['points_current']) ? (float) $row['points_current'] : null,
                        'points_start' => isset($row['points_start']) ? (float) $row['points_start'] : null,
                    ],
                );
            }
        });
    }

    /**
     * Sync detail payloads for one historically available session (Tier B).
     */
    public function syncSessionDetail(int $sessionKey): F1SyncRun
    {
        return $this->run('session_detail:'.$sessionKey, function (F1SyncRun $run) use ($sessionKey): void {
            $session = F1Session::query()->where('session_key', $sessionKey)->firstOrFail();

            if (! $session->isHistoricallyAvailable()) {
                throw new \RuntimeException('Session is still inside the live / paid window.');
            }

            if ($session->detail_synced_at !== null) {
                return;
            }

            $lock = Cache::lock('f1-detail-'.$sessionKey, 600);
            if (! $lock->get()) {
                return;
            }

            try {
                if ($session->fresh()?->detail_synced_at !== null) {
                    return;
                }

                $this->pullDrivers($session, $run);
                $this->pullSessionResults($session, $run);
                $this->pullStartingGrid($session, $run);
                $this->pullLaps($session, $run);
                $this->pullPits($session, $run);
                $this->pullStints($session, $run);
                $this->pullPositions($session, $run);
                $this->pullRaceControl($session, $run);
                $this->pullWeather($session, $run);

                if (strcasecmp((string) $session->session_type, 'Race') === 0
                    || strcasecmp((string) $session->session_name, 'Race') === 0) {
                    $this->pullOvertakes($session, $run);
                }

                $session->forceFill(['detail_synced_at' => now()])->save();
            } finally {
                $lock->release();
            }
        }, rebuildHome: false);
    }

    /**
     * Sync the next historically available session that still needs detail.
     */
    public function syncPendingSessionDetails(int $limit = 1): ?F1SyncRun
    {
        $buffer = (int) config('services.openf1.sync.live_buffer_minutes', 35);
        $cutoff = now('UTC')->subMinutes($buffer);

        $session = F1Session::query()
            ->whereNull('detail_synced_at')
            ->whereNotNull('date_end')
            ->where('date_end', '<=', $cutoff)
            ->where('is_cancelled', false)
            ->orderBy('date_end')
            ->first();

        if ($session === null) {
            return null;
        }

        return $this->syncSessionDetail($session->session_key);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertMeeting(array $row): void
    {
        $key = (int) ($row['meeting_key'] ?? 0);
        if ($key <= 0) {
            return;
        }

        F1Meeting::query()->updateOrCreate(
            ['meeting_key' => $key],
            [
                'year' => (int) ($row['year'] ?? now('UTC')->year),
                'meeting_name' => (string) ($row['meeting_name'] ?? 'Meeting'),
                'meeting_official_name' => $row['meeting_official_name'] ?? null,
                'circuit_short_name' => $row['circuit_short_name'] ?? null,
                'circuit_key' => isset($row['circuit_key']) ? (int) $row['circuit_key'] : null,
                'circuit_image' => $row['circuit_image'] ?? null,
                'circuit_info_url' => $row['circuit_info_url'] ?? null,
                'circuit_type' => $row['circuit_type'] ?? null,
                'country_code' => $row['country_code'] ?? null,
                'country_name' => $row['country_name'] ?? null,
                'country_flag' => $row['country_flag'] ?? null,
                'country_key' => isset($row['country_key']) ? (int) $row['country_key'] : null,
                'location' => $row['location'] ?? null,
                'gmt_offset' => $row['gmt_offset'] ?? null,
                'date_start' => $this->parseDate($row['date_start'] ?? null),
                'date_end' => $this->parseDate($row['date_end'] ?? null),
                'is_cancelled' => (bool) ($row['is_cancelled'] ?? false),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function upsertSession(array $row): void
    {
        $key = (int) ($row['session_key'] ?? 0);
        if ($key <= 0) {
            return;
        }

        F1Session::query()->updateOrCreate(
            ['session_key' => $key],
            [
                'meeting_key' => (int) ($row['meeting_key'] ?? 0),
                'year' => (int) ($row['year'] ?? now('UTC')->year),
                'session_name' => (string) ($row['session_name'] ?? 'Session'),
                'session_type' => $row['session_type'] ?? null,
                'circuit_short_name' => $row['circuit_short_name'] ?? null,
                'country_name' => $row['country_name'] ?? null,
                'location' => $row['location'] ?? null,
                'gmt_offset' => $row['gmt_offset'] ?? null,
                'date_start' => $this->parseDate($row['date_start'] ?? null),
                'date_end' => $this->parseDate($row['date_end'] ?? null),
                'is_cancelled' => (bool) ($row['is_cancelled'] ?? false),
            ],
        );
    }

    private function pullDrivers(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->drivers(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        foreach ($rows as $row) {
            $number = (int) ($row['driver_number'] ?? 0);
            if ($number <= 0) {
                continue;
            }

            F1Driver::query()->updateOrCreate(
                [
                    'session_key' => $session->session_key,
                    'driver_number' => $number,
                ],
                [
                    'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                    'broadcast_name' => $row['broadcast_name'] ?? null,
                    'full_name' => $row['full_name'] ?? null,
                    'first_name' => $row['first_name'] ?? null,
                    'last_name' => $row['last_name'] ?? null,
                    'name_acronym' => $row['name_acronym'] ?? null,
                    'team_name' => $row['team_name'] ?? null,
                    'team_colour' => $row['team_colour'] ?? null,
                    'headshot_url' => $row['headshot_url'] ?? null,
                ],
            );
        }
    }

    private function pullSessionResults(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->sessionResult(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        foreach ($rows as $row) {
            $number = (int) ($row['driver_number'] ?? 0);
            if ($number <= 0) {
                continue;
            }

            F1SessionResult::query()->updateOrCreate(
                [
                    'session_key' => $session->session_key,
                    'driver_number' => $number,
                ],
                [
                    'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                    'position' => isset($row['position']) ? (int) $row['position'] : null,
                    'duration' => $this->wrapScalarOrArray($row['duration'] ?? null),
                    'gap_to_leader' => $this->wrapScalarOrArray($row['gap_to_leader'] ?? null),
                    'number_of_laps' => isset($row['number_of_laps']) ? (int) $row['number_of_laps'] : null,
                    'dnf' => (bool) ($row['dnf'] ?? false),
                    'dns' => (bool) ($row['dns'] ?? false),
                    'dsq' => (bool) ($row['dsq'] ?? false),
                ],
            );
        }
    }

    private function pullStartingGrid(F1Session $session, F1SyncRun $run): void
    {
        // Starting grid is keyed to the race session.
        if (strcasecmp((string) $session->session_type, 'Race') !== 0
            && strcasecmp((string) $session->session_name, 'Race') !== 0) {
            return;
        }

        $rows = $this->openf1->startingGrid(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        foreach ($rows as $row) {
            $number = (int) ($row['driver_number'] ?? 0);
            if ($number <= 0) {
                continue;
            }

            F1StartingGrid::query()->updateOrCreate(
                [
                    'session_key' => $session->session_key,
                    'driver_number' => $number,
                ],
                [
                    'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                    'position' => isset($row['position']) ? (int) $row['position'] : null,
                    'lap_duration' => isset($row['lap_duration']) ? (float) $row['lap_duration'] : null,
                ],
            );
        }
    }

    private function pullLaps(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->laps(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        $payload = [];
        foreach ($rows as $row) {
            $number = (int) ($row['driver_number'] ?? 0);
            $lap = (int) ($row['lap_number'] ?? 0);
            if ($number <= 0 || $lap <= 0) {
                continue;
            }

            $payload[] = [
                'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                'session_key' => $session->session_key,
                'driver_number' => $number,
                'lap_number' => $lap,
                'date_start' => $this->parseDate($row['date_start'] ?? null),
                'lap_duration' => isset($row['lap_duration']) ? (float) $row['lap_duration'] : null,
                'duration_sector_1' => isset($row['duration_sector_1']) ? (float) $row['duration_sector_1'] : null,
                'duration_sector_2' => isset($row['duration_sector_2']) ? (float) $row['duration_sector_2'] : null,
                'duration_sector_3' => isset($row['duration_sector_3']) ? (float) $row['duration_sector_3'] : null,
                'i1_speed' => isset($row['i1_speed']) ? (int) $row['i1_speed'] : null,
                'i2_speed' => isset($row['i2_speed']) ? (int) $row['i2_speed'] : null,
                'st_speed' => isset($row['st_speed']) ? (int) $row['st_speed'] : null,
                'is_pit_out_lap' => (bool) ($row['is_pit_out_lap'] ?? false),
                'segments_sector_1' => isset($row['segments_sector_1']) ? json_encode($row['segments_sector_1']) : null,
                'segments_sector_2' => isset($row['segments_sector_2']) ? json_encode($row['segments_sector_2']) : null,
                'segments_sector_3' => isset($row['segments_sector_3']) ? json_encode($row['segments_sector_3']) : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->upsertChunks('f1_laps', $payload, ['session_key', 'driver_number', 'lap_number']);
    }

    private function pullPits(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->pits(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        F1Pit::query()->where('session_key', $session->session_key)->delete();

        $payload = [];
        foreach ($rows as $row) {
            $number = (int) ($row['driver_number'] ?? 0);
            if ($number <= 0) {
                continue;
            }

            $payload[] = [
                'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                'session_key' => $session->session_key,
                'driver_number' => $number,
                'date' => $this->parseDate($row['date'] ?? null),
                'lap_number' => isset($row['lap_number']) ? (int) $row['lap_number'] : null,
                'lane_duration' => isset($row['lane_duration']) ? (float) $row['lane_duration'] : (isset($row['pit_duration']) ? (float) $row['pit_duration'] : null),
                'stop_duration' => isset($row['stop_duration']) ? (float) $row['stop_duration'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            F1Pit::query()->insert($chunk);
        }
    }

    private function pullStints(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->stints(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        foreach ($rows as $row) {
            $number = (int) ($row['driver_number'] ?? 0);
            $stint = (int) ($row['stint_number'] ?? 0);
            if ($number <= 0 || $stint <= 0) {
                continue;
            }

            F1Stint::query()->updateOrCreate(
                [
                    'session_key' => $session->session_key,
                    'driver_number' => $number,
                    'stint_number' => $stint,
                ],
                [
                    'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                    'compound' => $row['compound'] ?? null,
                    'lap_start' => isset($row['lap_start']) ? (int) $row['lap_start'] : null,
                    'lap_end' => isset($row['lap_end']) ? (int) $row['lap_end'] : null,
                    'tyre_age_at_start' => isset($row['tyre_age_at_start']) ? (int) $row['tyre_age_at_start'] : null,
                ],
            );
        }
    }

    private function pullPositions(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->positions(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        F1Position::query()->where('session_key', $session->session_key)->delete();

        $payload = [];
        foreach ($rows as $row) {
            $number = (int) ($row['driver_number'] ?? 0);
            if ($number <= 0 || ! isset($row['position'])) {
                continue;
            }

            $payload[] = [
                'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                'session_key' => $session->session_key,
                'driver_number' => $number,
                'date' => $this->parseDate($row['date'] ?? null),
                'position' => (int) $row['position'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            F1Position::query()->insert($chunk);
        }
    }

    private function pullRaceControl(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->raceControl(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        F1RaceControl::query()->where('session_key', $session->session_key)->delete();

        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                'session_key' => $session->session_key,
                'date' => $this->parseDate($row['date'] ?? null),
                'category' => $row['category'] ?? null,
                'flag' => $row['flag'] ?? null,
                'scope' => $row['scope'] ?? null,
                'driver_number' => isset($row['driver_number']) ? (int) $row['driver_number'] : null,
                'lap_number' => isset($row['lap_number']) ? (int) $row['lap_number'] : null,
                'sector' => isset($row['sector']) ? (int) $row['sector'] : null,
                'qualifying_phase' => isset($row['qualifying_phase']) ? (int) $row['qualifying_phase'] : null,
                'message' => $row['message'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            F1RaceControl::query()->insert($chunk);
        }
    }

    private function pullWeather(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->weather(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        F1Weather::query()->where('session_key', $session->session_key)->delete();

        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                'session_key' => $session->session_key,
                'date' => $this->parseDate($row['date'] ?? null),
                'air_temperature' => isset($row['air_temperature']) ? (float) $row['air_temperature'] : null,
                'track_temperature' => isset($row['track_temperature']) ? (float) $row['track_temperature'] : null,
                'humidity' => isset($row['humidity']) ? (int) $row['humidity'] : null,
                'pressure' => isset($row['pressure']) ? (float) $row['pressure'] : null,
                'rainfall' => isset($row['rainfall']) ? (bool) $row['rainfall'] : null,
                'wind_direction' => isset($row['wind_direction']) ? (int) $row['wind_direction'] : null,
                'wind_speed' => isset($row['wind_speed']) ? (float) $row['wind_speed'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            F1Weather::query()->insert($chunk);
        }
    }

    private function pullOvertakes(F1Session $session, F1SyncRun $run): void
    {
        $rows = $this->openf1->overtakes(['session_key' => $session->session_key]);
        $run->increment('calls_used');

        F1Overtake::query()->where('session_key', $session->session_key)->delete();

        $payload = [];
        foreach ($rows as $row) {
            $payload[] = [
                'meeting_key' => (int) ($row['meeting_key'] ?? $session->meeting_key),
                'session_key' => $session->session_key,
                'date' => $this->parseDate($row['date'] ?? null),
                'overtaking_driver_number' => (int) ($row['overtaking_driver_number'] ?? 0),
                'overtaken_driver_number' => (int) ($row['overtaken_driver_number'] ?? 0),
                'position' => isset($row['position']) ? (int) $row['position'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            F1Overtake::query()->insert($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @param  list<string>  $uniqueBy
     */
    private function upsertChunks(string $table, array $payload, array $uniqueBy): void
    {
        if ($payload === []) {
            return;
        }

        $update = array_values(array_diff(array_keys($payload[0]), [...$uniqueBy, 'created_at']));

        foreach (array_chunk($payload, 300) as $chunk) {
            DB::table($table)->upsert($chunk, $uniqueBy, $update);
        }
    }

    /**
     * @return array<int, mixed>|null
     */
    private function wrapScalarOrArray(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  callable(F1SyncRun): void  $callback
     */
    private function run(string $job, callable $callback, bool $rebuildHome = true): F1SyncRun
    {
        $run = F1SyncRun::query()->create([
            'provider' => OpenF1Integration::PROVIDER,
            'job' => $job,
            'status' => F1SyncRun::STATUS_RUNNING,
            'calls_used' => 0,
            'started_at' => now(),
        ]);

        try {
            $callback($run);
            $run->forceFill([
                'status' => F1SyncRun::STATUS_OK,
                'finished_at' => now(),
            ])->save();

            if ($rebuildHome) {
                $this->home->rebuild();
            }
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => F1SyncRun::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        return $run->fresh();
    }
}
