<?php

namespace App\Services\FoodDrink;

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

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        $cacheKey = "food-drink:dashboard:{$user->id}";

        return Cache::remember($cacheKey, 60, function () use ($user) {
            $wines = CellarWine::query()->where('user_id', $user->id);
            $beers = BeerBeer::query()->where('user_id', $user->id);
            $recipes = KitchenRecipe::query()->where('user_id', $user->id);

            return [
                'counts' => [
                    'wines' => (clone $wines)->count(),
                    'beers' => (clone $beers)->count(),
                    'recipes' => (clone $recipes)->count(),
                    'pairings' => FoodDrinkPairing::query()->where('user_id', $user->id)->count(),
                ],
                'recent_wines' => (clone $wines)->orderByDesc('created_at')->limit(5)->get([
                    'id', 'name', 'producer_name', 'vintage', 'rating', 'match_status',
                ]),
                'recent_beers' => (clone $beers)->with('brewery')->orderByDesc('created_at')->limit(5)->get(),
                'top_recipes' => (clone $recipes)->with('meal')
                    ->orderByDesc('rating')
                    ->orderByDesc('cooked_count')
                    ->limit(5)
                    ->get(),
                'quota' => $this->wineApi->quotaSnapshot(),
                'suggestions' => $this->recommendations->suggest($user, 5),
                'recent_pairings' => $this->pairings->list($user)->take(5)->values(),
            ];
        });
    }
}
