<?php

namespace App\Jobs\Sports;

use App\Jobs\Sports\Concerns\RunsLightSportsSync;
use App\Services\Sports\SportsSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class SyncSportsDayJob implements ShouldQueue
{
    use Queueable;
    use RunsLightSportsSync;

    public int $tries = 3;

    public int $timeout = 30;

    private const WAVE_LOCK = 'sports:sync:day-wave';

    public function __construct(
        public readonly ?string $sportSlug = null,
        public readonly bool $chainRemaining = true,
    ) {
        $this->onQueue((string) config('services.sportsdb.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        $key = 'sports-sync-day-'.($this->sportSlug ?? 'start');

        return [
            (new WithoutOverlapping($key))
                ->expireAfter($this->timeout + 10)
                ->releaseAfter(20),
        ];
    }

    public function handle(SportsSyncService $sync): void
    {
        /** @var array<string, string> $sports */
        $sports = config('services.sportsdb.sport_api_names', []);
        $slugs = array_keys($sports);

        if ($slugs === []) {
            return;
        }

        $slug = $this->sportSlug ?? $slugs[0];
        if (! isset($sports[$slug])) {
            return;
        }

        if ($this->sportSlug === null && $this->chainRemaining && ! $this->claimSyncWave(self::WAVE_LOCK)) {
            return;
        }

        $ok = $this->runOrReleaseOnRateLimit(
            fn () => $sync->syncDay(null, $slug),
        );

        if (! $ok) {
            return;
        }

        if (! $this->chainRemaining) {
            $this->releaseSyncWave(self::WAVE_LOCK);
            RebuildSportsHomeSnapshotJob::dispatch();

            return;
        }

        $index = array_search($slug, $slugs, true);
        $next = is_int($index) ? ($slugs[$index + 1] ?? null) : null;

        if ($next !== null) {
            $delay = max(0, (int) config('services.sportsdb.sync.fixture_chain_delay_seconds', 1));
            $pending = self::dispatch($next, true);
            if ($delay > 0) {
                $pending->delay(now()->addSeconds($delay));
            }

            return;
        }

        $this->releaseSyncWave(self::WAVE_LOCK);
        RebuildSportsHomeSnapshotJob::dispatch();
    }
}
