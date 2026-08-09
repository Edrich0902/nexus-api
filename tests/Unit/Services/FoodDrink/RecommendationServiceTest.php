<?php

namespace Tests\Unit\Services\FoodDrink;

use App\Models\Cellar\CellarWine;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\MealCatalog\MealCatalogMeal;
use App\Models\User;
use App\Services\FoodDrink\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wine_recipe_scorer_boosts_ai_pairing_and_rating(): void
    {
        $user = User::factory()->create();

        $wine = CellarWine::factory()->create([
            'user_id' => $user->id,
            'rating' => 5.0,
            'ai_analysis' => [
                'schema_version' => 1,
                'domain' => 'wine',
                'identity' => ['category' => 'red'],
                'narrative' => ['food_pairings' => ['Beef']],
            ],
        ]);

        $meal = MealCatalogMeal::factory()->create([
            'name' => 'Steak Pie',
            'category' => 'Beef',
            'area' => 'British',
        ]);
        $recipe = KitchenRecipe::factory()->create([
            'user_id' => $user->id,
            'meal_catalog_meal_id' => $meal->id,
        ]);
        $recipe->load(['meal.ingredients']);

        $service = app(RecommendationService::class);
        $result = $service->scoreWineRecipe($wine, $recipe);

        $this->assertGreaterThan(2.0, $result['score']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertTrue(
            collect($result['reasons'])->contains(fn ($r) => str_contains($r, 'AI analysis') || str_contains($r, 'Beef')),
        );
    }
}
