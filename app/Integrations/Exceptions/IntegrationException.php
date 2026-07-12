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
        return new self("[{$provider}] {$message}", 401);
    }

    public static function fromHttp(string $provider, int $status, ?array $payload = null): self
    {
        $detail = is_string($payload['error']['message'] ?? null)
            ? $payload['error']['message']
            : (is_string($payload['error_description'] ?? null) ? $payload['error_description'] : 'Request failed.');

        return new self("[{$provider}] {$detail}", $status, $payload);
    }
}
