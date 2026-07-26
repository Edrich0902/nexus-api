<?php

namespace App\Services\Kitchen;

use App\Integrations\MealDb\MealDbIntegration;
use App\Models\MealCatalog\MealCatalogArea;
use App\Models\MealCatalog\MealCatalogCategory;
use App\Models\MealCatalog\MealCatalogMeal;
use Illuminate\Support\Facades\Cache;

class MealCatalogService
{
    public function __construct(
        private readonly MealDbIntegration $mealDb,
        private readonly RecipeImportService $importer,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $cacheKey = 'mealdb:search:'.md5(mb_strtolower($query));
        $ttl = $this->ttl();

        $rows = Cache::remember($cacheKey, $ttl, function () use ($query) {
            return $this->mealDb->searchByName($query);
        });

        return array_map(fn (array $row) => $this->summarize($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function browse(?string $category = null, ?string $area = null, ?string $ingredient = null): array
    {
        $key = 'mealdb:browse:'.md5(json_encode([$category, $area, $ingredient]));

        $rows = Cache::remember($key, $this->ttl(), function () use ($category, $area, $ingredient) {
            if ($category) {
                return $this->mealDb->filterByCategory($category);
            }
            if ($area) {
                return $this->mealDb->filterByArea($area);
            }
            if ($ingredient) {
                return $this->mealDb->filterByIngredient($ingredient);
            }

            return $this->mealDb->searchByName('chicken');
        });

        return array_map(fn (array $row) => $this->summarize($row), $rows);
    }

    public function importByMealdbId(string $mealdbId): MealCatalogMeal
    {
        $existing = MealCatalogMeal::query()
            ->with('ingredients')
            ->where('mealdb_id', $mealdbId)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $payload = $this->mealDb->lookup($mealdbId);
        if ($payload === null) {
            abort(404, 'Meal not found upstream.');
        }

        return $this->importer->upsertMeal($payload);
    }

    public function random(): MealCatalogMeal
    {
        $payload = $this->mealDb->random();
        if ($payload === null) {
            abort(404, 'No random meal available.');
        }

        return $this->importer->upsertMeal($payload);
    }

    /**
     * @return array{categories: list<string>, areas: list<string>}
     */
    public function filters(): array
    {
        return Cache::remember('mealdb:filters', $this->ttl(), function () {
            $categories = $this->mealDb->listCategories();
            foreach ($categories as $row) {
                if (empty($row['strCategory'])) {
                    continue;
                }
                MealCatalogCategory::query()->updateOrCreate(
                    ['name' => (string) $row['strCategory']],
                    [
                        'thumb_url' => $row['strCategoryThumb'] ?? null,
                        'description' => $row['strCategoryDescription'] ?? null,
                    ],
                );
            }

            $areas = $this->mealDb->listAreas();
            foreach ($areas as $row) {
                if (empty($row['strArea'])) {
                    continue;
                }
                MealCatalogArea::query()->firstOrCreate([
                    'name' => (string) $row['strArea'],
                ]);
            }

            return [
                'categories' => MealCatalogCategory::query()->orderBy('name')->pluck('name')->all(),
                'areas' => MealCatalogArea::query()->orderBy('name')->pluck('name')->all(),
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{mealdb_id: string, name: string|null, thumb_url: string|null, category: string|null, area: string|null}
     */
    private function summarize(array $row): array
    {
        return [
            'mealdb_id' => (string) ($row['idMeal'] ?? ''),
            'name' => $row['strMeal'] ?? null,
            'thumb_url' => $row['strMealThumb'] ?? null,
            'category' => $row['strCategory'] ?? null,
            'area' => $row['strArea'] ?? null,
        ];
    }

    private function ttl(): int
    {
        return max(60, (int) config('services.mealdb.cache_seconds', 86400));
    }
}
