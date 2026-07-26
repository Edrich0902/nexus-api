<?php

namespace Tests\Unit\Services\FoodDrink;

use App\Models\Cellar\CellarWine;
use App\Models\Kitchen\KitchenRecipe;
use App\Models\MealCatalog\MealCatalogMeal;
use App\Models\User;
use App\Models\WineCatalog\WineCatalogGrape;
use App\Models\WineCatalog\WineCatalogPairing;
use App\Models\WineCatalog\WineCatalogWine;
use App\Services\FoodDrink\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_wine_recipe_scorer_boosts_catalog_pairing_and_rating(): void
    {
        $user = User::factory()->create();
        $catalog = WineCatalogWine::factory()->complete()->create();
        $grape = WineCatalogGrape::query()->create(['name' => 'Cabernet Sauvignon', 'color' => 'red']);
        $catalog->grapes()->attach($grape->id);
        WineCatalogPairing::query()->create([
            'wine_catalog_wine_id' => $catalog->id,
            'food' => 'Beef',
            'confidence' => 0.9,
            'notes' => null,
        ]);

        $wine = CellarWine::factory()->create([
            'user_id' => $user->id,
            'wine_catalog_wine_id' => $catalog->id,
            'rating' => 5.0,
            'match_status' => CellarWine::MATCH_MATCHED,
        ]);
        $wine->load(['catalogWine.grapes', 'catalogWine.pairings', 'catalogWine.region']);

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
            collect($result['reasons'])->contains(fn ($r) => str_contains($r, 'WineAPI') || str_contains($r, 'Beef')),
        );
    }
}
