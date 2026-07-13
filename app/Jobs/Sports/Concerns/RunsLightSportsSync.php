<?php

namespace App\Jobs\Sports\Concerns;

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Support\Facades\Cache;

trait RunsLightSportsSync
{
    /**
     * Fail fast on upstream rate limits — never sleep in the worker.
     * Releases the job for a short cooldown so other queues stay responsive.
     */
    protected function runOrReleaseOnRateLimit(callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (IntegrationException $e) {
            if ($e->statusCode !== 429) {
                throw $e;
            }

            $seconds = max(15, (int) config('services.sportsdb.sync.rate_limit_release_seconds', 45));
            $this->release($seconds);

            return false;
        }
    }

    /**
     * Prevent stacked fixture/day waves from overlapping on a small worker.
     */
    protected function claimSyncWave(string $key, int $ttlSeconds = 2700): bool
    {
        return Cache::add($key, 1, $ttlSeconds);
    }

    protected function releaseSyncWave(string $key): void
    {
        Cache::forget($key);
    }
}
