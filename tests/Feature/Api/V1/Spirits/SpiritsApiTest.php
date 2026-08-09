<?php

namespace Tests\Feature\Api\V1\Spirits;

use App\Jobs\Analysis\AnalyseDrinkJob;
use App\Models\Spirit\SpiritSpirit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpiritsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_spirits_crud_and_analyse(): void
    {
        Bus::fake([AnalyseDrinkJob::class]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $create = $this->postJson('/api/v1/spirits/spirits', [
            'name' => 'Glenfarclas 12',
            'producer' => 'Glenfarclas',
            'category' => 'whiskey',
            'rating' => 4.5,
        ])->assertCreated();

        $id = $create->json('id');
        $this->getJson('/api/v1/spirits/spirits')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/spirits/spirits/{$id}")->assertOk()->assertJsonPath('name', 'Glenfarclas 12');

        $this->postJson("/api/v1/spirits/spirits/{$id}/analyse")
            ->assertStatus(202)
            ->assertJsonPath('analysis_status', 'pending');

        Bus::assertDispatched(AnalyseDrinkJob::class);

        $this->deleteJson("/api/v1/spirits/spirits/{$id}")->assertOk();
        $this->assertSoftDeleted('spirit_spirits', ['id' => $id]);
    }

    public function test_spirits_are_user_scoped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $spirit = SpiritSpirit::factory()->create(['user_id' => $user->id]);

        Sanctum::actingAs($other);
        $this->getJson("/api/v1/spirits/spirits/{$spirit->id}")->assertNotFound();
    }
}
