<?php

namespace Tests\Unit\Integrations\OpenF1;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\OpenF1\OpenF1ProviderHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class OpenF1ProviderHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_live_session_lockout_from_detail(): void
    {
        $e = IntegrationException::fromHttp('openf1', 401, [
            'detail' => 'Live F1 session in progress. Global API access (including past sessions) is restricted to authenticated users until the session ends.',
        ]);

        $this->assertSame(409, $e->statusCode);
        $this->assertStringContainsString('Live F1 session', $e->getMessage());
        $this->assertTrue(OpenF1ProviderHealth::isLiveLockout($e));
    }

    public function test_ignores_unrelated_409(): void
    {
        $e = new IntegrationException('[openf1] Conflict (HTTP 409)', 409, ['detail' => 'Something else']);

        $this->assertFalse(OpenF1ProviderHealth::isLiveLockout($e));
    }

    public function test_caches_lockout_snapshot(): void
    {
        Cache::flush();

        $e = IntegrationException::fromHttp('openf1', 401, [
            'detail' => 'Live F1 session in progress. Restricted to authenticated users.',
        ]);
        OpenF1ProviderHealth::rememberLockout($e);

        $snap = OpenF1ProviderHealth::snapshot();
        $this->assertFalse($snap['available']);
        $this->assertTrue($snap['live_lockout']);
        $this->assertNotNull($snap['locked_until']);
    }
}
