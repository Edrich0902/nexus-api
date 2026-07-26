<?php

namespace App\Http\Resources\Api\V1\Media;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Media\MediaAsset */
class MediaAssetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'asset_id' => $this->asset_id,
            'folder' => $this->folder,
            'collection' => $this->collection,
            'url' => $this->secure_url,
            'secure_url' => $this->secure_url,
            'format' => $this->format,
            'resource_type' => $this->resource_type,
            'bytes' => $this->bytes,
            'width' => $this->width,
            'height' => $this->height,
            'version' => $this->version,
            'alt_text' => $this->alt_text,
            'source' => $this->source,
            'source_provider' => $this->source_provider,
            'source_ref' => $this->source_ref,
            'source_meta' => $this->source_meta,
            'tags' => $this->tags ?? [],
            'attachments_count' => $this->when(isset($this->attachments_count), $this->attachments_count),
            'attachments' => $this->whenLoaded('attachments', function () {
                return $this->attachments->map(fn ($row) => [
                    'type' => $row->mediable_type,
                    'id' => $row->mediable_id,
                    'role' => $row->role,
                    'position' => $row->position,
                ])->values()->all();
            }),
            'media' => $this->toImagePayload(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
