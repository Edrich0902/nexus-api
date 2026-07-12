<?php

namespace App\Services\Spotify;

use App\Integrations\Spotify\SpotifyIntegration;
use App\Jobs\Spotify\SyncPlaylistsJob;
use App\Jobs\Spotify\SyncRecentlyPlayedJob;
use App\Jobs\Spotify\SyncTopItemsJob;
use App\Models\Integration\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Bus;

class SpotifyConnectionService
{
    public function __construct(
        private readonly SpotifyIntegration $spotify,
    ) {}

    /**
     * @return array{url: string}
     */
    public function connectUrl(User $user): array
    {
        return [
            'url' => $this->spotify->getAuthorizationUrl($user),
        ];
    }

    public function handleCallback(string $code, string $state): RedirectResponse
    {
        $connection = $this->spotify->handleCallback($code, $state);

        $this->dispatchInitialSync($connection->user);

        return redirect()->away($this->successRedirectUrl());
    }

    public function handleCallbackError(?string $error): RedirectResponse
    {
        $query = http_build_query([
            'connected' => '0',
            'error' => $error ?: 'access_denied',
        ]);

        return redirect()->away($this->frontendSpotifyPath().'?'.$query);
    }

    /**
     * @return array{
     *     connected: bool,
     *     provider: string,
     *     status: string|null,
     *     external_user_id: string|null,
     *     scopes: list<string>,
     *     missing_scopes: list<string>,
     *     connected_at: string|null,
     *     last_synced_at: string|null,
     *     needs_reauth: bool
     * }
     */
    public function status(User $user): array
    {
        $connection = $this->spotify->connectionFor($user);

        if ($connection === null) {
            return [
                'connected' => false,
                'provider' => SpotifyIntegration::PROVIDER,
                'status' => null,
                'external_user_id' => null,
                'scopes' => [],
                'missing_scopes' => [],
                'connected_at' => null,
                'last_synced_at' => null,
                'needs_reauth' => false,
            ];
        }

        $scopes = $connection->scopeList();
        $required = $this->spotify->scopes();
        $missingScopes = array_values(array_diff($required, $scopes));
        $needsReauth = $connection->needsReauth() || $missingScopes !== [];

        return [
            'connected' => $connection->isActive() || $needsReauth,
            'provider' => $connection->provider,
            'status' => $needsReauth && ! $connection->needsReauth()
                ? IntegrationConnection::STATUS_NEEDS_REAUTH
                : $connection->status,
            'external_user_id' => $connection->external_user_id,
            'scopes' => $scopes,
            'missing_scopes' => $missingScopes,
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'needs_reauth' => $needsReauth,
        ];
    }

    public function disconnect(User $user): void
    {
        $this->spotify->disconnect($user);
    }

    public function dispatchSync(User $user): void
    {
        $this->spotify->requireConnection($user);
        $this->dispatchInitialSync($user);
    }

    public function requireConnection(User $user): IntegrationConnection
    {
        return $this->spotify->requireConnection($user);
    }

    private function dispatchInitialSync(User $user): void
    {
        Bus::chain([
            new SyncRecentlyPlayedJob($user->id),
            new SyncTopItemsJob($user->id),
            new SyncPlaylistsJob($user->id),
        ])->dispatch();
    }

    private function successRedirectUrl(): string
    {
        return $this->frontendSpotifyPath().'?connected=1';
    }

    private function frontendSpotifyPath(): string
    {
        return rtrim((string) config('services.spotify.frontend_redirect'), '/');
    }
}
