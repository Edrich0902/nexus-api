<?php

namespace Tests\Feature\Api\V1\Admin;

use App\Integrations\OpenF1\OpenF1ProviderHealth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOpsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/overview')->assertUnauthorized();
    }

    public function test_overview_jobs_and_failed_jobs(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Cache::flush();
        OpenF1ProviderHealth::rememberAvailable();

        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\F1\\SyncF1SeasonJob',
                'data' => ['commandName' => 'App\\Jobs\\F1\\SyncF1SeasonJob'],
            ]),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->getTimestamp(),
            'created_at' => now()->getTimestamp(),
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => '11111111-1111-1111-1111-111111111111',
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => 'App\\Jobs\\Sports\\SyncSportsDayJob',
            ]),
            'exception' => "Exception: boom\nStack...",
            'failed_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/overview')
            ->assertOk()
            ->assertJsonPath('failed_job_count', 1)
            ->assertJsonPath('providers.openf1.available', true)
            ->assertJsonPath('queues.0.queue', 'default')
            ->assertJsonPath('queues.0.pending', 1)
            ->assertJsonStructure([
                'queues',
                'server' => ['memory', 'disk', 'php_version'],
                'telescope_url',
            ]);

        $this->getJson('/api/v1/admin/jobs')
            ->assertOk()
            ->assertJsonPath('data.0.job_class', 'App\\Jobs\\F1\\SyncF1SeasonJob')
            ->assertJsonPath('total', 1);

        $this->getJson('/api/v1/admin/failed-jobs')
            ->assertOk()
            ->assertJsonPath('data.0.uuid', '11111111-1111-1111-1111-111111111111')
            ->assertJsonPath('data.0.job_class', 'App\\Jobs\\Sports\\SyncSportsDayJob');

        $this->getJson('/api/v1/admin/recent-jobs')
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_retry_and_forget_failed_job(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $uuid = '22222222-2222-2222-2222-222222222222';
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\ExampleJob']),
            'exception' => 'Exception: fail',
            'failed_at' => now(),
        ]);

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:retry', ['id' => [$uuid]])
            ->andReturn(0);

        $this->postJson("/api/v1/admin/failed-jobs/{$uuid}/retry")
            ->assertOk()
            ->assertJsonPath('uuid', $uuid);

        Artisan::shouldReceive('call')
            ->once()
            ->with('queue:forget', ['id' => $uuid])
            ->andReturn(0);

        $this->deleteJson("/api/v1/admin/failed-jobs/{$uuid}")
            ->assertOk()
            ->assertJsonPath('uuid', $uuid);
    }
}
