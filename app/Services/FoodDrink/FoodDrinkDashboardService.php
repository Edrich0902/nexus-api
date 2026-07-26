<?php

namespace App\Services\FoodDrink;

use App\Http\Resources\Api\V1\FoodDrink\FoodDrinkPairingResource;
use App\Integrations\WineApi\WineApiIntegration;
use App\Models\Beer\BeerBeer;
use App\Models\Cellar\CellarWine;
use App\Models\FoodDrink\FoodDrinkPairing;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class FoodDrinkDashboardService
{
    public function __construct(
        private readonly WineApiIntegration $wineApi,
        private readonly RecommendationService $recommendations,
        private readonly PairingService $pairings,
    ) {}

    public static function cacheKey(User|int $user): string
    {
        $id = $user instanceof User ? $user->id : $user;

        return "food-drink:dashboard:{$id}";
    }

    public static function forget(User|int $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        // Cache plain arrays only — file cache cannot safely unserialize Eloquent
        // collections (they become __PHP_Incomplete_Class and JSON as empty objects).
        return Cache::remember(self::cacheKey($user), 60, function () use ($user) {
            $wines = CellarWine::query()->where('user_id', $user->id);
            $beers = BeerBeer::query()->where('user_id', $user->id);
            $recipes = KitchenRecipe::query()->where('user_id', $user->id);

            $recentWines = (clone $wines)
                ->with('catalogWine')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn (CellarWine $wine) => [
                    'id' => $wine->id,
                    'name' => $wine->name,
                    'producer_name' => $wine->producer_name,
                    'vintage' => $wine->vintage,
                    'rating' => $wine->rating,
                    'match_status' => $wine->match_status,
                    'media' => $wine->mediaImagePayload()
                        ?? $wine->catalogWine?->mediaImagePayload(),
                    'image_url' => $wine->resolvedImageUrl()
                        ?? $wine->catalogWine?->resolvedImageUrl('image_url'),
                ])
                ->values()
                ->all();

            $recentBeers = (clone $beers)
                ->with('brewery')
                ->orderByDesc('created_at')
                ->limit(5)
                ->get()
                ->map(fn (BeerBeer $beer) => [
                    'id' => $beer->id,
                    'name' => $beer->name,
                    'rating' => $beer->rating,
                    'brewery' => $beer->brewery
                        ? ['id' => $beer->brewery->id, 'name' => $beer->brewery->name]
                        : null,
                    'media' => $beer->mediaImagePayload(),
                    'image_url' => $beer->resolvedImageUrl(),
                ])
                ->values()
                ->all();

            $topRecipes = (clone $recipes)
                ->with('meal')
                ->orderByDesc('rating')
                ->orderByDesc('cooked_count')
                ->limit(5)
                ->get()
                ->map(fn (KitchenRecipe $recipe) => [
                    'id' => $recipe->id,
                    'rating' => $recipe->rating,
                    'cooked_count' => $recipe->cooked_count,
                    'meal' => $recipe->meal
                        ? [
                            'name' => $recipe->meal->name,
                            'thumb_url' => $recipe->meal->thumb_url,
                        ]
                        : null,
                    'media' => $recipe->mediaImagePayload(),
                    'image_url' => $recipe->resolvedImageUrl()
                        ?? (is_string($recipe->meal?->thumb_url) ? $recipe->meal->thumb_url : null),
                ])
                ->values()
                ->all();

            return [
                'counts' => [
                    'wines' => (clone $wines)->count(),
                    'beers' => (clone $beers)->count(),
                    'recipes' => (clone $recipes)->count(),
                    'pairings' => FoodDrinkPairing::query()->where('user_id', $user->id)->count(),
                ],
                'recent_wines' => $recentWines,
                'recent_beers' => $recentBeers,
                'top_recipes' => $topRecipes,
                'quota' => $this->wineApi->quotaSnapshot(),
                'suggestions' => $this->recommendations->suggest($user, 5),
                'recent_pairings' => FoodDrinkPairingResource::collection(
                    $this->pairings->list($user)->take(5)->values(),
                )->resolve(),
            ];
        });
    }
}
