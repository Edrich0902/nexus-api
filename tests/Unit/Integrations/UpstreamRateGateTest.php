<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\UpstreamRateGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class UpstreamRateGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_acquire_allows_within_limit(): void
    {
        config([
            'services.rate_limits.sportsdb' => [
                'max_attempts' => 2,
                'decay_seconds' => 60,
            ],
        ]);

        RateLimiter::clear('upstream-rate:sportsdb');

        $gate = app(UpstreamRateGate::class);
        $gate->acquire('sportsdb');
        $gate->acquire('sportsdb');

        $this->expectException(IntegrationException::class);
        $gate->acquire('sportsdb', 1);
    }

    public function test_acquire_with_zero_wait_fails_immediately_when_exhausted(): void
    {
        config([
            'services.rate_limits.sportsdb' => [
                'max_attempts' => 1,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
        ]);

        RateLimiter::clear('upstream-rate:sportsdb');

        $gate = app(UpstreamRateGate::class);
        $gate->acquire('sportsdb', 0);

        $started = microtime(true);
        try {
            $gate->acquire('sportsdb', 0);
            $this->fail('Expected IntegrationException');
        } catch (IntegrationException $e) {
            $this->assertSame(429, $e->statusCode);
            $this->assertLessThan(0.5, microtime(true) - $started);
        }
    }
}
