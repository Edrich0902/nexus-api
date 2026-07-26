<?php

namespace App\Http\Resources\Api\V1\FoodDrink;

use App\Models\Beer\BeerBeer;
use App\Models\Cellar\CellarWine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\FoodDrink\FoodDrinkPairing */
class FoodDrinkPairingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $drinkableType = 'unknown';
        if ($this->drinkable_type === CellarWine::class || $this->drinkable_type === 'cellar_wine') {
            $drinkableType = 'wine';
        } elseif ($this->drinkable_type === BeerBeer::class || $this->drinkable_type === 'beer_beer') {
            $drinkableType = 'beer';
        }

        return [
            'id' => $this->id,
            'drinkable_type' => $drinkableType,
            'drinkable_id' => $this->drinkable_id,
            'drinkable_name' => $this->whenLoaded('drinkable', fn () => $this->drinkable?->name),
            'kitchen_recipe_id' => $this->kitchen_recipe_id,
            'recipe_name' => $this->whenLoaded('recipe', fn () => $this->recipe?->meal?->name),
            'verdict' => $this->verdict,
            'notes' => $this->notes,
            'source' => $this->source,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
