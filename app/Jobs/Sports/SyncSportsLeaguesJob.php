<?php

namespace App\Jobs\Sports;

use App\Jobs\Sports\Concerns\RunsLightSportsSync;
use App\Services\Sports\SportsSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncSportsLeaguesJob implements ShouldQueue
{
    use Queueable;
    use RunsLightSportsSync;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue((string) config('services.sportsdb.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sports-sync-leagues'))
                ->expireAfter($this->timeout + 10)
                ->releaseAfter(30),
        ];
    }

    public function handle(SportsSyncService $sync): void
    {
        $this->runOrReleaseOnRateLimit(
            fn () => $sync->syncLeagues(),
        );
    }
}
