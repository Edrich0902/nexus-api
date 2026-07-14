<?php

namespace App\Jobs\F1;

use App\Jobs\F1\Concerns\RunsLightF1Sync;
use App\Models\F1\F1Session;
use App\Services\F1\F1ReplayService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

/**
 * Syncs location for a single driver, then chains the next driver.
 * Keeps each queue run bounded so workers are not killed mid-race dump.
 */
class SyncF1ReplayJob implements ShouldQueue
{
    use Queueable;
    use RunsLightF1Sync;

    public int $tries = 5;

    /** One race-length driver needs many windowed OpenF1 calls + rate-limit pauses. */
    public int $timeout = 600;

    /**
     * @param  list<int>|null  $remainingDrivers  null = bootstrap driver list on first run
     */
    public function __construct(
        public int $sessionKey,
        public ?array $remainingDrivers = null,
    ) {
        $this->onQueue((string) config('services.openf1.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('f1-replay-'.$this->sessionKey))
                ->releaseAfter(45)
                ->expireAfter(720),
        ];
    }

    public function handle(F1ReplayService $replay): void
    {
        $session = F1Session::query()->where('session_key', $this->sessionKey)->first();
        if ($session === null) {
            return;
        }

        // Fresh ensureLocation / retry only — skip if already fully done.
        if ($session->replay_status === F1Session::REPLAY_READY
            && $session->replay_error !== 'partial'
            && ($this->remainingDrivers === null || $this->remainingDrivers === [])) {
            return;
        }

        $this->runOrReleaseOnRateLimit(function () use ($replay, $session): void {
            try {
                $remaining = $this->remainingDrivers;
                if ($remaining === null) {
                    $remaining = $replay->driverNumbersForReplay($session);
                    $replay->clearLocation($session->session_key);
                    $session->forceFill([
                        'replay_status' => F1Session::REPLAY_PENDING,
                        'replay_error' => null,
                        'replay_synced_at' => null,
                    ])->save();
                }

                if ($remaining === []) {
                    $replay->markReplayReady($session, partial: false);

                    return;
                }

                $driverNumber = (int) array_shift($remaining);
                // Heartbeat so stale-pending recovery does not fire mid-driver.
                $session->touch();

                $replay->syncDriverLocation($session, $driverNumber);

                // First driver unlocks the map; remaining cars fill in without flipping to pending.
                $stillMore = $remaining !== [];
                $replay->markReplayReady($session, partial: $stillMore);

                if ($stillMore) {
                    self::dispatch($this->sessionKey, $remaining)->delay(now()->addSeconds(2));
                }
            } catch (\Throwable $e) {
                $session->forceFill([
                    'replay_status' => F1Session::REPLAY_FAILED,
                    'replay_error' => $e->getMessage(),
                ])->save();

                Log::warning('F1 replay sync failed', [
                    'session_key' => $this->sessionKey,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    public function backoff(): array
    {
        return [15, 45, 90, 120];
    }
}
