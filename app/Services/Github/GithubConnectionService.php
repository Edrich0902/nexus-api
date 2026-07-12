<?php

namespace App\Services\Github;

use App\Integrations\Github\GithubIntegration;
use App\Jobs\Github\SyncGithubReposJob;
use App\Models\Github\GithubRepo;
use App\Models\Integration\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class GithubConnectionService
{
    public function __construct(
        private readonly GithubIntegration $github,
    ) {}

    /**
     * @return array{url: string}
     */
    public function connectUrl(User $user): array
    {
        return [
            'url' => $this->github->getAuthorizationUrl($user),
        ];
    }

    public function handleCallback(string $code, string $state): RedirectResponse
    {
        $connection = $this->github->handleCallback($code, $state);

        $this->dispatchInitialSync($connection->user);

        return redirect()->away($this->successRedirectUrl());
    }

    public function handleCallbackError(?string $error): RedirectResponse
    {
        $query = http_build_query([
            'connected' => '0',
            'error' => $error ?: 'access_denied',
        ]);

        return redirect()->away($this->frontendGithubPath().'?'.$query);
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
        $connection = $this->github->connectionFor($user);

        if ($connection === null) {
            return [
                'connected' => false,
                'provider' => GithubIntegration::PROVIDER,
                'status' => null,
                'external_user_id' => null,
                'scopes' => [],
                'missing_scopes' => [],
                'connected_at' => null,
                'last_synced_at' => null,
                'needs_reauth' => false,
            ];
        }

        $needsReauth = $connection->needsReauth();

        return [
            'connected' => $connection->isActive() || $needsReauth,
            'provider' => $connection->provider,
            'status' => $connection->status,
            'external_user_id' => $connection->external_user_id,
            'scopes' => $connection->scopeList(),
            'missing_scopes' => [],
            'connected_at' => $connection->connected_at?->toIso8601String(),
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'needs_reauth' => $needsReauth,
        ];
    }

    public function disconnect(User $user): void
    {
        $this->github->disconnect($user);

        GithubRepo::query()->where('user_id', $user->id)->delete();
    }

    public function dispatchSync(User $user): void
    {
        $this->github->requireConnection($user);
        $this->dispatchInitialSync($user);
    }

    public function requireConnection(User $user): IntegrationConnection
    {
        return $this->github->requireConnection($user);
    }

    private function dispatchInitialSync(User $user): void
    {
        SyncGithubReposJob::dispatch($user->id);
    }

    private function successRedirectUrl(): string
    {
        return $this->frontendGithubPath().'?connected=1';
    }

    private function frontendGithubPath(): string
    {
        return rtrim((string) config('services.github.frontend_redirect'), '/');
    }
}
