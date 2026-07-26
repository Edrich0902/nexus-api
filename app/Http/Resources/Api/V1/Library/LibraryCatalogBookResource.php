<?php

namespace App\Http\Resources\Api\V1\Library;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LibraryCatalog\LibraryCatalogBook */
class LibraryCatalogBookResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'ol_work_key' => $this->ol_work_key,
            'ol_edition_key' => $this->ol_edition_key,
            'title' => $this->title,
            'authors' => $this->authors,
            'authors_label' => $this->authorsLabel(),
            'isbn_10' => $this->isbn_10,
            'isbn_13' => $this->isbn_13,
            'publish_year' => $this->publish_year,
            'page_count' => $this->page_count,
            'description' => $this->description,
            'cover_url' => $this->resolvedImageUrl('cover_url'),
            'media' => $this->mediaImagePayload(),
        ];
    }
}
