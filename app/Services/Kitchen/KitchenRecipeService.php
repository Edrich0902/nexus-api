<?php

namespace App\Services\Kitchen;

use App\Models\Kitchen\KitchenRecipe;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\FoodDrink\FoodDrinkDashboardService;
use App\Services\Media\MediaMirrorService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class KitchenRecipeService
{
    public function __construct(
        private readonly MealCatalogService $catalog,
        private readonly MediaMirrorService $mediaMirrors,
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  array{q?: string, favourite?: bool, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, KitchenRecipe>
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        $query = KitchenRecipe::query()
            ->where('user_id', $user->id)
            ->with(['meal.ingredients'])
            ->orderByDesc('updated_at');

        if (! empty($filters['favourite'])) {
            $query->where('is_favourite', true);
        }

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->whereHas('meal', fn ($q) => $q->where('name', 'like', $term));
        }

        return $query->paginate($perPage);
    }

    public function saveFromMealdb(User $user, string $mealdbId, ?float $rating = null, ?string $notes = null): KitchenRecipe
    {
        $this->assertValidRating($rating);
        $meal = $this->catalog->importByMealdbId($mealdbId);

        $recipe = KitchenRecipe::query()->firstOrNew([
            'user_id' => $user->id,
            'meal_catalog_meal_id' => $meal->id,
        ]);

        $recipe->source = KitchenRecipe::SOURCE_MEALDB;
        if ($rating !== null) {
            $recipe->rating = $rating;
        }
        if ($notes !== null) {
            $recipe->notes = $notes;
        }
        $recipe->save();
        $isNew = $recipe->wasRecentlyCreated;

        $recipe = $recipe->fresh(['meal.ingredients']) ?? $recipe;
        if ($isNew) {
            $this->activity->record(
                $user->id,
                'kitchen',
                'recipe.saved',
                $meal->name,
                collect([$meal->area, $meal->category])->filter()->implode(' · ') ?: null,
                $recipe,
            );
        }
        if ($recipe->meal !== null && is_string($recipe->meal->thumb_url) && $recipe->meal->thumb_url !== '') {
            $this->mediaMirrors->queueMealdbMirror(
                $recipe,
                $recipe->meal->thumb_url,
                (string) $recipe->meal->mealdb_id,
                $user,
            );
        }

        FoodDrinkDashboardService::forget($user);

        return $recipe;
    }

    public function findOwned(User $user, int $recipeId): KitchenRecipe
    {
        $recipe = KitchenRecipe::query()
            ->where('user_id', $user->id)
            ->with(['meal.ingredients'])
            ->find($recipeId);

        if ($recipe === null) {
            abort(404, 'Recipe not found.');
        }

        return $recipe;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, KitchenRecipe $recipe, array $data): KitchenRecipe
    {
        $this->assertOwned($user, $recipe);
        $this->assertValidRating($data['rating'] ?? null);

        $recipe->fill(array_intersect_key($data, array_flip([
            'rating',
            'notes',
            'is_favourite',
        ])));
        $recipe->save();
        FoodDrinkDashboardService::forget($user);

        return $recipe->fresh(['meal.ingredients']) ?? $recipe;
    }

    public function markCooked(User $user, KitchenRecipe $recipe): KitchenRecipe
    {
        $this->assertOwned($user, $recipe);
        $recipe->cooked_count = (int) $recipe->cooked_count + 1;
        $recipe->last_cooked_on = now()->toDateString();
        $recipe->save();
        FoodDrinkDashboardService::forget($user);

        $recipe = $recipe->fresh(['meal.ingredients']) ?? $recipe;
        $this->activity->record(
            $user->id,
            'kitchen',
            'recipe.cooked',
            (string) ($recipe->meal?->name ?? 'Recipe'),
            null,
            $recipe,
            ['cooked_count' => (int) $recipe->cooked_count],
        );

        return $recipe;
    }

    public function delete(User $user, KitchenRecipe $recipe): void
    {
        $this->assertOwned($user, $recipe);
        $recipe->delete();
        FoodDrinkDashboardService::forget($user);
    }

    public function assertOwned(User $user, KitchenRecipe $recipe): void
    {
        if ((int) $recipe->user_id !== (int) $user->id) {
            abort(404, 'Recipe not found.');
        }
    }

    private function assertValidRating(mixed $rating): void
    {
        if ($rating === null) {
            return;
        }

        $value = (float) $rating;
        if ($value < 0.5 || $value > 5.0) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must be between 0.5 and 5.0.'],
            ]);
        }

        if (abs(($value * 2) - round($value * 2)) > 0.001) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must use half-step increments (e.g. 3.5, 4.0).'],
            ]);
        }
    }
}
