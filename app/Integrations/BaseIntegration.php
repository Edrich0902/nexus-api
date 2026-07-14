<?php

namespace App\Integrations;

use App\Integrations\Contracts\IntegrationInterface;
use App\Integrations\DTOs\TokenSet;
use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\UpstreamRateGate;
use App\Models\Integration\IntegrationConnection;
use App\Models\Integration\IntegrationOauthState;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class BaseIntegration implements IntegrationInterface
{
    protected const TOKEN_REFRESH_SKEW_SECONDS = 300;

    protected const OAUTH_STATE_TTL_MINUTES = 10;

    public function __construct(
        protected readonly UpstreamRateGate $rateGate,
    ) {}

    abstract public function provider(): string;

    /**
     * @return list<string>
     */
    abstract public function scopes(): array;

    abstract protected function authorizationBaseUrl(): string;

    abstract protected function tokenUrl(): string;

    abstract protected function apiBaseUrl(): string;

    abstract protected function clientId(): string;

    abstract protected function clientSecret(): string;

    abstract protected function redirectUri(): string;

    /**
     * @return array<string, string>
     */
    protected function extraAuthorizationParams(): array
    {
        return [];
    }

    public function getAuthorizationUrl(User $user): string
    {
        $state = $this->createOauthState($user);

        $query = array_merge([
            'client_id' => $this->clientId(),
            'response_type' => 'code',
            'redirect_uri' => $this->redirectUri(),
            'scope' => implode(' ', $this->scopes()),
            'state' => $state,
        ], $this->extraAuthorizationParams());

        return $this->authorizationBaseUrl().'?'.http_build_query($query);
    }

    public function handleCallback(string $code, string $state): IntegrationConnection
    {
        $oauthState = IntegrationOauthState::query()
            ->where('provider', $this->provider())
            ->where('state', $state)
            ->first();

        if ($oauthState === null || $oauthState->isExpired()) {
            $oauthState?->delete();
            throw new IntegrationException(
                "[{$this->provider()}] Invalid or expired OAuth state.",
                400,
            );
        }

        $user = $oauthState->user;
        $oauthState->delete();

        $tokenSet = $this->exchangeCode($code);
        $externalUserId = $this->resolveExternalUserId($tokenSet);

        return IntegrationConnection::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'provider' => $this->provider(),
            ],
            [
                'external_user_id' => $externalUserId,
                'scopes' => $tokenSet->scopes !== '' ? $tokenSet->scopes : implode(' ', $this->scopes()),
                'access_token' => $tokenSet->accessToken,
                'refresh_token' => $tokenSet->refreshToken,
                'access_token_expires_at' => $tokenSet->expiresAt,
                'connected_at' => now(),
                'status' => IntegrationConnection::STATUS_ACTIVE,
            ],
        );
    }

    public function exchangeCode(string $code): TokenSet
    {
        $response = Http::asForm()
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

        return $this->tokenSetFromResponse($response->json());
    }

    public function refreshAccessToken(IntegrationConnection $connection): TokenSet
    {
        if ($connection->refresh_token === null || $connection->refresh_token === '') {
            $this->markNeedsReauth($connection);
            throw IntegrationException::needsReauth($this->provider(), 'Missing refresh token.');
        }

        $response = Http::asForm()
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

        $tokenSet = $this->tokenSetFromResponse(
            $response->json(),
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

    public function ensureValidToken(IntegrationConnection $connection): string
    {
        if ($connection->provider !== $this->provider()) {
            throw new IntegrationException(
                "Connection provider mismatch for {$this->provider()}.",
                500,
            );
        }

        if ($connection->needsReauth()) {
            throw IntegrationException::needsReauth($this->provider());
        }

        $expiresAt = $connection->access_token_expires_at;

        if ($expiresAt === null || $expiresAt->lte(now()->addSeconds(self::TOKEN_REFRESH_SKEW_SECONDS))) {
            $this->refreshAccessToken($connection->fresh());
            $connection->refresh();
        }

        return $connection->access_token;
    }

    public function request(
        IntegrationConnection $connection,
        string $method,
        string $path,
        array $options = [],
    ): Response {
        $relativePath = ltrim($path, '/');
        $token = $this->ensureValidToken($connection);
        $this->rateGate->acquire($this->provider(), $this->rateGateMaxWaitSeconds());
        $response = $this->sendAuthorized($token, $method, $relativePath, $options);

        if ($response->status() === 401) {
            $this->refreshAccessToken($connection->fresh());
            $connection->refresh();
            $this->rateGate->acquire($this->provider(), $this->rateGateMaxWaitSeconds());
            $response = $this->sendAuthorized($connection->access_token, $method, $relativePath, $options);
        }

        if ($response->status() === 429) {
            // Fail fast — recommendation neighborhood builds catch 429 and return [].
            // Do not sleep for Retry-After (can be minutes) or park on an exhausted gate.
            $this->rateGate->exhaust($this->provider());
            throw IntegrationException::fromHttp(
                $this->provider(),
                429,
                $response->json(),
            );
        }

        if ($response->failed() && $response->status() !== 204) {
            throw IntegrationException::fromHttp(
                $this->provider(),
                $response->status(),
                $response->json(),
            );
        }

        return $response;
    }

    private function rateGateMaxWaitSeconds(): ?int
    {
        $maxWait = config('services.rate_limits.'.$this->provider().'.max_wait_seconds');

        return is_numeric($maxWait) ? (int) $maxWait : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function sendAuthorized(
        string $accessToken,
        string $method,
        string $relativePath,
        array $options,
    ): Response {
        $client = $this->httpClient($accessToken);
        $method = strtoupper($method);
        $query = $options['query'] ?? [];
        $json = $options['json'] ?? null;
        $pending = $client->withQueryParameters($query);

        if (($options['empty_body'] ?? false) === true) {
            // GitHub starring requires Content-Length: 0 with no JSON body.
            return $pending
                ->withHeaders(['Content-Length' => '0'])
                ->send($method, $relativePath);
        }

        return match ($method) {
            'GET' => $client->get($relativePath, $query),
            'DELETE' => $this->sendJson($pending, 'DELETE', $relativePath, $json),
            'PUT' => $this->sendJson($pending, 'PUT', $relativePath, $json),
            'POST' => $this->sendJson($pending, 'POST', $relativePath, $json),
            default => $client->send($method, $relativePath, $options),
        };
    }

    /**
     * Spotify rejects Laravel's asJson() default body "[]" as malformed JSON.
     * Empty player control bodies must be "{}" (object), never a JSON array.
     *
     * @param  array<string, mixed>|null  $json
     */
    private function sendJson(
        PendingRequest $client,
        string $method,
        string $relativePath,
        ?array $json,
    ): Response {
        if ($json === null) {
            return $client
                ->withBody('{}', 'application/json')
                ->send($method, $relativePath);
        }

        return $client->asJson()->send($method, $relativePath, [
            'json' => $json,
        ]);
    }

    public function disconnect(User $user): void
    {
        IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', $this->provider())
            ->delete();

        IntegrationOauthState::query()
            ->where('user_id', $user->id)
            ->where('provider', $this->provider())
            ->delete();
    }

    public function connectionFor(User $user): ?IntegrationConnection
    {
        return IntegrationConnection::query()
            ->where('user_id', $user->id)
            ->where('provider', $this->provider())
            ->first();
    }

    public function requireConnection(User $user): IntegrationConnection
    {
        $connection = $this->connectionFor($user);

        if ($connection === null || $connection->status === IntegrationConnection::STATUS_DISCONNECTED) {
            throw new IntegrationException(
                "[{$this->provider()}] Account is not connected.",
                404,
            );
        }

        if ($connection->needsReauth()) {
            throw IntegrationException::needsReauth($this->provider());
        }

        return $connection;
    }

    protected function createOauthState(User $user): string
    {
        IntegrationOauthState::query()
            ->where('user_id', $user->id)
            ->where('provider', $this->provider())
            ->delete();

        $state = Str::random(40);

        IntegrationOauthState::query()->create([
            'user_id' => $user->id,
            'provider' => $this->provider(),
            'state' => $state,
            'expires_at' => now()->addMinutes(self::OAUTH_STATE_TTL_MINUTES),
        ]);

        return $state;
    }

    protected function resolveExternalUserId(TokenSet $tokenSet): ?string
    {
        return null;
    }

    protected function markNeedsReauth(IntegrationConnection $connection): void
    {
        $connection->forceFill([
            'status' => IntegrationConnection::STATUS_NEEDS_REAUTH,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function tokenSetFromResponse(array $payload, ?string $fallbackRefreshToken = null): TokenSet
    {
        $accessToken = $payload['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new IntegrationException(
                "[{$this->provider()}] Token response missing access_token.",
                500,
                $payload,
            );
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 3600);
        $refreshToken = is_string($payload['refresh_token'] ?? null)
            ? $payload['refresh_token']
            : $fallbackRefreshToken;
        $scopes = is_string($payload['scope'] ?? null) ? $payload['scope'] : '';

        return new TokenSet(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: now()->addSeconds($expiresIn),
            scopes: $scopes,
        );
    }

    protected function httpClient(string $accessToken): PendingRequest
    {
        return Http::baseUrl($this->apiBaseUrl())
            ->withToken($accessToken)
            ->acceptJson()
            ->timeout(20);
    }
}
