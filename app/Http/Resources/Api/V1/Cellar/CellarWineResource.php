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
            'tastings_count' => $this->when(isset($this->tastings_count), $this->tastings_count),
            'catalog' => $this->whenLoaded('catalogWine', function () {
                if ($this->catalogWine === null) {
                    return null;
                }

                return [
                    'id' => $this->catalogWine->id,
                    'wineapi_id' => $this->catalogWine->wineapi_id,
                    'name' => $this->catalogWine->name,
                    'vintage' => $this->catalogWine->vintage,
                    'type' => $this->catalogWine->type,
                    'body' => $this->catalogWine->body,
                    'acidity' => $this->catalogWine->acidity,
                    'description' => $this->catalogWine->description,
                    'image_url' => $this->catalogWine->image_url,
                    'average_rating' => $this->catalogWine->average_rating,
                    'enrichment_status' => $this->catalogWine->enrichment_status,
                    'enriched_at' => $this->catalogWine->enriched_at?->toIso8601String(),
                    'winery' => $this->catalogWine->relationLoaded('winery') && $this->catalogWine->winery
                        ? ['id' => $this->catalogWine->winery->id, 'name' => $this->catalogWine->winery->name]
                        : null,
                    'region' => $this->catalogWine->relationLoaded('region') && $this->catalogWine->region
                        ? [
                            'id' => $this->catalogWine->region->id,
                            'name' => $this->catalogWine->region->name,
                            'country' => $this->catalogWine->region->country,
                        ]
                        : null,
                    'grapes' => $this->catalogWine->relationLoaded('grapes')
                        ? $this->catalogWine->grapes->map(fn ($g) => [
                            'id' => $g->id,
                            'name' => $g->name,
                            'color' => $g->color,
                        ])->values()->all()
                        : [],
                    'scores' => $this->catalogWine->relationLoaded('scores')
                        ? $this->catalogWine->scores->map(fn ($s) => [
                            'score' => $s->score,
                            'score_text' => $s->score_text,
                            'reviewer' => $s->reviewer,
                            'review_date' => $s->review_date?->toDateString(),
                        ])->values()->all()
                        : [],
                    'prices' => $this->catalogWine->relationLoaded('prices')
                        ? $this->catalogWine->prices->map(fn ($p) => [
                            'merchant_name' => $p->merchant_name,
                            'price' => $p->price,
                            'currency' => $p->currency,
                            'url' => $p->url,
                        ])->values()->all()
                        : [],
                    'pairings' => $this->catalogWine->relationLoaded('pairings')
                        ? $this->catalogWine->pairings->map(fn ($p) => [
                            'food' => $p->food,
                            'confidence' => $p->confidence,
                            'notes' => $p->notes,
                        ])->values()->all()
                        : [],
                ];
            }),
            'tastings' => CellarWineTastingResource::collection($this->whenLoaded('tastings')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
