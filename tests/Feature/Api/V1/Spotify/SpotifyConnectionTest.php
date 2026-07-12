<?php

namespace Tests\Feature\Api\V1\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\Integration\IntegrationConnection;
use App\Models\Integration\IntegrationOauthState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpotifyConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
            'services.spotify.redirect' => 'http://127.0.0.1:80/spotify/callback',
            'services.spotify.frontend_redirect' => 'http://nexus.test/spotify',
        ]);
    }

    public function test_connect_requires_authentication(): void
    {
        $this->getJson('/api/v1/spotify/connect')->assertUnauthorized();
    }

    public function test_unauthenticated_html_accept_returns_401_not_login_route_error(): void
    {
        $this->get('/api/v1/spotify/status', [
            'Accept' => 'text/html',
        ])
            ->assertUnauthorized()
            ->assertJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_connect_returns_authorization_url_and_stores_state(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/spotify/connect');

        $response->assertOk()->assertJsonStructure(['url']);

        $url = $response->json('url');
        $this->assertStringContainsString('accounts.spotify.com/authorize', $url);
        $this->assertStringContainsString('client_id=test-client-id', $url);
        $this->assertStringContainsString(urlencode('http://127.0.0.1:80/spotify/callback'), $url);

        $this->assertDatabaseCount('integration_oauth_states', 1);
        $this->assertDatabaseHas('integration_oauth_states', [
            'user_id' => $user->id,
            'provider' => SpotifyIntegration::PROVIDER,
        ]);
    }

    public function test_callback_exchanges_code_and_stores_encrypted_tokens(): void
    {
        Bus::fake();

        $user = User::factory()->create();

        IntegrationOauthState::query()->create([
            'user_id' => $user->id,
            'provider' => SpotifyIntegration::PROVIDER,
            'state' => 'valid-state-token',
            'expires_at' => now()->addMinutes(10),
        ]);

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'access-token-value',
                'token_type' => 'Bearer',
                'scope' => 'user-read-email user-read-private',
                'expires_in' => 3600,
                'refresh_token' => 'refresh-token-value',
            ], 200),
            'api.spotify.com/v1/me' => Http::response([
                'id' => 'spotify-user-1',
                'display_name' => 'Test User',
            ], 200),
        ]);

        $response = $this->get('/spotify/callback?code=auth-code&state=valid-state-token');

        $response->assertRedirect('http://nexus.test/spotify?connected=1');

        $connection = IntegrationConnection::query()->where('user_id', $user->id)->first();
        $this->assertNotNull($connection);
        $this->assertSame('spotify-user-1', $connection->external_user_id);
        $this->assertSame('access-token-value', $connection->access_token);
        $this->assertSame('refresh-token-value', $connection->refresh_token);
        $this->assertSame(IntegrationConnection::STATUS_ACTIVE, $connection->status);
        $this->assertDatabaseCount('integration_oauth_states', 0);

        $raw = DB::table('integration_connections')->where('id', $connection->id)->first();
        $this->assertNotSame('access-token-value', $raw->access_token);
    }

    public function test_callback_rejects_invalid_state(): void
    {
        $response = $this->get('/spotify/callback?code=auth-code&state=unknown');

        $response->assertRedirect();
        $this->assertStringContainsString('connected=0', $response->headers->get('Location'));
        $this->assertDatabaseCount('integration_connections', 0);
    }

    public function test_status_and_disconnect(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        IntegrationConnection::query()->create([
            'user_id' => $user->id,
            'provider' => SpotifyIntegration::PROVIDER,
            'external_user_id' => 'spotify-user-1',
            'scopes' => 'user-read-email',
            'access_token' => 'access',
            'refresh_token' => 'refresh',
            'access_token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);

        $this->getJson('/api/v1/spotify/status')
            ->assertOk()
            ->assertJsonPath('connected', true)
            ->assertJsonPath('external_user_id', 'spotify-user-1')
            ->assertJsonPath('needs_reauth', false);

        $this->postJson('/api/v1/spotify/disconnect')->assertNoContent();

        $this->assertDatabaseCount('integration_connections', 0);

        $this->getJson('/api/v1/spotify/status')
            ->assertOk()
            ->assertJsonPath('connected', false);
    }
}
