<?php

namespace App\Jobs\F1;

use App\Jobs\F1\Concerns\RunsLightF1Sync;
use App\Services\F1\F1HomeService;
use App\Services\F1\F1SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncF1SeasonJob implements ShouldQueue
{
    use Queueable;
    use RunsLightF1Sync;

    public int $tries = 5;

    public function __construct(
        public ?int $year = null,
    ) {
        $this->onQueue((string) config('services.openf1.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('f1-sync-season-'.($this->year ?? 'current')))
                ->releaseAfter(60)
                ->expireAfter(1800),
        ];
    }

    public function handle(F1SyncService $sync, F1HomeService $home): void
    {
        $key = 'f1-sync-wave-season';
        if (! $this->claimSyncWave($key)) {
            $this->release(30);

            return;
        }

        try {
            $ok = $this->runOrReleaseOnRateLimit(function () use ($sync): void {
                $sync->syncSeason($this->year);
            });

            if ($ok) {
                $home->rebuild();
            }
        } finally {
            $this->releaseSyncWave($key);
        }
    }
}
