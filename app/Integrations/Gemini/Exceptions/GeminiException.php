<?php

namespace App\Integrations\Gemini\Exceptions;

use RuntimeException;

class GeminiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
        public readonly ?array $payload = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
