<?php

namespace App\Http\Resources\Api\V1\Spotify;

use App\Models\Spotify\SpotifyRecentlyPlayed;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpotifyRecentlyPlayed */
class SpotifyRecentlyPlayedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'played_at' => $this->played_at?->toIso8601String(),
            'context_uri' => $this->context_uri,
            'context_type' => $this->context_type,
            'track' => new SpotifyTrackResource($this->whenLoaded('track')),
        ];
    }
}
