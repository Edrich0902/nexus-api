<?php

namespace App\Http\Resources\Api\V1\Spotify;

use App\Models\Spotify\SpotifyTrack;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpotifyTrack */
class SpotifyTrackResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->spotify_id,
            'name' => $this->name,
            'uri' => $this->uri,
            'duration_ms' => $this->duration_ms,
            'explicit' => $this->explicit,
            'album_name' => $this->album_name,
            'album_image_url' => $this->album_image_url,
            'artists' => $this->artists ?? [],
            'external_url' => $this->external_url,
        ];
    }
}
