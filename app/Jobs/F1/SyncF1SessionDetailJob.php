<?php

namespace App\Jobs\F1;

use App\Jobs\F1\Concerns\RunsLightF1Sync;
use App\Services\F1\F1HomeService;
use App\Services\F1\F1SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncF1SessionDetailJob implements ShouldQueue
{
    use Queueable;
    use RunsLightF1Sync;

    public int $tries = 5;

    public int $timeout = 300;

    public function __construct(
        public ?int $sessionKey = null,
    ) {
        $this->onQueue((string) config('services.openf1.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $key = $this->sessionKey ?? 'pending';

        return [
            (new WithoutOverlapping('f1-sync-detail-'.$key))
                ->dontRelease()
                ->expireAfter(1800),
        ];
    }

    public function handle(F1SyncService $sync, F1HomeService $home): void
    {
        $ok = $this->runOrReleaseOnRateLimit(function () use ($sync): void {
            if ($this->sessionKey !== null) {
                $sync->syncSessionDetail($this->sessionKey);
            } else {
                $sync->syncPendingSessionDetails(1);
            }
        });

        if ($ok) {
            $home->rebuild();
        }
    }

    public function backoff(): array
    {
        return [20, 45, 90, 120];
    }

    public function failed(?\Throwable $e): void
    {
        Log::warning('F1 session detail sync failed permanently', [
            'session_key' => $this->sessionKey,
            'error' => $e?->getMessage(),
        ]);
    }
}
