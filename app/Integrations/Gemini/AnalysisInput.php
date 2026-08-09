<?php

namespace App\Integrations\Gemini;

readonly class AnalysisInput
{
    /**
     * @param  array<string, mixed>  $fields  Manual record fields
     * @param  ?string  $imageUrl  Public HTTPS URL Gemini fetches server-side (never base64)
     */
    public function __construct(
        public array $fields,
        public ?string $extraContext = null,
        public ?string $imageUrl = null,
    ) {}

    public function hasImage(): bool
    {
        return is_string($this->imageUrl)
            && $this->imageUrl !== ''
            && filter_var($this->imageUrl, FILTER_VALIDATE_URL) !== false;
    }
}
