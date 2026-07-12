<?php

namespace App\Http\Resources\Api\V1\Spotify;

use App\Models\Spotify\SpotifyPlaylist;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SpotifyPlaylist */
class SpotifyPlaylistResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->spotify_id,
            'name' => $this->name,
            'description' => $this->description,
            'public' => $this->public,
            'collaborative' => $this->collaborative,
            'is_owner' => $this->is_owner,
            'image_url' => $this->image_url,
            'snapshot_id' => $this->snapshot_id,
            'uri' => $this->uri,
            'item_count' => $this->item_count,
            'synced_at' => $this->synced_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', function () {
                return $this->items->map(fn ($item) => [
                    'position' => $item->position,
                    'added_at' => $item->added_at?->toIso8601String(),
                    'track' => $item->track ? new SpotifyTrackResource($item->track) : null,
                ]);
            }),
        ];
    }
}
