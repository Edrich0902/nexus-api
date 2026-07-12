<?php

namespace Tests\Feature\Api\V1\Github;

use App\Integrations\Github\GithubIntegration;
use App\Jobs\Github\SyncGithubReposJob;
use App\Models\Github\GithubRepo;
use App\Models\Integration\IntegrationConnection;
use App\Models\Integration\IntegrationOauthState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GithubConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.github.client_id' => 'test-github-client',
            'services.github.client_secret' => 'test-github-secret',
            'services.github.redirect' => 'http://127.0.0.1:80/github/callback',
            'services.github.frontend_redirect' => 'http://nexus.test/github',
        ]);
    }

    public function test_connect_requires_authentication(): void
    {
        $this->getJson('/api/v1/github/connect')->assertUnauthorized();
    }

    public function test_connect_returns_authorization_url_and_stores_state(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/github/connect');

        $response->assertOk()->assertJsonStructure(['url']);

        $url = $response->json('url');
        $this->assertStringContainsString('github.com/login/oauth/authorize', $url);
        $this->assertStringContainsString('client_id=test-github-client', $url);
        $this->assertStringContainsString(urlencode('http://127.0.0.1:80/github/callback'), $url);

        $this->assertDatabaseHas('integration_oauth_states', [
            'user_id' => $user->id,
            'provider' => GithubIntegration::PROVIDER,
        ]);
    }

    public function test_callback_exchanges_code_and_stores_encrypted_tokens(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        IntegrationOauthState::query()->create([
            'user_id' => $user->id,
            'provider' => GithubIntegration::PROVIDER,
            'state' => 'valid-github-state',
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::fake([
            'github.com/login/oauth/access_token' => Http::response([
                'access_token' => 'ghu_access_token',
                'expires_in' => 28800,
                'refresh_token' => 'ghr_refresh_token',
                'refresh_token_expires_in' => 15897600,
                'scope' => '',
                'token_type' => 'bearer',
            ], 200),
            'api.github.com/user' => Http::response([
                'id' => 4242,
                'login' => 'edrich',
            ], 200),
        ]);

        $response = $this->get('/github/callback?code=auth-code&state=valid-github-state');

        $response->assertRedirect('http://nexus.test/github?connected=1');

        $connection = IntegrationConnection::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame('4242', $connection->external_user_id);
        $this->assertSame('ghu_access_token', $connection->access_token);
        $this->assertSame('ghr_refresh_token', $connection->refresh_token);
        $this->assertSame(IntegrationConnection::STATUS_ACTIVE, $connection->status);

        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();
        $this->assertNotSame('ghu_access_token', $raw->access_token);

        Bus::assertDispatched(SyncGithubReposJob::class);
    }

    public function test_status_and_disconnect(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        IntegrationConnection::query()->create([
            'user_id' => $user->id,
            'provider' => GithubIntegration::PROVIDER,
            'external_user_id' => '4242',
            'scopes' => '',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/v1/github/status')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('external_user_id', '4242');

        $this->postJson('/api/v1/github/disconnect')->assertNoContent();

        $this->assertDatabaseCount('integration_connections', 0);
    }

    public function test_repos_list_and_pull_create_merge(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        IntegrationConnection::query()->create([
            'user_id' => $user->id,
            'provider' => GithubIntegration::PROVIDER,
            'external_user_id' => '4242',
            'scopes' => '',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);

        GithubRepo::query()->create([
            'user_id' => $user->id,
            'github_id' => 99,
            'owner_login' => 'edrich',
            'name' => 'nexus-api',
            'full_name' => 'edrich/nexus-api',
            'private' => true,
            'default_branch' => 'main',
            'html_url' => 'https://github.com/edrich/nexus-api',
            'description' => 'API',
            'pushed_at' => now(),
            'language' => 'PHP',
        ]);

        Http::fake([
            'api.github.com/user' => Http::response([
                'id' => 4242,
                'login' => 'edrich',
                'avatar_url' => 'https://avatars.example/edrich',
                'public_repos' => 3,
            ], 200),
            'api.github.com/repos/edrich/nexus-api/pulls/7/files*' => Http::response([
                [
                    'filename' => 'README.md',
                    'status' => 'modified',
                    'additions' => 2,
                    'deletions' => 1,
                    'patch' => "@@ -1 +1 @@\n-old\n+new",
                ],
            ], 200),
            'api.github.com/repos/edrich/nexus-api/pulls/7/merge*' => Http::response([
                'sha' => 'abc123',
                'merged' => true,
                'message' => 'Pull Request successfully merged',
            ], 200),
            'api.github.com/repos/edrich/nexus-api/pulls/7*' => Http::response([
                'id' => 1,
                'number' => 7,
                'title' => 'Add feature',
                'state' => 'open',
                'mergeable' => true,
                'user' => ['login' => 'edrich'],
                'head' => ['ref' => 'feature'],
                'base' => ['ref' => 'main'],
                'html_url' => 'https://github.com/edrich/nexus-api/pull/7',
            ], 200),
            'api.github.com/repos/edrich/nexus-api/pulls*' => function ($request) {
                if ($request->method() === 'POST') {
                    return Http::response([
                        'id' => 2,
                        'number' => 8,
                        'title' => 'New PR',
                        'state' => 'open',
                        'body' => 'Details',
                        'user' => ['login' => 'edrich'],
                        'head' => ['ref' => 'feat'],
                        'base' => ['ref' => 'main'],
                        'html_url' => 'https://github.com/edrich/nexus-api/pull/8',
                    ], 201);
                }

                return Http::response([
                    [
                        'id' => 1,
                        'number' => 7,
                        'title' => 'Add feature',
                        'state' => 'open',
                        'user' => ['login' => 'edrich'],
                        'head' => ['ref' => 'feature'],
                        'base' => ['ref' => 'main'],
                        'html_url' => 'https://github.com/edrich/nexus-api/pull/7',
                    ],
                ], 200);
            },
        ]);

        $this->getJson('/api/v1/github/me')
            ->assertOk()
            ->assertJsonPath('login', 'edrich');

        $this->getJson('/api/v1/github/repos')
            ->assertOk()
            ->assertJsonPath('0.full_name', 'edrich/nexus-api');

        $this->getJson('/api/v1/github/repos/edrich/nexus-api/pulls')
            ->assertOk()
            ->assertJsonPath('items.0.number', 7);

        $this->getJson('/api/v1/github/repos/edrich/nexus-api/pulls/7/files')
            ->assertOk()
            ->assertJsonPath('items.0.filename', 'README.md');

        $this->postJson('/api/v1/github/repos/edrich/nexus-api/pulls', [
            'title' => 'New PR',
            'head' => 'feat',
            'base' => 'main',
            'body' => 'Details',
        ])
            ->assertCreated()
            ->assertJsonPath('number', 8);

        $this->putJson('/api/v1/github/repos/edrich/nexus-api/pulls/7/merge', [
            'merge_method' => 'squash',
        ])
            ->assertOk()
            ->assertJsonPath('merged', true);
    }
}
