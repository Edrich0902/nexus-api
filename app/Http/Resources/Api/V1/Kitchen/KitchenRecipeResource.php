<?php

namespace App\Http\Resources\Api\V1\Kitchen;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Kitchen\KitchenRecipe */
class KitchenRecipeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'rating' => $this->rating,
            'notes' => $this->notes,
            'cooked_count' => $this->cooked_count,
            'last_cooked_on' => $this->last_cooked_on?->toDateString(),
            'is_favourite' => $this->is_favourite,
            'meal' => $this->whenLoaded('meal', function () {
                if ($this->meal === null) {
                    return null;
                }

                return [
                    'id' => $this->meal->id,
                    'mealdb_id' => $this->meal->mealdb_id,
                    'name' => $this->meal->name,
                    'category' => $this->meal->category,
                    'area' => $this->meal->area,
                    'instructions' => $this->meal->instructions,
                    'thumb_url' => $this->meal->thumb_url,
                    'tags' => $this->meal->tags,
                    'youtube_url' => $this->meal->youtube_url,
                    'source_url' => $this->meal->source_url,
                    'ingredients' => $this->meal->relationLoaded('ingredients')
                        ? $this->meal->ingredients->map(fn ($i) => [
                            'id' => $i->id,
                            'name' => $i->name,
                            'measure' => $i->pivot->measure ?? null,
                            'position' => $i->pivot->position ?? null,
                        ])->values()->all()
                        : [],
                ];
            }),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
