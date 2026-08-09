<?php

namespace App\Integrations\Gemini\Exceptions;

class GeminiQuotaException extends GeminiException
{
    public function __construct(
        string $message = 'All Gemini models are rate-limited or exhausted.',
        public readonly int $retryAfterSeconds = 60,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, null, $previous);
    }
}
