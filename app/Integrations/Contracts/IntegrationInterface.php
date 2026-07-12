<?php

namespace App\Integrations\Contracts;

use App\Integrations\DTOs\TokenSet;
use App\Models\Integration\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\Client\Response;

interface IntegrationInterface
{
    public function provider(): string;

    /**
     * @return list<string>
     */
    public function scopes(): array;

    public function getAuthorizationUrl(User $user): string;

    public function handleCallback(string $code, string $state): IntegrationConnection;

    public function ensureValidToken(IntegrationConnection $connection): string;

    /**
     * @param  array<string, mixed>  $options
     */
    public function request(
        IntegrationConnection $connection,
        string $method,
        string $path,
        array $options = [],
    ): Response;

    public function disconnect(User $user): void;

    public function exchangeCode(string $code): TokenSet;

    public function refreshAccessToken(IntegrationConnection $connection): TokenSet;
}
