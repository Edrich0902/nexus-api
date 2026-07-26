<?php

namespace Tests\Feature\Api\V1\Cellar;

use App\Jobs\FoodDrink\EnrichWineJob;
use App\Models\Cellar\CellarWine;
use App\Models\User;
use App\Models\WineCatalog\WineCatalogWine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CellarApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wineapi.api_key' => 'test-key',
            'services.wineapi.base_url' => 'https://api.wineapi.io',
            'services.wineapi.daily_limit' => 100,
            'services.wineapi.reserve_for_enrichment' => 20,
            'services.wineapi.budget_timezone' => 'UTC',
            'services.wineapi.search_cache_seconds' => 60,
            'services.rate_limits.wineapi' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
        ]);
    }

    public function test_wine_crud_is_user_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/cellar/wines', [
            'name' => 'Pinotage Reserve',
            'producer_name' => 'Kanonkop',
            'vintage' => 2019,
            'rating' => 4.5,
        ])->assertCreated();

        $wineId = $create->json('id');
        $this->assertNotNull($wineId);

        $this->getJson('/api/v1/cellar/wines')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/cellar/wines/{$wineId}")->assertNotFound();
    }

    public function test_tasting_lifecycle(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wine = CellarWine::factory()->create(['user_id' => $user->id]);

        $tasting = $this->postJson("/api/v1/cellar/wines/{$wine->id}/tastings", [
            'tasted_on' => '2026-07-01',
            'rating' => 4.0,
            'notes' => 'Cherry and smoke',
            'occasion' => 'Dinner',
        ])->assertCreated()
            ->assertJsonPath('rating', 4);

        $tastingId = $tasting->json('id');

        $this->patchJson("/api/v1/cellar/tastings/{$tastingId}", [
            'rating' => 4.5,
        ])->assertOk()->assertJsonPath('rating', 4.5);

        $this->deleteJson("/api/v1/cellar/tastings/{$tastingId}")
            ->assertOk();
    }

    public function test_candidates_search_and_confirm_match_dispatches_enrichment(): void
    {
        Bus::fake([EnrichWineJob::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wine = CellarWine::factory()->create([
            'user_id' => $user->id,
            'name' => 'Opus One',
            'producer_name' => 'Opus One',
            'vintage' => 2018,
        ]);

        $wineapiId = (string) Str::uuid();

        Http::fake([
            'api.wineapi.io/wines/search*' => Http::response([
                'results' => [[
                    'id' => $wineapiId,
                    'name' => 'Opus One',
                    'vintage' => 2018,
                    'type' => 'Red',
                    'winery' => 'Opus One',
                    'region' => 'Napa Valley',
                    'country' => 'USA',
                    'averageRating' => 4.6,
                    'ratingsCount' => 1200,
                    'confidence' => 0.95,
                ]],
                'total' => 1,
                'limit' => 20,
                'offset' => 0,
            ]),
        ]);

        $this->getJson("/api/v1/cellar/wines/{$wine->id}/candidates")
            ->assertOk()
            ->assertJsonPath('candidates.0.wineapi_id', $wineapiId)
            ->assertJsonPath('from_cache', false);

        // Second call hits cache — no extra upstream request.
        $this->getJson("/api/v1/cellar/wines/{$wine->id}/candidates")
            ->assertOk()
            ->assertJsonPath('from_cache', true);

        $this->postJson("/api/v1/cellar/wines/{$wine->id}/match", [
            'wineapi_id' => $wineapiId,
        ])->assertOk()
            ->assertJsonPath('match_status', CellarWine::MATCH_MATCHED);

        Bus::assertDispatched(EnrichWineJob::class);
        $this->assertDatabaseHas('wine_catalog_wines', [
            'wineapi_id' => $wineapiId,
            'enrichment_status' => WineCatalogWine::ENRICHMENT_QUEUED,
        ]);
    }

    public function test_no_match_and_clear_match(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $wine = CellarWine::factory()->create(['user_id' => $user->id]);

        $this->postJson("/api/v1/cellar/wines/{$wine->id}/no-match")
            ->assertOk()
            ->assertJsonPath('match_status', CellarWine::MATCH_NO_MATCH);

        $this->deleteJson("/api/v1/cellar/wines/{$wine->id}/match")
            ->assertOk()
            ->assertJsonPath('match_status', CellarWine::MATCH_UNMATCHED);
    }

    public function test_quota_endpoint(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/cellar/quota')
            ->assertOk()
            ->assertJsonPath('provider', 'wineapi')
            ->assertJsonPath('daily_limit', 100)
            ->assertJsonPath('used', 0);
    }

    public function test_enrichment_upserts_catalog_graph(): void
    {
        $wineapiId = (string) Str::uuid();
        $catalog = WineCatalogWine::factory()->create([
            'wineapi_id' => $wineapiId,
            'enrichment_status' => WineCatalogWine::ENRICHMENT_QUEUED,
        ]);

        Http::fake([
            "api.wineapi.io/wines/{$wineapiId}" => Http::response([
                'id' => $wineapiId,
                'name' => 'Opus One',
                'vintage' => 2018,
                'type' => 'Red',
                'body' => 'Full',
                'acidity' => 'Medium',
                'description' => 'Cabernet blend',
                'imageUrl' => 'https://example.com/wine.jpg',
                'averageRating' => 4.6,
                'ratingsCount' => 1200,
                'winery' => ['id' => 'w1', 'name' => 'Opus One'],
                'region' => ['id' => 'r1', 'name' => 'Napa Valley', 'country' => 'USA'],
                'grapes' => [
                    ['id' => 'g1', 'name' => 'Cabernet Sauvignon', 'color' => 'red'],
                ],
                'scores' => [
                    ['score' => 96, 'scoreText' => '96', 'reviewer' => 'WS', 'reviewDate' => '2020-01-01'],
                ],
                'prices' => [
                    ['merchantName' => 'Shop', 'price' => 350, 'currency' => 'USD', 'url' => null],
                ],
                'pairings' => [
                    ['food' => 'Steak', 'confidence' => 0.9, 'notes' => 'Classic'],
                ],
            ], 200, ['X-Update-Status' => 'complete']),
        ]);

        /** @var \App\Services\Cellar\WineEnrichmentService $service */
        $service = app(\App\Services\Cellar\WineEnrichmentService::class);
        $result = $service->enrich($catalog->fresh());

        $this->assertSame('complete', $result['status']);
        $this->assertDatabaseHas('wine_catalog_wines', [
            'id' => $catalog->id,
            'enrichment_status' => WineCatalogWine::ENRICHMENT_COMPLETE,
            'body' => 'Full',
        ]);
        $this->assertDatabaseHas('wine_catalog_wineries', ['name' => 'Opus One']);
        $this->assertDatabaseHas('wine_catalog_grapes', ['name' => 'Cabernet Sauvignon']);
        $this->assertDatabaseHas('wine_catalog_pairings', ['food' => 'Steak']);
    }
}
