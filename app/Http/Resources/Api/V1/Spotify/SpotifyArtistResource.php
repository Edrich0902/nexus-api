<?php

namespace App\Http\Resources\Api\V1\Spotify;

use App\Models\Spotify\SpotifyArtist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpotifyArtist */
class SpotifyArtistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->spotify_id,
            'name' => $this->name,
            'genres' => $this->genres ?? [],
            'images' => $this->images ?? [],
            'external_url' => $this->external_url,
        ];
    }
}
