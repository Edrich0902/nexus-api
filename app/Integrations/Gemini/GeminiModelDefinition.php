<?php

namespace App\Integrations\Gemini;

readonly class GeminiModelDefinition
{
    public function __construct(
        public string $id,
        public string $label,
        public int $rpm,
        public int $rpd,
        public bool $supportsVision,
        public int $priority,
    ) {}

    public function budgetProviderKey(): string
    {
        return 'gemini:'.$this->id;
    }
}
