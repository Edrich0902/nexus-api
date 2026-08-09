<?php

namespace App\Integrations\Gemini;

readonly class GeminiGenerationResult
{
    public function __construct(
        public string $text,
        public string $modelId,
    ) {}
}
