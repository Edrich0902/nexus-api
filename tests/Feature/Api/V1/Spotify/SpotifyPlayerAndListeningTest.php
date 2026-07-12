<?php

namespace Tests\Feature\Api\V1\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Models\Integration\IntegrationConnection;
use App\Models\Spotify\SpotifyRecentlyPlayed;
use App\Models\Spotify\SpotifyTrack;
use App\Models\User;
use App\Services\Spotify\SpotifyTasteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpotifyPlayerAndListeningTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.spotify.client_id' => 'test-client-id',
            'services.spotify.client_secret' => 'test-client-secret',
            'services.spotify.redirect' => 'http://127.0.0.1:80/spotify/callback',
        ]);

        $this->user = User::factory()->create();

        IntegrationConnection::query()->create([
            'user_id' => $this->user->id,
            'provider' => SpotifyIntegration::PROVIDER,
            'external_user_id' => 'spotify-user-1',
            'scopes' => implode(' ', app(SpotifyIntegration::class)->scopes()),
            'access_token' => 'access-token',
            'refresh_token' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'connected_at' => now(),
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ]);

        Sanctum::actingAs($this->user);
    }

    public function test_player_returns_idle_state_on_204(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player' => Http::response(null, 204),
        ]);

        $this->getJson('/api/v1/spotify/player')
            ->assertOk()
            ->assertJsonPath('is_playing', false)
            ->assertJsonPath('message', 'No active device');
    }

    public function test_player_play_proxies_to_spotify(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/play*' => Http::response(null, 204),
        ]);

        $this->putJson('/api/v1/spotify/player/play', [
            'context_uri' => 'spotify:playlist:abc',
        ])->assertNoContent();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.spotify.com/v1/me/player/play'
                && $request->method() === 'PUT'
                && $request['context_uri'] === 'spotify:playlist:abc';
        });
    }

    public function test_player_play_with_uris_sends_json_object(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/play*' => Http::response(null, 204),
        ]);

        $this->putJson('/api/v1/spotify/player/play', [
            'uris' => ['spotify:track:abc123'],
        ])->assertNoContent();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.spotify.com/v1/me/player/play'
                && $request->method() === 'PUT'
                && $request->body() === '{"uris":["spotify:track:abc123"]}'
                && $request['uris'] === ['spotify:track:abc123'];
        });
    }

    public function test_player_play_resume_sends_empty_json_object_not_array(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/play*' => Http::response(null, 204),
        ]);

        $this->putJson('/api/v1/spotify/player/play', [])->assertNoContent();

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.spotify.com/v1/me/player/play'
                && $request->method() === 'PUT'
                && $request->body() === '{}';
        });
    }

    public function test_player_pause_sends_empty_json_object_not_array(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/player/pause*' => Http::response(null, 204),
        ]);

        $this->putJson('/api/v1/spotify/player/pause')->assertNoContent();

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.spotify.com/v1/me/player/pause')
                && $request->method() === 'PUT'
                && $request->body() === '{}';
        });
    }

    public function test_library_save_uses_uri_endpoint(): void
    {
        Http::fake([
            'api.spotify.com/v1/me/library' => Http::response(null, 200),
        ]);

        $this->putJson('/api/v1/spotify/library', [
            'uris' => ['spotify:track:abc123'],
        ])->assertNoContent();

        Http::assertSent(fn ($request) => $request->url() === 'https://api.spotify.com/v1/me/library'
            && $request->method() === 'PUT'
            && $request['uris'] === ['spotify:track:abc123']);
    }

    public function test_taste_endpoint_returns_computed_profile(): void
    {
        $track = SpotifyTrack::query()->create([
            'spotify_id' => 'track-1',
            'name' => 'Song',
            'uri' => 'spotify:track:track-1',
            'artists' => [['id' => 'a1', 'name' => 'Artist']],
        ]);

        app(SpotifyTasteService::class)->recompute($this->user);

        $this->getJson('/api/v1/spotify/taste')
            ->assertOk()
            ->assertJsonStructure(['genres', 'top_artists', 'top_tracks', 'notes']);

        $this->assertNotNull($track->id);
    }

    public function test_recently_played_reads_from_database(): void
    {
        $track = SpotifyTrack::query()->create([
            'spotify_id' => 'track-2',
            'name' => 'Recent Song',
            'uri' => 'spotify:track:track-2',
        ]);

        SpotifyRecentlyPlayed::query()->create([
            'user_id' => $this->user->id,
            'spotify_track_id' => $track->id,
            'played_at' => now()->subMinute(),
        ]);

        $this->getJson('/api/v1/spotify/recently-played')
            ->assertOk()
            ->assertJsonPath('data.0.track.id', 'track-2');
    }
}
