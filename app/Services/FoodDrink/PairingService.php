<?php

namespace App\Services\FoodDrink;

use App\Models\Beer\BeerBeer;
use App\Models\Cellar\CellarWine;
use App\Models\FoodDrink\FoodDrinkPairing;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PairingService
{
    /**
     * @return Collection<int, FoodDrinkPairing>
     */
    public function list(User $user): Collection
    {
        return FoodDrinkPairing::query()
            ->where('user_id', $user->id)
            ->with(['recipe.meal', 'drinkable'])
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): FoodDrinkPairing
    {
        $drinkable = $this->resolveDrinkable($user, (string) $data['drinkable_type'], (int) $data['drinkable_id']);
        $recipe = KitchenRecipe::query()
            ->where('user_id', $user->id)
            ->find($data['kitchen_recipe_id']);

        if ($recipe === null) {
            abort(404, 'Recipe not found.');
        }

        $verdict = (string) ($data['verdict'] ?? FoodDrinkPairing::VERDICT_GOOD);
        if (! in_array($verdict, [
            FoodDrinkPairing::VERDICT_GREAT,
            FoodDrinkPairing::VERDICT_GOOD,
            FoodDrinkPairing::VERDICT_POOR,
        ], true)) {
            throw ValidationException::withMessages([
                'verdict' => ['Verdict must be great, good, or poor.'],
            ]);
        }

        return FoodDrinkPairing::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'drinkable_type' => $drinkable::class,
                'drinkable_id' => $drinkable->id,
                'kitchen_recipe_id' => $recipe->id,
            ],
            [
                'verdict' => $verdict,
                'notes' => $data['notes'] ?? null,
                'source' => FoodDrinkPairing::SOURCE_MANUAL,
            ],
        )->load(['recipe.meal', 'drinkable']);
    }

    public function delete(User $user, int $pairingId): void
    {
        $pairing = FoodDrinkPairing::query()
            ->where('user_id', $user->id)
            ->find($pairingId);

        if ($pairing === null) {
            abort(404, 'Pairing not found.');
        }

        $pairing->delete();
    }

    private function resolveDrinkable(User $user, string $type, int $id): CellarWine|BeerBeer
    {
        $normalized = strtolower($type);

        if (in_array($normalized, ['wine', 'cellar_wine', CellarWine::class], true)) {
            $wine = CellarWine::query()->where('user_id', $user->id)->find($id);
            if ($wine === null) {
                abort(404, 'Wine not found.');
            }

            return $wine;
        }

        if (in_array($normalized, ['beer', 'beer_beer', BeerBeer::class], true)) {
            $beer = BeerBeer::query()->where('user_id', $user->id)->find($id);
            if ($beer === null) {
                abort(404, 'Beer not found.');
            }

            return $beer;
        }

        throw ValidationException::withMessages([
            'drinkable_type' => ['Drinkable type must be wine or beer.'],
        ]);
    }
}
