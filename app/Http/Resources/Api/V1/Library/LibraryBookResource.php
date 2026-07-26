<?php

namespace App\Http\Resources\Api\V1\Library;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Library\LibraryBook */
class LibraryBookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'authors' => $this->authors,
            'isbn' => $this->isbn,
            'status' => $this->status,
            'rating' => $this->rating,
            'notes' => $this->notes,
            'started_at' => $this->started_at?->toDateString(),
            'finished_at' => $this->finished_at?->toDateString(),
            'match_status' => $this->match_status,
            'media' => $this->mediaImagePayload(),
            'image_url' => $this->resolvedImageUrl()
                ?? ($this->relationLoaded('catalogBook')
                    ? $this->catalogBook?->resolvedImageUrl('cover_url')
                    : null),
            'catalog' => $this->whenLoaded(
                'catalogBook',
                fn () => $this->catalogBook
                    ? new LibraryCatalogBookResource($this->catalogBook)
                    : null,
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
