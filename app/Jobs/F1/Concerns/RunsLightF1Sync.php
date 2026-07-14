<?php

namespace App\Jobs\F1\Concerns;

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Support\Facades\Cache;

trait RunsLightF1Sync
{
    protected function runOrReleaseOnRateLimit(callable $callback): bool
    {
        try {
            $callback();

            return true;
        } catch (IntegrationException $e) {
            if ($e->statusCode !== 429) {
                throw $e;
            }

            $seconds = max(15, (int) config('services.openf1.sync.rate_limit_release_seconds', 45));
            $this->release($seconds);

            return false;
        }
    }

    protected function claimSyncWave(string $key, int $ttlSeconds = 2700): bool
    {
        return Cache::add($key, 1, $ttlSeconds);
    }

    protected function releaseSyncWave(string $key): void
    {
        Cache::forget($key);
    }
}
