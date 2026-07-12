<?php

namespace App\Integrations\Github;

use App\Integrations\BaseIntegration;
use App\Integrations\DTOs\TokenSet;
use App\Integrations\Exceptions\IntegrationException;
use App\Models\Integration\IntegrationConnection;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class GithubIntegration extends BaseIntegration
{
    public const PROVIDER = 'github';

    public const API_VERSION = '2022-11-28';

    /**
     * GitHub Apps use fine-grained app permissions, not OAuth scopes.
     *
     * @return list<string>
     */
    public function scopes(): array
    {
        return [];
    }

    public function provider(): string
    {
        return self::PROVIDER;
    }

    protected function authorizationBaseUrl(): string
    {
        return 'https://github.com/login/oauth/authorize';
    }

    protected function tokenUrl(): string
    {
        return 'https://github.com/login/oauth/access_token';
    }

    protected function apiBaseUrl(): string
    {
        return 'https://api.github.com';
    }

    protected function clientId(): string
    {
        return (string) config('services.github.client_id');
    }

    protected function clientSecret(): string
    {
        return (string) config('services.github.client_secret');
    }

    protected function redirectUri(): string
    {
        return (string) config('services.github.redirect');
    }

    public function exchangeCode(string $code): TokenSet
    {
        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post($this->tokenUrl(), [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ]);

        if (! $response->successful()) {
            throw IntegrationException::fromHttp(
                $this->provider(),
                $response->status(),
                $response->json(),
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new IntegrationException(
                "[{$this->provider()}] Invalid token response.",
                500,
            );
        }

        if (isset($payload['error'])) {
            throw new IntegrationException(
                "[{$this->provider()}] ".((string) ($payload['error_description'] ?? $payload['error'])),
                400,
                $payload,
            );
        }

        return $this->tokenSetFromResponse($payload);
    }

    public function refreshAccessToken(IntegrationConnection $connection): TokenSet
    {
        if ($connection->refresh_token === null || $connection->refresh_token === '') {
            $this->markNeedsReauth($connection);
            throw IntegrationException::needsReauth($this->provider(), 'Missing refresh token.');
        }

        $response = Http::asForm()
            ->acceptJson()
            ->withBasicAuth($this->clientId(), $this->clientSecret())
            ->post($this->tokenUrl(), [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
            ]);

        if (! $response->successful()) {
            if ($response->status() === 400 || $response->status() === 401) {
                $this->markNeedsReauth($connection);
                throw IntegrationException::needsReauth($this->provider());
            }

            throw IntegrationException::fromHttp(
                $this->provider(),
                $response->status(),
                $response->json(),
            );
        }

        $payload = $response->json();
        if (! is_array($payload) || isset($payload['error'])) {
            $this->markNeedsReauth($connection);
            throw IntegrationException::needsReauth($this->provider());
        }

        $tokenSet = $this->tokenSetFromResponse(
            $payload,
            $connection->refresh_token,
        );

        $connection->forceFill([
            'access_token' => $tokenSet->accessToken,
            'refresh_token' => $tokenSet->refreshToken ?? $connection->refresh_token,
            'access_token_expires_at' => $tokenSet->expiresAt,
            'scopes' => $tokenSet->scopes !== '' ? $tokenSet->scopes : $connection->scopes,
            'status' => IntegrationConnection::STATUS_ACTIVE,
        ])->save();

        return $tokenSet;
    }

    protected function resolveExternalUserId(TokenSet $tokenSet): ?string
    {
        $response = Http::withToken($tokenSet->accessToken)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => self::API_VERSION,
            ])
            ->get($this->apiBaseUrl().'/user');

        if (! $response->successful()) {
            return null;
        }

        $id = $response->json('id');

        return $id !== null ? (string) $id : null;
    }

    protected function httpClient(string $accessToken): PendingRequest
    {
        return Http::baseUrl($this->apiBaseUrl())
            ->withToken($accessToken)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => self::API_VERSION,
            ])
            ->timeout(20);
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
        if ($body === []) {
            return $this->request($connection, 'PUT', $path, [
                'query' => $query,
                'empty_body' => true,
            ]);
        }

        return $this->request($connection, 'PUT', $path, [
            'query' => $query,
            'json' => $body,
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
        if ($body === []) {
            return $this->request($connection, 'DELETE', $path, [
                'query' => $query,
                'empty_body' => true,
            ]);
        }

        return $this->request($connection, 'DELETE', $path, [
            'query' => $query,
            'json' => $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(IntegrationConnection $connection, string $query, array $variables = []): array
    {
        $token = $this->ensureValidToken($connection);

        $response = Http::withToken($token)
            ->accept('application/vnd.github+json')
            ->withHeaders([
                'X-GitHub-Api-Version' => self::API_VERSION,
            ])
            ->timeout(20)
            ->post('https://api.github.com/graphql', [
                'query' => $query,
                'variables' => $variables,
            ]);

        if (! $response->successful()) {
            throw IntegrationException::fromHttp(
                $this->provider(),
                $response->status(),
                $response->json(),
            );
        }

        $payload = $response->json() ?? [];
        if (! is_array($payload)) {
            throw new IntegrationException(
                "[{$this->provider()}] Invalid GraphQL response.",
                502,
            );
        }

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $message = is_string($payload['errors'][0]['message'] ?? null)
                ? $payload['errors'][0]['message']
                : 'GraphQL request failed.';

            throw new IntegrationException(
                "[{$this->provider()}] {$message}",
                422,
                $payload,
            );
        }

        $data = $payload['data'] ?? [];

        return is_array($data) ? $data : [];
    }
}
