<?php

namespace App\Jobs\Sports;

use App\Jobs\Sports\Concerns\RunsLightSportsSync;
use App\Services\Sports\SportsSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncFootballStandingsJob implements ShouldQueue
{
    use Queueable;
    use RunsLightSportsSync;

    public int $tries = 2;

    public int $timeout = 45;

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
            (new WithoutOverlapping('sports-sync-standings'))
                ->expireAfter($this->timeout + 10)
                ->releaseAfter(30),
        ];
    }

    public function handle(SportsSyncService $sync): void
    {
        $ok = $this->runOrReleaseOnRateLimit(
            fn () => $sync->syncStandings(),
        );

        if ($ok) {
            RebuildSportsHomeSnapshotJob::dispatch();
        }
    }
}
