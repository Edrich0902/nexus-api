<?php

namespace App\Integrations\Exceptions;

use RuntimeException;

class IntegrationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?array $payload = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function needsReauth(string $provider, string $message = 'Re-authorization required.'): self
    {
        // Use 409 so clients do not treat this as a Sanctum session failure (HTTP 401).
        return new self("[{$provider}] {$message}", 409);
    }

    public static function fromHttp(string $provider, int $status, ?array $payload = null): self
    {
        $detail = is_string($payload['error']['message'] ?? null)
            ? $payload['error']['message']
            : (is_string($payload['message'] ?? null)
                ? $payload['message']
                : (is_string($payload['error_description'] ?? null) ? $payload['error_description'] : 'Request failed.'));

        // Upstream provider 401/403 auth failures are integration problems, not Nexus auth.
        if ($status === 401) {
            $status = 409;
        }

        return new self("[{$provider}] {$detail} (HTTP {$status})", $status, $payload);
    }
}
