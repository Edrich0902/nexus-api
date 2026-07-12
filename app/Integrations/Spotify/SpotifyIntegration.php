<?php

namespace App\Integrations\Spotify;

use App\Integrations\BaseIntegration;
use App\Integrations\DTOs\TokenSet;
use App\Models\Integration\IntegrationConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SpotifyIntegration extends BaseIntegration
{
    public const PROVIDER = 'spotify';

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return [
            'user-read-email',
            'user-read-private',
            'user-read-playback-state',
            'user-modify-playback-state',
            'user-read-currently-playing',
            'user-read-recently-played',
            'user-top-read',
            'playlist-read-private',
            'playlist-read-collaborative',
            'playlist-modify-public',
            'playlist-modify-private',
            'user-library-read',
            'user-library-modify',
        ];
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    protected function authorizationBaseUrl(): string
    {
        return 'https://accounts.spotify.com/authorize';
    }

    protected function tokenUrl(): string
    {
        return 'https://accounts.spotify.com/api/token';
    }

    protected function apiBaseUrl(): string
    {
        return 'https://api.spotify.com/v1';
    }

    protected function clientId(): string
    {
        return (string) config('services.spotify.client_id');
    }

    protected function clientSecret(): string
    {
        return (string) config('services.spotify.client_secret');
    }

    protected function redirectUri(): string
    {
        return (string) config('services.spotify.redirect');
    }

    /**
     * @return array<string, string>
     */
    protected function extraAuthorizationParams(): array
    {
        return [
            'show_dialog' => 'true',
        ];
    }

    protected function resolveExternalUserId(TokenSet $tokenSet): ?string
    {
        $response = Http::withToken($tokenSet->accessToken)
            ->acceptJson()
            ->get($this->apiBaseUrl().'/me');

        if (! $response->successful()) {
            return null;
        }

        $id = $response->json('id');

        return is_string($id) ? $id : null;
    }

    /**
     * @param  array<string, mixed>  $query
     */
    public function get(IntegrationConnection $connection, string $path, array $query = []): Response
    {
        return $this->request($connection, 'GET', $path, [
            'query' => $query,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    public function put(IntegrationConnection $connection, string $path, array $body = [], array $query = []): Response
    {
        return $this->request($connection, 'PUT', $path, [
            'query' => $query,
            'json' => $body === [] ? null : $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    public function post(IntegrationConnection $connection, string $path, array $body = [], array $query = []): Response
    {
        return $this->request($connection, 'POST', $path, [
            'query' => $query,
            'json' => $body === [] ? null : $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    public function delete(IntegrationConnection $connection, string $path, array $body = [], array $query = []): Response
    {
        return $this->request($connection, 'DELETE', $path, [
            'query' => $query,
            'json' => $body === [] ? null : $body,
        ]);
    }
}
