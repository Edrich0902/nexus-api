<?php

namespace App\Http\Resources\Api\V1\Cellar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Cellar\CellarWine */
class CellarWineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'producer_name' => $this->producer_name,
            'name' => $this->name,
            'vintage' => $this->vintage,
            'wine_type' => $this->wine_type,
            'region_name' => $this->region_name,
            'country' => $this->country,
            'rating' => $this->rating,
            'notes' => $this->notes,
            'match_status' => $this->match_status,
            'media' => $this->mediaImagePayload(),
            'image_url' => $this->resolvedImageUrl()
                ?? ($this->relationLoaded('catalogWine')
                    ? $this->catalogWine?->resolvedImageUrl('image_url')
                    : null),
            'analysis_status' => $this->analysis_status ?? 'none',
            'analysed_at' => $this->analysed_at?->toIso8601String(),
            'analysis_model' => $this->analysis_model,
            'analysis_prompt_version' => $this->analysis_prompt_version,
            'analysis_error' => $this->analysis_error,
            'ai_analysis' => $this->ai_analysis,
            'tastings_count' => $this->when(isset($this->tastings_count), $this->tastings_count),
            'catalog' => null,
            'tastings' => CellarWineTastingResource::collection($this->whenLoaded('tastings')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
