<?php

namespace Tests\Feature\Api\V1\Beer;

use App\Models\Beer\BeerBrewery;
use App\Models\User;
use Database\Seeders\BeerStyleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BeerApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openbrewerydb.base_url' => 'https://api.openbrewerydb.org/v1',
            'services.openbrewerydb.cache_seconds' => 60,
            'services.rate_limits.openbrewerydb' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
        ]);

        $this->seed(BeerStyleSeeder::class);
    }

    public function test_beer_crud_with_manual_brewery(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $breweryId = $this->postJson('/api/v1/beer/breweries', [
            'name' => 'Devil\'s Peak',
            'city' => 'Cape Town',
            'country' => 'South Africa',
        ])->assertCreated()->json('id');

        $styleId = $this->getJson('/api/v1/beer/styles')
            ->assertOk()
            ->json('styles.0.id');

        $beerId = $this->postJson('/api/v1/beer/beers', [
            'name' => 'King of the Mountain',
            'beer_brewery_id' => $breweryId,
            'beer_style_id' => $styleId,
            'abv' => 6.0,
            'rating' => 4.5,
        ])->assertCreated()
            ->assertJsonPath('brewery.name', 'Devil\'s Peak')
            ->json('id');

        $this->getJson('/api/v1/beer/beers')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/beer/beers/{$beerId}")->assertOk();
    }

    public function test_import_brewery_from_obdb(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'api.openbrewerydb.org/v1/breweries/search*' => Http::response([
                [
                    'id' => 'banjo-brewing-fayetteville',
                    'name' => 'Banjo Brewing',
                    'brewery_type' => 'micro',
                    'city' => 'Fayetteville',
                    'state_province' => 'West Virginia',
                    'country' => 'United States',
                ],
            ]),
            'api.openbrewerydb.org/v1/breweries/banjo-brewing-fayetteville' => Http::response([
                'id' => 'banjo-brewing-fayetteville',
                'name' => 'Banjo Brewing',
                'brewery_type' => 'micro',
                'city' => 'Fayetteville',
                'state_province' => 'West Virginia',
                'country' => 'United States',
                'website_url' => 'http://www.banjobrewing.com',
            ]),
        ]);

        $this->getJson('/api/v1/beer/breweries/search?q=banjo')
            ->assertOk()
            ->assertJsonPath('results.0.obdb_id', 'banjo-brewing-fayetteville');

        $this->postJson('/api/v1/beer/breweries/import', [
            'obdb_id' => 'banjo-brewing-fayetteville',
        ])->assertCreated()
            ->assertJsonPath('source', BeerBrewery::SOURCE_OPENBREWERYDB);

        $this->assertDatabaseHas('beer_breweries', [
            'obdb_id' => 'banjo-brewing-fayetteville',
        ]);
    }
}
