<?php

namespace Tests\Feature\Api\V1\Cellar;

use App\Jobs\Analysis\AnalyseDrinkJob;
use App\Models\Cellar\CellarWine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CellarApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.gemini.api_key' => 'test-key',
            'services.gemini.base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'services.gemini.cascade' => ['gemma-4-31b-it'],
            'services.gemini.models' => [
                'gemma-4-31b-it' => [
                    'label' => 'Gemma 4 31B',
                    'rpm' => 30,
                    'rpd' => 14400,
                    'supports_vision' => true,
                ],
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

    public function test_analyse_queues_job_and_is_idempotent_when_complete(): void
    {
        Bus::fake([AnalyseDrinkJob::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wine = CellarWine::factory()->create([
            'user_id' => $user->id,
            'name' => 'Opus One',
            'producer_name' => 'Opus One',
            'vintage' => 2018,
        ]);

        $this->postJson("/api/v1/cellar/wines/{$wine->id}/analyse")
            ->assertStatus(202)
            ->assertJsonPath('analysis_status', 'pending');

        Bus::assertDispatched(AnalyseDrinkJob::class);

        $wine->forceFill([
            'analysis_status' => 'complete',
            'ai_analysis' => [
                'schema_version' => 1,
                'domain' => 'wine',
                'identity' => ['name' => 'Opus One'],
                'sensory' => [],
                'narrative' => [],
                'meta' => ['confidence' => 0.9, 'uncertainties' => [], 'labels_detected' => []],
            ],
        ])->save();

        Bus::fake([AnalyseDrinkJob::class]);
        $this->postJson("/api/v1/cellar/wines/{$wine->id}/analyse")
            ->assertOk()
            ->assertJsonPath('analysis_status', 'complete');
        Bus::assertNotDispatched(AnalyseDrinkJob::class);
    }

    public function test_analyse_job_persists_payload(): void
    {
        $user = User::factory()->create();
        $wine = CellarWine::factory()->create([
            'user_id' => $user->id,
            'name' => 'Test Wine',
            'producer_name' => null,
        ]);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'id' => 'test',
                'status' => 'completed',
                'object' => 'interaction',
                'model' => 'gemini-3.5-flash-lite',
                'steps' => [[
                    'type' => 'model_output',
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode([
                            'schema_version' => 1,
                            'domain' => 'wine',
                            'identity' => [
                                'name' => 'Test Wine',
                                'producer' => 'AI Estate',
                                'brand' => null,
                                'category' => 'red',
                                'style' => null,
                                'vintage_or_age' => '2019',
                                'region' => 'Stellenbosch',
                                'country' => 'South Africa',
                                'abv' => 14.0,
                                'volume_ml' => 750,
                            ],
                            'sensory' => [
                                'appearance' => 'deep ruby',
                                'aroma_notes' => ['cherry'],
                                'taste_notes' => ['plum'],
                                'finish' => 'long',
                                'body' => 'full',
                                'sweetness' => 'dry',
                                'acidity' => 'medium',
                                'bitterness' => null,
                                'bitterness_ibu' => null,
                                'tannin' => 'firm',
                                'carbonation' => null,
                                'mouthfeel' => 'structured',
                                'smoke_peat' => null,
                            ],
                            'narrative' => [
                                'tasting_notes' => 'Rich and bold.',
                                'characteristics' => ['bold'],
                                'interesting_facts' => ['Popular SA wine region'],
                                'serving_suggestions' => 'Decant 30 min',
                                'food_pairings' => ['Beef'],
                                'glassware' => 'Bordeaux',
                                'serving_temp_c' => ['min' => 16, 'max' => 18],
                            ],
                            'meta' => [
                                'confidence' => 0.85,
                                'uncertainties' => [],
                                'labels_detected' => [],
                            ],
                        ], JSON_THROW_ON_ERROR),
                    ]],
                ]],
            ]),
        ]);

        $job = new AnalyseDrinkJob('cellar_wine', (int) $wine->id);
        $job->handle(app(\App\Services\Analysis\DrinkAnalysisService::class));

        $wine->refresh();
        $this->assertSame('complete', $wine->analysis_status);
        $this->assertSame('AI Estate', $wine->producer_name);
        $this->assertNotEmpty($wine->analysis_model);
        $this->assertSame('Beef', $wine->ai_analysis['narrative']['food_pairings'][0] ?? null);
    }
}
