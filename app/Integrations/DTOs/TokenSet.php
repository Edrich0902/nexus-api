<?php

namespace App\Integrations\DTOs;

use Carbon\CarbonInterface;

readonly class TokenSet
{
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public CarbonInterface $expiresAt,
        public string $scopes = '',
    ) {}
}
