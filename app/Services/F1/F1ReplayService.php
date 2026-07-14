<?php

namespace App\Services\F1;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\OpenF1\OpenF1Integration;
use App\Jobs\F1\SyncF1ReplayJob;
use App\Models\F1\F1CarDataSample;
use App\Models\F1\F1Driver;
use App\Models\F1\F1LocationSample;
use App\Models\F1\F1Session;
use App\Models\F1\F1SessionResult;
use App\Models\F1\F1SyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class F1ReplayService
{
    public function __construct(
        private readonly OpenF1Integration $openf1,
        private readonly F1Downsample $downsample,
    ) {}

    /**
     * @return array{status: string, message?: string, session_key: int, error?: ?string, available?: bool, synced_at?: ?string}
     */
    public function status(int $sessionKey): array
    {
        $session = F1Session::query()->where('session_key', $sessionKey)->firstOrFail();
        $this->recoverStalePending($session);
        $session->refresh();

        return [
            'session_key' => $sessionKey,
            'status' => $session->replay_status ?? ($session->replay_synced_at ? F1Session::REPLAY_READY : 'idle'),
            'error' => $session->replay_error === 'partial' ? null : $session->replay_error,
            'partial' => $session->replay_error === 'partial',
            'available' => $session->isHistoricallyAvailable(),
            'synced_at' => $session->replay_synced_at?->toIso8601String(),
            'location_count' => F1LocationSample::query()->where('session_key', $sessionKey)->count(),
        ];
    }

    /**
     * @return array{status: string, session_key: int, location_count?: int, message?: string}
     */
    public function ensureLocation(int $sessionKey): array
    {
        $session = F1Session::query()->where('session_key', $sessionKey)->firstOrFail();
        $this->recoverStalePending($session);
        $session->refresh();

        if (! $session->isHistoricallyAvailable()) {
            return [
                'session_key' => $sessionKey,
                'status' => 'unavailable',
                'message' => 'Replay is only available after the free historical window opens (≈35 minutes after session end).',
            ];
        }

        $count = F1LocationSample::query()->where('session_key', $sessionKey)->count();

        // Ready (including partial) — usable map while more drivers may still chain.
        if ($session->replay_status === F1Session::REPLAY_READY && $count > 0) {
            return [
                'session_key' => $sessionKey,
                'status' => F1Session::REPLAY_READY,
                'partial' => $session->replay_error === 'partial',
                'location_count' => $count,
            ];
        }

        if ($session->replay_status === F1Session::REPLAY_PENDING) {
            return [
                'session_key' => $sessionKey,
                'status' => F1Session::REPLAY_PENDING,
                'message' => 'Replay data is being prepared (one driver at a time to stay under OpenF1 limits).',
                'location_count' => $count,
            ];
        }

        $session->forceFill([
            'replay_status' => F1Session::REPLAY_PENDING,
            'replay_error' => null,
            'replay_synced_at' => null,
        ])->save();

        SyncF1ReplayJob::dispatch($sessionKey);

        return [
            'session_key' => $sessionKey,
            'status' => F1Session::REPLAY_PENDING,
            'message' => 'Replay data is being prepared.',
        ];
    }

    /**
     * Prefer classified results order, fall back to session drivers — capped.
     *
     * @return list<int>
     */
    public function driverNumbersForReplay(F1Session $session): array
    {
        $max = max(1, (int) config('services.openf1.sync.replay_max_drivers', 6));

        $fromResults = F1SessionResult::query()
            ->where('session_key', $session->session_key)
            ->whereNotNull('position')
            ->orderBy('position')
            ->pluck('driver_number')
            ->map(fn ($n) => (int) $n)
            ->unique()
            ->values()
            ->all();

        if ($fromResults !== []) {
            return array_slice($fromResults, 0, $max);
        }

        return F1Driver::query()
            ->where('session_key', $session->session_key)
            ->orderBy('driver_number')
            ->pluck('driver_number')
            ->map(fn ($n) => (int) $n)
            ->unique()
            ->take($max)
            ->values()
            ->all();
    }

    public function clearLocation(int $sessionKey): void
    {
        F1LocationSample::query()->where('session_key', $sessionKey)->delete();
    }

    public function markReplayReady(F1Session $session, bool $partial = false): void
    {
        $session->forceFill([
            'replay_synced_at' => now(),
            'replay_status' => F1Session::REPLAY_READY,
            'replay_error' => $partial ? 'partial' : null,
        ])->save();
    }

    /**
     * Pull location for one driver across the session using small time windows.
     */
    public function syncDriverLocation(F1Session $session, int $driverNumber): F1SyncRun
    {
        $run = F1SyncRun::query()->create([
            'provider' => OpenF1Integration::PROVIDER,
            'job' => 'replay_location:'.$session->session_key.':'.$driverNumber,
            'status' => F1SyncRun::STATUS_RUNNING,
            'calls_used' => 0,
            'started_at' => now(),
        ]);

        try {
            if ($session->date_start === null || $session->date_end === null) {
                throw new \RuntimeException('Session is missing date_start/date_end; cannot build replay windows.');
            }

            $hz = (float) config('services.openf1.sync.location_hz', 0.25);
            $chunkSeconds = max(30, (int) config('services.openf1.sync.telemetry_chunk_seconds', 180));
            $callsUsed = 0;

            // Idempotent on job retry / rate-limit release — avoid duplicate inserts.
            F1LocationSample::query()
                ->where('session_key', $session->session_key)
                ->where('driver_number', $driverNumber)
                ->delete();

            $cursor = $session->date_start->copy()->utc();
            $end = $session->date_end->copy()->utc()->addSeconds(30);

            while ($cursor->lt($end)) {
                $windowEnd = $cursor->copy()->addSeconds($chunkSeconds);
                if ($windowEnd->gt($end)) {
                    $windowEnd = $end->copy();
                }

                $rows = $this->fetchTelemetryWindow(
                    'location',
                    [
                        'session_key' => $session->session_key,
                        'driver_number' => $driverNumber,
                    ],
                    $cursor,
                    $windowEnd,
                    $callsUsed,
                );

                $kept = $this->downsample->byHz($rows, $hz);
                $this->insertLocationRows($session->session_key, $kept);

                $cursor = $windowEnd;
            }

            $run->forceFill([
                'calls_used' => $callsUsed,
                'status' => F1SyncRun::STATUS_OK,
                'finished_at' => now(),
            ])->save();
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

    /**
     * @deprecated Use chained SyncF1ReplayJob + syncDriverLocation instead.
     */
    public function syncLocation(F1Session $session): F1SyncRun
    {
        $drivers = $this->driverNumbersForReplay($session);
        $this->clearLocation($session->session_key);

        $last = null;
        foreach ($drivers as $driverNumber) {
            $last = $this->syncDriverLocation($session, $driverNumber);
        }

        $this->markReplayReady($session);

        return $last ?? F1SyncRun::query()->create([
            'provider' => OpenF1Integration::PROVIDER,
            'job' => 'replay_location:'.$session->session_key,
            'status' => F1SyncRun::STATUS_OK,
            'calls_used' => 0,
            'started_at' => now(),
            'finished_at' => now(),
        ]);
    }

    /**
     * @return array{status: string, driver_number: int, sample_count: int, samples?: list<array<string, mixed>>, message?: string}
     */
    public function ensureCarData(int $sessionKey, int $driverNumber): array
    {
        $session = F1Session::query()->where('session_key', $sessionKey)->firstOrFail();

        if (! $session->isHistoricallyAvailable()) {
            return [
                'status' => 'unavailable',
                'driver_number' => $driverNumber,
                'sample_count' => 0,
                'message' => 'Car data is only available after the historical window opens.',
            ];
        }

        $existing = F1CarDataSample::query()
            ->where('session_key', $sessionKey)
            ->where('driver_number', $driverNumber)
            ->count();

        if ($existing === 0) {
            $this->syncCarData($session, $driverNumber);
        }

        $samples = F1CarDataSample::query()
            ->where('session_key', $sessionKey)
            ->where('driver_number', $driverNumber)
            ->orderBy('date')
            ->limit(2500)
            ->get()
            ->map(fn (F1CarDataSample $s) => [
                'date' => $s->date?->toIso8601String(),
                'speed' => $s->speed,
                'rpm' => $s->rpm,
                'n_gear' => $s->n_gear,
                'throttle' => $s->throttle,
                'brake' => $s->brake,
                'drs' => $s->drs,
            ])
            ->values()
            ->all();

        return [
            'status' => 'ready',
            'driver_number' => $driverNumber,
            'sample_count' => count($samples),
            'samples' => $samples,
        ];
    }

    public function syncCarData(F1Session $session, int $driverNumber): void
    {
        $lock = Cache::lock('f1-car-data-'.$session->session_key.'-'.$driverNumber, 300);
        if (! $lock->get()) {
            return;
        }

        try {
            if ($session->date_start === null || $session->date_end === null) {
                return;
            }

            $hz = (float) config('services.openf1.sync.car_data_hz', 1.0);
            $chunkSeconds = max(30, (int) config('services.openf1.sync.telemetry_chunk_seconds', 180));

            F1CarDataSample::query()
                ->where('session_key', $session->session_key)
                ->where('driver_number', $driverNumber)
                ->delete();

            $cursor = $session->date_start->copy()->utc();
            $end = $session->date_end->copy()->utc()->addSeconds(30);
            $callsUsed = 0;

            while ($cursor->lt($end)) {
                $windowEnd = $cursor->copy()->addSeconds($chunkSeconds);
                if ($windowEnd->gt($end)) {
                    $windowEnd = $end->copy();
                }

                $rows = $this->fetchTelemetryWindow(
                    'car_data',
                    [
                        'session_key' => $session->session_key,
                        'driver_number' => $driverNumber,
                    ],
                    $cursor,
                    $windowEnd,
                    $callsUsed,
                );

                $kept = $this->downsample->byHz($rows, $hz);
                $this->insertCarDataRows($session->session_key, $driverNumber, $kept);

                $cursor = $windowEnd;
            }
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, scalar|null>  $baseQuery
     * @return list<array<string, mixed>>
     */
    private function fetchTelemetryWindow(
        string $endpoint,
        array $baseQuery,
        Carbon $start,
        Carbon $end,
        int &$callsUsed,
        int $bisectDepth = 0,
        int $rateRetries = 0,
    ): array {
        if ($start->gte($end)) {
            return [];
        }

        // Build URL with raw operators so OpenF1 receives date>/date< filters reliably.
        $query = array_merge($baseQuery, [
            'date>' => $start->utc()->format('Y-m-d\TH:i:s.000\Z'),
            'date<' => $end->utc()->format('Y-m-d\TH:i:s.000\Z'),
        ]);

        try {
            $rows = $endpoint === 'car_data'
                ? $this->openf1->carData($query)
                : $this->openf1->location($query);
            $callsUsed++;

            return $rows;
        } catch (IntegrationException $e) {
            $callsUsed++;

            if ($e->statusCode === 404) {
                return [];
            }

            // Wait out the OpenF1 minute window instead of releasing the whole job mid-driver.
            if ($e->statusCode === 429 && $rateRetries < 4) {
                $wait = max(15, (int) config('services.openf1.sync.rate_limit_release_seconds', 45));
                sleep(min(90, $wait));

                return $this->fetchTelemetryWindow(
                    $endpoint,
                    $baseQuery,
                    $start,
                    $end,
                    $callsUsed,
                    $bisectDepth,
                    $rateRetries + 1,
                );
            }

            if ($e->statusCode === 422 && $bisectDepth < 8 && $start->diffInSeconds($end) > 10) {
                $mid = $start->copy()->addSeconds((int) max(1, floor($start->diffInSeconds($end) / 2)));

                return array_merge(
                    $this->fetchTelemetryWindow($endpoint, $baseQuery, $start, $mid, $callsUsed, $bisectDepth + 1, $rateRetries),
                    $this->fetchTelemetryWindow($endpoint, $baseQuery, $mid, $end, $callsUsed, $bisectDepth + 1, $rateRetries),
                );
            }

            throw $e;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertLocationRows(int $sessionKey, array $rows): void
    {
        $payload = [];
        foreach ($rows as $row) {
            $date = $this->parseDate($row['date'] ?? null);
            if ($date === null || ! isset($row['driver_number'], $row['x'], $row['y'])) {
                continue;
            }

            $payload[] = [
                'session_key' => $sessionKey,
                'driver_number' => (int) $row['driver_number'],
                'date' => $date,
                'x' => (int) $row['x'],
                'y' => (int) $row['y'],
                'z' => isset($row['z']) ? (int) $row['z'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            F1LocationSample::query()->insert($chunk);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertCarDataRows(int $sessionKey, int $driverNumber, array $rows): void
    {
        $payload = [];
        foreach ($rows as $row) {
            $date = $this->parseDate($row['date'] ?? null);
            if ($date === null) {
                continue;
            }

            $payload[] = [
                'session_key' => $sessionKey,
                'driver_number' => $driverNumber,
                'date' => $date,
                'speed' => isset($row['speed']) ? (int) $row['speed'] : null,
                'rpm' => isset($row['rpm']) ? (int) $row['rpm'] : null,
                'n_gear' => isset($row['n_gear']) ? (int) $row['n_gear'] : null,
                'throttle' => isset($row['throttle']) ? (int) $row['throttle'] : null,
                'brake' => isset($row['brake']) ? (int) $row['brake'] : null,
                'drs' => isset($row['drs']) ? (int) $row['drs'] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($payload, 500) as $chunk) {
            F1CarDataSample::query()->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(int $sessionKey, ?int $driverNumber = null): array
    {
        $ensure = $this->ensureLocation($sessionKey);
        $status = $ensure['status'] ?? 'pending';

        $drivers = F1Driver::query()
            ->where('session_key', $sessionKey)
            ->orderBy('driver_number')
            ->get()
            ->map(fn (F1Driver $d) => [
                'driver_number' => $d->driver_number,
                'name_acronym' => $d->name_acronym,
                'full_name' => $d->full_name,
                'team_name' => $d->team_name,
                'team_colour' => $d->team_colour,
            ])
            ->values()
            ->all();

        if ($status !== F1Session::REPLAY_READY) {
            return [
                'session_key' => $sessionKey,
                'status' => $status,
                'message' => $ensure['message'] ?? null,
                'partial' => false,
                'drivers' => $drivers,
                'location' => [],
                'bounds' => null,
                'car_data' => null,
            ];
        }

        // Cap browser payload — enough for scrubbing without multi-MB JSON.
        $locations = F1LocationSample::query()
            ->where('session_key', $sessionKey)
            ->orderBy('date')
            ->limit(12_000)
            ->get(['driver_number', 'date', 'x', 'y', 'z']);

        $bounds = null;
        if ($locations->isNotEmpty()) {
            $bounds = [
                'min_x' => $locations->min('x'),
                'max_x' => $locations->max('x'),
                'min_y' => $locations->min('y'),
                'max_y' => $locations->max('y'),
            ];
        }

        $carData = null;
        if ($driverNumber !== null) {
            $carData = $this->ensureCarData($sessionKey, $driverNumber);
        }

        $session = F1Session::query()->where('session_key', $sessionKey)->first();

        return [
            'session_key' => $sessionKey,
            'status' => F1Session::REPLAY_READY,
            'partial' => $session?->replay_error === 'partial',
            'drivers' => $drivers,
            'location' => $locations->map(fn (F1LocationSample $s) => [
                'driver_number' => $s->driver_number,
                'date' => $s->date?->toIso8601String(),
                'x' => $s->x,
                'y' => $s->y,
                'z' => $s->z,
            ])->values()->all(),
            'bounds' => $bounds,
            'car_data' => $carData,
            'hz' => (float) config('services.openf1.sync.location_hz', 0.25),
            'message' => $locations->isEmpty()
                ? 'Replay marked ready but no location samples were stored.'
                : null,
        ];
    }

    /**
     * Force a fresh replay sync chain.
     *
     * @return array{status: string, session_key: int, message?: string, location_count?: int}
     */
    public function retry(int $sessionKey): array
    {
        $session = F1Session::query()->where('session_key', $sessionKey)->firstOrFail();

        $session->forceFill([
            'replay_status' => null,
            'replay_error' => null,
            'replay_synced_at' => null,
        ])->save();

        $this->clearLocation($sessionKey);

        return $this->ensureLocation($sessionKey);
    }

    private function recoverStalePending(F1Session $session): void
    {
        if ($session->replay_status !== F1Session::REPLAY_PENDING) {
            return;
        }

        $staleAfter = max(300, (int) config('services.openf1.sync.replay_stale_pending_seconds', 900));
        $updatedAt = $session->updated_at;
        if ($updatedAt !== null && $updatedAt->gt(now()->subSeconds($staleAfter))) {
            return;
        }

        // Lock still held → a worker is actively syncing this session.
        $lock = Cache::lock('laravel-queue-overlap:f1-replay-'.$session->session_key, 1);
        if (! $lock->get()) {
            return;
        }
        $lock->release();

        $hasSamples = F1LocationSample::query()
            ->where('session_key', $session->session_key)
            ->exists();

        if ($hasSamples) {
            $session->forceFill([
                'replay_status' => F1Session::REPLAY_READY,
                'replay_synced_at' => $session->replay_synced_at ?? now(),
                'replay_error' => 'partial',
            ])->save();

            return;
        }

        $session->forceFill([
            'replay_status' => F1Session::REPLAY_FAILED,
            'replay_error' => 'Replay sync stalled (queue worker stopped). Please retry.',
        ])->save();
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
}
