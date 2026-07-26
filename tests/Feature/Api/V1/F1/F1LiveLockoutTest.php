<?php

namespace Tests\Feature\Api\V1\F1;

use App\Integrations\OpenF1\OpenF1ProviderHealth;
use App\Jobs\F1\SyncF1SeasonJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class F1LiveLockoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openf1.base_url' => 'https://api.openf1.org/v1',
            'services.openf1.sync.live_lockout_release_seconds' => 1800,
            'services.rate_limits.openf1' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
        ]);
    }

    public function test_season_job_releases_on_live_lockout_instead_of_failing(): void
    {
        Queue::fake();
        Cache::flush();

        Http::fake([
            'api.openf1.org/v1/meetings*' => Http::response([
                'detail' => 'Live F1 session in progress. Global API access is restricted to authenticated users until the session ends.',
            ], 401),
        ]);

        $job = new SyncF1SeasonJob(2025);
        $job->withFakeQueueInteractions();
        $job->handle(app(\App\Services\F1\F1SyncService::class), app(\App\Services\F1\F1HomeService::class));

        $job->assertReleased(OpenF1ProviderHealth::releaseSeconds());

        $snap = OpenF1ProviderHealth::snapshot();
        $this->assertTrue($snap['live_lockout']);
    }

    public function test_status_includes_provider_health(): void
    {
        Cache::flush();
        OpenF1ProviderHealth::rememberLockout(
            \App\Integrations\Exceptions\IntegrationException::fromHttp('openf1', 401, [
                'detail' => 'Live F1 session in progress. Restricted to authenticated users.',
            ]),
        );

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/f1/status')
            ->assertOk()
            ->assertJsonPath('provider_health.live_lockout', true)
            ->assertJsonPath('provider_health.available', false);
    }
}
