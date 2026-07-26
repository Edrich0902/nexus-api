<?php

namespace Tests\Feature\Api\V1\FoodDrink;

use App\Models\Cellar\CellarWine;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\MealCatalog\MealCatalogMeal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FoodDrinkApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wineapi.daily_limit' => 100,
            'services.wineapi.reserve_for_enrichment' => 20,
            'services.wineapi.budget_timezone' => 'UTC',
            'services.wineapi.api_key' => 'test',
        ]);
    }

    public function test_dashboard_and_pairing_lifecycle(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wine = CellarWine::factory()->create(['user_id' => $user->id, 'rating' => 4.5]);
        $meal = MealCatalogMeal::factory()->create(['category' => 'Beef']);
        $recipe = KitchenRecipe::factory()->create([
            'user_id' => $user->id,
            'meal_catalog_meal_id' => $meal->id,
            'rating' => 4.0,
        ]);

        $this->getJson('/api/v1/food-drink/dashboard')
            ->assertOk()
            ->assertJsonPath('counts.wines', 1)
            ->assertJsonPath('counts.recipes', 1);

        $pairingId = $this->postJson('/api/v1/food-drink/pairings', [
            'drinkable_type' => 'wine',
            'drinkable_id' => $wine->id,
            'kitchen_recipe_id' => $recipe->id,
            'verdict' => 'great',
            'notes' => 'Perfect with steak',
        ])->assertCreated()
            ->assertJsonPath('verdict', 'great')
            ->json('id');

        $this->getJson('/api/v1/food-drink/pairings')
            ->assertOk()
            ->assertJsonCount(1);

        $this->getJson('/api/v1/food-drink/suggestions')
            ->assertOk()
            ->assertJsonStructure(['suggestions']);

        $this->deleteJson("/api/v1/food-drink/pairings/{$pairingId}")
            ->assertOk();
    }
}
