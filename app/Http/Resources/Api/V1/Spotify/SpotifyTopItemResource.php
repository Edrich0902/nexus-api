<?php

namespace App\Http\Resources\Api\V1\Spotify;

use App\Models\Spotify\SpotifyTopItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpotifyTopItem */
class SpotifyTopItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type,
            'time_range' => $this->time_range,
            'rank' => $this->rank,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'artist' => new SpotifyArtistResource($this->whenLoaded('artist')),
            'track' => new SpotifyTrackResource($this->whenLoaded('track')),
        ];
    }
}
