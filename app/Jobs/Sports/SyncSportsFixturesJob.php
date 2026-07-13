<?php

namespace App\Jobs\Sports;

use App\Jobs\Sports\Concerns\RunsLightSportsSync;
use App\Services\Sports\SportsSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncSportsFixturesJob implements ShouldQueue
{
    use Queueable;
    use RunsLightSportsSync;

    public int $tries = 3;

    public int $timeout = 30;

    private const WAVE_LOCK = 'sports:sync:fixtures-wave';

    public function __construct(
        public readonly ?int $chunkSize = null,
        public readonly int $offset = 0,
        public readonly bool $chainRemaining = true,
    ) {
        $this->onQueue((string) config('services.sportsdb.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('sports-sync-fixtures-'.$this->offset))
                ->expireAfter($this->timeout + 10)
                ->releaseAfter(20),
        ];
    }

    public function handle(SportsSyncService $sync): void
    {
        if ($this->offset === 0 && $this->chainRemaining && ! $this->claimSyncWave(self::WAVE_LOCK)) {
            return;
        }

        $chunkSize = max(1, $this->chunkSize ?? (int) config(
            'services.sportsdb.sync.fixture_chunk_size',
            1,
        ));
        $total = $sync->whitelistedLeagues()->count();
        $nextOffset = $this->offset + $chunkSize;
        $isLast = $nextOffset >= $total;

        $ok = $this->runOrReleaseOnRateLimit(
            fn () => $sync->syncFixtures($chunkSize, $this->offset, rebuildHome: false),
        );

        if (! $ok) {
            return;
        }

        if ($this->chainRemaining && ! $isLast && $total > 0) {
            $delay = max(0, (int) config('services.sportsdb.sync.fixture_chain_delay_seconds', 1));
            $pending = self::dispatch($chunkSize, $nextOffset, true);
            if ($delay > 0) {
                $pending->delay(now()->addSeconds($delay));
            }

            return;
        }

        $this->releaseSyncWave(self::WAVE_LOCK);
        RebuildSportsHomeSnapshotJob::dispatch();
    }
}
