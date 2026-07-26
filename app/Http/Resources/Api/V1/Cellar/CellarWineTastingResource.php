<?php

namespace App\Http\Resources\Api\V1\Cellar;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Cellar\CellarWineTasting */
class CellarWineTastingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cellar_wine_id' => $this->cellar_wine_id,
            'tasted_on' => $this->tasted_on?->toDateString(),
            'rating' => $this->rating,
            'notes' => $this->notes,
            'occasion' => $this->occasion,
            'location' => $this->location,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
