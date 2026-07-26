<?php

namespace Tests\Feature\Api\V1\Kitchen;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class KitchenApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mealdb.api_key' => '1',
            'services.mealdb.base_url' => 'https://www.themealdb.com/api/json/v1',
            'services.mealdb.cache_seconds' => 60,
            'services.rate_limits.mealdb' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
        ]);
    }

    public function test_search_and_save_recipe_flattens_ingredients(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'www.themealdb.com/api/json/v1/1/search.php*' => Http::response([
                'meals' => [[
                    'idMeal' => '52772',
                    'strMeal' => 'Teriyaki Chicken Casserole',
                    'strMealThumb' => 'https://example.com/meal.jpg',
                    'strCategory' => 'Chicken',
                    'strArea' => 'Japanese',
                ]],
            ]),
            'www.themealdb.com/api/json/v1/1/lookup.php*' => Http::response([
                'meals' => [[
                    'idMeal' => '52772',
                    'strMeal' => 'Teriyaki Chicken Casserole',
                    'strCategory' => 'Chicken',
                    'strArea' => 'Japanese',
                    'strInstructions' => 'Mix and bake.',
                    'strMealThumb' => 'https://example.com/meal.jpg',
                    'strTags' => 'Meat,Casserole',
                    'strYoutube' => null,
                    'strSource' => null,
                    'strIngredient1' => 'soy sauce',
                    'strMeasure1' => '3/4 cup',
                    'strIngredient2' => 'water',
                    'strMeasure2' => '1/2 cup',
                    'strIngredient3' => 'chicken',
                    'strMeasure3' => '1 kg',
                    'strIngredient4' => '',
                    'strMeasure4' => '',
                ]],
            ]),
        ]);

        $this->getJson('/api/v1/kitchen/search?q=teriyaki')
            ->assertOk()
            ->assertJsonPath('results.0.mealdb_id', '52772');

        $this->postJson('/api/v1/kitchen/recipes', [
            'mealdb_id' => '52772',
            'rating' => 4.5,
        ])->assertCreated()
            ->assertJsonPath('meal.name', 'Teriyaki Chicken Casserole')
            ->assertJsonCount(3, 'meal.ingredients');

        // Second save hits local catalog — no extra lookup if already imported.
        $this->postJson('/api/v1/kitchen/recipes', [
            'mealdb_id' => '52772',
        ])->assertCreated();

        $this->assertDatabaseCount('meal_catalog_meals', 1);
        $this->assertDatabaseCount('kitchen_recipes', 1);
    }

    public function test_meal_preview_does_not_create_user_recipe(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'www.themealdb.com/api/json/v1/1/lookup.php*' => Http::response([
                'meals' => [[
                    'idMeal' => '52772',
                    'strMeal' => 'Teriyaki Chicken Casserole',
                    'strCategory' => 'Chicken',
                    'strArea' => 'Japanese',
                    'strInstructions' => 'Mix and bake.',
                    'strMealThumb' => 'https://example.com/meal.jpg',
                    'strIngredient1' => 'soy sauce',
                    'strMeasure1' => '3/4 cup',
                ]],
            ]),
        ]);

        $this->getJson('/api/v1/kitchen/meals/52772')
            ->assertOk()
            ->assertJsonPath('meal.mealdb_id', '52772')
            ->assertJsonPath('meal.name', 'Teriyaki Chicken Casserole');

        $this->assertDatabaseCount('meal_catalog_meals', 1);
        $this->assertDatabaseCount('kitchen_recipes', 0);
    }

    public function test_recipe_cooked_and_update(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'www.themealdb.com/api/json/v1/1/lookup.php*' => Http::response([
                'meals' => [[
                    'idMeal' => '1',
                    'strMeal' => 'Test Meal',
                    'strCategory' => 'Dessert',
                    'strArea' => 'American',
                    'strInstructions' => 'Bake',
                    'strMealThumb' => null,
                    'strIngredient1' => 'sugar',
                    'strMeasure1' => '1 cup',
                ]],
            ]),
        ]);

        $id = $this->postJson('/api/v1/kitchen/recipes', ['mealdb_id' => '1'])
            ->assertCreated()
            ->json('id');

        $this->postJson("/api/v1/kitchen/recipes/{$id}/cooked")
            ->assertOk()
            ->assertJsonPath('cooked_count', 1);

        $this->patchJson("/api/v1/kitchen/recipes/{$id}", [
            'is_favourite' => true,
            'rating' => 5.0,
        ])->assertOk()
            ->assertJsonPath('is_favourite', true)
            ->assertJsonPath('rating', 5);
    }
}
