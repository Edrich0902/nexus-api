<?php

namespace App\Services\FoodDrink;

use App\Models\Beer\BeerBeer;
use App\Models\Cellar\CellarWine;
use App\Models\FoodDrink\FoodDrinkPairing;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Deterministic local recommender — zero upstream cost.
 */
class RecommendationService
{
    /**
     * @return list<array{recipe_id: int, drinkable_type: string, drinkable_id: int, score: float, reasons: list<string>, recipe_name: string|null, drink_name: string|null}>
     */
    public function suggest(User $user, int $limit = 10): array
    {
        $recipes = KitchenRecipe::query()
            ->where('user_id', $user->id)
            ->with(['meal.ingredients'])
            ->get();

        $wines = CellarWine::query()
            ->where('user_id', $user->id)
            ->get();

        $beers = BeerBeer::query()
            ->where('user_id', $user->id)
            ->with(['style'])
            ->get();

        $existing = FoodDrinkPairing::query()
            ->where('user_id', $user->id)
            ->get();

        $existingKeys = $existing->map(
            fn (FoodDrinkPairing $p) => $p->drinkable_type.'|'.$p->drinkable_id.'|'.$p->kitchen_recipe_id,
        )->all();

        $greatHistory = $existing->where('verdict', FoodDrinkPairing::VERDICT_GREAT);
        $poorHistory = $existing->where('verdict', FoodDrinkPairing::VERDICT_POOR);

        $suggestions = [];

        foreach ($recipes as $recipe) {
            foreach ($wines as $wine) {
                $key = CellarWine::class.'|'.$wine->id.'|'.$recipe->id;
                if (in_array($key, $existingKeys, true)) {
                    continue;
                }

                $scored = $this->scoreWineRecipe($wine, $recipe, $greatHistory, $poorHistory);
                if ($scored['score'] <= 0) {
                    continue;
                }

                $suggestions[] = [
                    'recipe_id' => $recipe->id,
                    'drinkable_type' => 'wine',
                    'drinkable_id' => $wine->id,
                    'score' => round($scored['score'], 3),
                    'reasons' => $scored['reasons'],
                    'recipe_name' => $recipe->meal?->name,
                    'drink_name' => $wine->name,
                ];
            }

            foreach ($beers as $beer) {
                $key = BeerBeer::class.'|'.$beer->id.'|'.$recipe->id;
                if (in_array($key, $existingKeys, true)) {
                    continue;
                }

                $scored = $this->scoreBeerRecipe($beer, $recipe, $greatHistory, $poorHistory);
                if ($scored['score'] <= 0) {
                    continue;
                }

                $suggestions[] = [
                    'recipe_id' => $recipe->id,
                    'drinkable_type' => 'beer',
                    'drinkable_id' => $beer->id,
                    'score' => round($scored['score'], 3),
                    'reasons' => $scored['reasons'],
                    'recipe_name' => $recipe->meal?->name,
                    'drink_name' => $beer->name,
                ];
            }
        }

        usort($suggestions, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($suggestions, 0, max(1, min(30, $limit)));
    }

    /**
     * @param  Collection<int, FoodDrinkPairing>|iterable<int, FoodDrinkPairing>  $greatHistory
     * @param  Collection<int, FoodDrinkPairing>|iterable<int, FoodDrinkPairing>  $poorHistory
     * @return array{score: float, reasons: list<string>}
     */
    public function scoreWineRecipe(
        CellarWine $wine,
        KitchenRecipe $recipe,
        iterable $greatHistory = [],
        iterable $poorHistory = [],
    ): array {
        $score = 0.0;
        $reasons = [];
        $meal = $recipe->meal;
        $category = mb_strtolower((string) ($meal?->category ?? ''));
        $area = (string) ($meal?->area ?? '');
        $mealName = mb_strtolower((string) ($meal?->name ?? ''));
        $ingredientNames = $meal?->ingredients?->pluck('name')->map(fn ($n) => mb_strtolower((string) $n))->all() ?? [];

        $ai = is_array($wine->ai_analysis) ? $wine->ai_analysis : [];
        $pairings = is_array(data_get($ai, 'narrative.food_pairings'))
            ? data_get($ai, 'narrative.food_pairings')
            : [];

        foreach ($pairings as $food) {
            $food = mb_strtolower((string) $food);
            if ($food === '') {
                continue;
            }
            if (
                ($category !== '' && str_contains($food, $category))
                || ($area !== '' && str_contains($food, mb_strtolower($area)))
                || ($mealName !== '' && str_contains($food, $mealName))
                || collect($ingredientNames)->contains(fn ($ing) => str_contains($food, $ing) || str_contains($ing, $food))
            ) {
                $score += 1.8;
                $reasons[] = "AI analysis pairs this wine with {$food}";
            }
        }

        $styleMap = config('food_drink.style_to_categories', []);
        $wineType = mb_strtolower((string) ($wine->wine_type ?? data_get($ai, 'identity.category') ?? ''));
        foreach ($styleMap as $style => $cats) {
            if ($wineType === '' || (! str_contains($wineType, (string) $style) && ! str_contains((string) $style, $wineType))) {
                continue;
            }
            foreach ($cats as $cat) {
                if ($category !== '' && mb_strtolower((string) $cat) === $category) {
                    $score += 1.0;
                    $reasons[] = "{$wineType} often suits {$cat} dishes";
                }
            }
        }

        $country = $wine->country ?? data_get($ai, 'identity.country');
        $cuisineMap = config('food_drink.cuisine_to_wine_countries', []);
        if ($area !== '' && $country && isset($cuisineMap[$area])) {
            foreach ($cuisineMap[$area] as $matchCountry) {
                if (strcasecmp((string) $country, (string) $matchCountry) === 0) {
                    $score += 0.8;
                    $reasons[] = "{$country} wines often suit {$area} cuisine";
                    break;
                }
            }
        }

        if ($wine->rating !== null) {
            $score += ((float) $wine->rating / 5.0) * 1.5;
            $reasons[] = 'Boosted by your wine rating';
        }

        foreach ($poorHistory as $hist) {
            if ($hist->drinkable_type !== CellarWine::class || (int) $hist->drinkable_id !== (int) $wine->id) {
                continue;
            }
            $histRecipe = KitchenRecipe::query()->with('meal')->find($hist->kitchen_recipe_id);
            if ($histRecipe && mb_strtolower((string) ($histRecipe->meal?->category ?? '')) === $category) {
                $score -= 2.0;
                $reasons[] = 'Penalised — you marked a similar pairing as poor';
            }
        }

        return ['score' => $score, 'reasons' => array_values(array_unique($reasons))];
    }

    /**
     * @param  iterable<FoodDrinkPairing>  $greatHistory
     * @param  iterable<FoodDrinkPairing>  $poorHistory
     * @return array{score: float, reasons: list<string>}
     */
    public function scoreBeerRecipe(
        BeerBeer $beer,
        KitchenRecipe $recipe,
        iterable $greatHistory = [],
        iterable $poorHistory = [],
    ): array {
        $score = 0.0;
        $reasons = [];
        $category = mb_strtolower((string) ($recipe->meal?->category ?? ''));
        $styleMap = config('food_drink.style_to_categories', []);
        $styleName = mb_strtolower((string) ($beer->style?->name ?? ''));

        $ai = is_array($beer->ai_analysis) ? $beer->ai_analysis : [];
        $pairings = is_array(data_get($ai, 'narrative.food_pairings'))
            ? data_get($ai, 'narrative.food_pairings')
            : [];
        foreach ($pairings as $food) {
            $food = mb_strtolower((string) $food);
            if ($food !== '' && $category !== '' && str_contains($food, $category)) {
                $score += 1.5;
                $reasons[] = "AI analysis pairs this beer with {$food}";
            }
        }

        foreach ($styleMap as $style => $cats) {
            if ($styleName === '' || (! str_contains($styleName, (string) $style) && ! str_contains((string) $style, $styleName))) {
                continue;
            }
            foreach ($cats as $cat) {
                if ($category !== '' && mb_strtolower((string) $cat) === $category) {
                    $score += 1.2;
                    $reasons[] = "{$beer->style?->name} often suits {$cat} dishes";
                }
            }
        }

        if ($beer->rating !== null) {
            $score += ((float) $beer->rating / 5.0) * 1.5;
            $reasons[] = 'Boosted by your beer rating';
        }

        foreach ($poorHistory as $hist) {
            if ($hist->drinkable_type === BeerBeer::class && (int) $hist->drinkable_id === (int) $beer->id) {
                $score -= 1.5;
                $reasons[] = 'Penalised by a prior poor pairing with this beer';
            }
        }

        return ['score' => $score, 'reasons' => array_values(array_unique($reasons))];
    }
}
