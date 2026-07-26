<?php

namespace App\Http\Resources\Api\V1\Beer;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Beer\BeerBeer */
class BeerBeerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'abv' => $this->abv,
            'ibu' => $this->ibu,
            'format' => $this->format,
            'rating' => $this->rating,
            'notes' => $this->notes,
            'media' => $this->mediaImagePayload(),
            'image_url' => $this->resolvedImageUrl(),
            'brewery' => $this->whenLoaded('brewery', fn () => $this->brewery ? [
                'id' => $this->brewery->id,
                'name' => $this->brewery->name,
                'city' => $this->brewery->city,
                'country' => $this->brewery->country,
                'source' => $this->brewery->source,
            ] : null),
            'style' => $this->whenLoaded('style', fn () => $this->style ? [
                'id' => $this->style->id,
                'slug' => $this->style->slug,
                'name' => $this->style->name,
                'family' => $this->style->family,
            ] : null),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
