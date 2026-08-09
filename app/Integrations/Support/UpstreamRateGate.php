<?php

namespace App\Integrations\Support;

use App\Integrations\Exceptions\IntegrationException;
use Illuminate\Support\Facades\RateLimiter;

class UpstreamRateGate
{
    private const DEFAULT_MAX_WAIT_SECONDS = 90;

    private const SLEEP_MICROSECONDS = 250_000;

    /**
     * Acquire a slot for an upstream provider call, waiting if the window is full.
     *
     * @throws IntegrationException
     */
    public function acquire(string $provider, ?int $maxWaitSeconds = null): void
    {
        $config = $this->configFor($provider);
        $key = $this->key($provider);
        $maxWait = $maxWaitSeconds ?? self::DEFAULT_MAX_WAIT_SECONDS;
        $deadline = microtime(true) + $maxWait;

        while (true) {
            $allowed = RateLimiter::attempt(
                $key,
                $config['max_attempts'],
                static fn () => true,
                $config['decay_seconds'],
            );

            if ($allowed) {
                return;
            }

            if (microtime(true) >= $deadline) {
                throw new IntegrationException(
                    "[{$provider}] Upstream rate limit gate timed out after {$maxWait}s.",
                    429,
                );
            }

            usleep(self::SLEEP_MICROSECONDS);
        }
    }

    /**
     * Mark the current window as exhausted (e.g. after an upstream 429).
     */
    public function exhaust(string $provider): void
    {
        $config = $this->configFor($provider);
        $key = $this->key($provider);

        RateLimiter::clear($key);

        for ($i = 0; $i < $config['max_attempts']; $i++) {
            RateLimiter::hit($key, $config['decay_seconds']);
        }
    }

    /**
     * Try once without waiting. Returns false when the RPM window is full.
     */
    public function tryAcquire(string $provider, ?int $maxAttempts = null, ?int $decaySeconds = null): bool
    {
        $config = $this->configFor($provider, $maxAttempts, $decaySeconds);
        $key = $this->key($provider);

        return (bool) RateLimiter::attempt(
            $key,
            $config['max_attempts'],
            static fn () => true,
            $config['decay_seconds'],
        );
    }

    public function remaining(string $provider, ?int $maxAttempts = null, ?int $decaySeconds = null): int
    {
        $config = $this->configFor($provider, $maxAttempts, $decaySeconds);

        return max(0, RateLimiter::remaining($this->key($provider), $config['max_attempts']));
    }

    /**
     * @return array{max_attempts: int, decay_seconds: int}
     */
    private function configFor(string $provider, ?int $maxAttempts = null, ?int $decaySeconds = null): array
    {
        /** @var array{max_attempts?: int, decay_seconds?: int} $config */
        $config = config("services.rate_limits.{$provider}", []);

        return [
            'max_attempts' => max(1, $maxAttempts ?? (int) ($config['max_attempts'] ?? 30)),
            'decay_seconds' => max(1, $decaySeconds ?? (int) ($config['decay_seconds'] ?? 60)),
        ];
    }

    private function key(string $provider): string
    {
        return 'upstream-rate:'.$provider;
    }
}
