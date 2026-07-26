<?php

namespace Database\Factories;

use App\Models\Kitchen\KitchenRecipe;
use App\Models\MealCatalog\MealCatalogMeal;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KitchenRecipe>
 */
class KitchenRecipeFactory extends Factory
{
    protected $model = KitchenRecipe::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'meal_catalog_meal_id' => MealCatalogMeal::factory(),
            'source' => KitchenRecipe::SOURCE_MEALDB,
            'rating' => fake()->randomElement([3.5, 4.0, 4.5, 5.0]),
            'notes' => fake()->optional()->sentence(),
            'cooked_count' => 0,
            'is_favourite' => false,
        ];
    }
}
