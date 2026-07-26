<?php

namespace App\Services\Kitchen;

use App\Models\MealCatalog\MealCatalogIngredient;
use App\Models\MealCatalog\MealCatalogMeal;
use Illuminate\Support\Facades\DB;

class RecipeImportService
{
    /**
     * Upsert a TheMealDB meal payload into the local catalog.
     *
     * @param  array<string, mixed>  $payload
     */
    public function upsertMeal(array $payload): MealCatalogMeal
    {
        $mealdbId = (string) ($payload['idMeal'] ?? '');
        if ($mealdbId === '') {
            abort(422, 'Meal payload is missing idMeal.');
        }

        return DB::transaction(function () use ($payload, $mealdbId) {
            $tags = null;
            if (! empty($payload['strTags']) && is_string($payload['strTags'])) {
                $tags = array_values(array_filter(array_map('trim', explode(',', $payload['strTags']))));
            }

            $meal = MealCatalogMeal::query()->updateOrCreate(
                ['mealdb_id' => $mealdbId],
                [
                    'name' => (string) ($payload['strMeal'] ?? 'Untitled meal'),
                    'category' => $payload['strCategory'] ?? null,
                    'area' => $payload['strArea'] ?? null,
                    'instructions' => $payload['strInstructions'] ?? null,
                    'thumb_url' => $payload['strMealThumb'] ?? null,
                    'tags' => $tags,
                    'youtube_url' => $payload['strYoutube'] ?? null,
                    'source_url' => $payload['strSource'] ?? null,
                    'raw' => $payload,
                ],
            );

            $sync = [];
            foreach ($this->flattenIngredients($payload) as $index => $row) {
                $ingredient = MealCatalogIngredient::query()->firstOrCreate(
                    ['name' => $row['name']],
                );
                $sync[$ingredient->id] = [
                    'measure' => $row['measure'],
                    'position' => $index + 1,
                ];
            }

            // Sync with position uniqueness — detach then attach to keep order clean.
            $meal->ingredients()->detach();
            foreach ($sync as $ingredientId => $pivot) {
                $meal->ingredients()->attach($ingredientId, $pivot);
            }

            return $meal->fresh(['ingredients']) ?? $meal;
        });
    }

    /**
     * Flatten strIngredient1..20 / strMeasure1..20 into structured rows.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{name: string, measure: string|null}>
     */
    public function flattenIngredients(array $payload): array
    {
        $rows = [];
        for ($i = 1; $i <= 20; $i++) {
            $name = trim((string) ($payload["strIngredient{$i}"] ?? ''));
            if ($name === '') {
                continue;
            }
            $measure = trim((string) ($payload["strMeasure{$i}"] ?? ''));
            $rows[] = [
                'name' => $name,
                'measure' => $measure !== '' ? $measure : null,
            ];
        }

        return $rows;
    }
}
