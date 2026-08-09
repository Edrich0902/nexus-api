<?php

namespace App\Http\Resources\Api\V1\Spirits;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Spirit\SpiritSpirit */
class SpiritResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'producer' => $this->producer,
            'category' => $this->category,
            'age_statement' => $this->age_statement,
            'abv' => $this->abv,
            'region' => $this->region,
            'country' => $this->country,
            'rating' => $this->rating,
            'notes' => $this->notes,
            'media' => $this->mediaImagePayload(),
            'image_url' => $this->resolvedImageUrl(),
            'analysis_status' => $this->analysis_status ?? 'none',
            'analysed_at' => $this->analysed_at?->toIso8601String(),
            'analysis_model' => $this->analysis_model,
            'analysis_prompt_version' => $this->analysis_prompt_version,
            'analysis_error' => $this->analysis_error,
            'ai_analysis' => $this->ai_analysis,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
