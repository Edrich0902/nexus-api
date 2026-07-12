<?php

namespace Tests\Unit\Integrations;

use App\Integrations\DTOs\TokenSet;
use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\Integration\IntegrationConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SpotifyIntegrationTokenRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_ensure_valid_token_refreshes_when_near_expiry(): void
    {
        config([
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
            'services.spotify.redirect' => 'http://127.0.0.1/spotify/callback',
        ]);

        $user = User::factory()->create();
        $connection = IntegrationConnection::query()->create([
            'user_id' => $user->id,
            'provider' => SpotifyIntegration::PROVIDER,
            'access_token' => 'old-access',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addSeconds(60),
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response([
                'access_token' => 'new-access',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
                'scope' => 'user-read-email',
            ], 200),
        ]);

        $token = app(SpotifyIntegration::class)->ensureValidToken($connection);

        $this->assertSame('new-access', $token);
        $this->assertSame('new-access', $connection->fresh()->access_token);
    }

    public function test_token_set_dto_holds_values(): void
    {
        $set = new TokenSet('a', 'r', now()->addHour(), 'scope');
        $this->assertSame('a', $set->accessToken);
        $this->assertSame('r', $set->refreshToken);
    }
}
