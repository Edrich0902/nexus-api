<?php

namespace App\Http\Resources\Api\V1;

use App\Models\PersonalAccessToken;
use App\Services\Auth\AuthService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PersonalAccessToken
 */
class AccessTokenResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currentToken = $request->user()?->currentAccessToken();
        $isCurrent = $currentToken instanceof PersonalAccessToken
            && (int) $currentToken->id === (int) $this->id;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'device' => [
                'name' => $this->name,
                'ip_address' => $this->ip_address,
                'user_agent' => $this->user_agent,
            ],
            'remember' => in_array(AuthService::REMEMBER_ABILITY, $this->abilities ?? [], true),
            'last_used_at' => $this->last_used_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'is_current' => $isCurrent,
        ];
    }
}
