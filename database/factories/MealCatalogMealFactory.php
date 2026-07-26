<?php

namespace Database\Factories;

use App\Models\MealCatalog\MealCatalogMeal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealCatalogMeal>
 */
class MealCatalogMealFactory extends Factory
{
    protected $model = MealCatalogMeal::class;

    public function definition(): array
    {
        return [
            'mealdb_id' => (string) fake()->unique()->numberBetween(50000, 60000),
            'name' => fake()->words(3, true),
            'category' => 'Seafood',
            'area' => 'Italian',
            'instructions' => fake()->paragraphs(2, true),
            'thumb_url' => fake()->imageUrl(),
            'tags' => ['Pasta'],
        ];
    }
}
