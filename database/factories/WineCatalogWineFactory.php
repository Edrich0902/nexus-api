<?php

namespace Database\Factories;

use App\Models\WineCatalog\WineCatalogWine;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WineCatalogWine>
 */
class WineCatalogWineFactory extends Factory
{
    protected $model = WineCatalogWine::class;

    public function definition(): array
    {
        return [
            'wineapi_id' => (string) Str::uuid(),
            'name' => fake()->words(3, true),
            'vintage' => fake()->numberBetween(2010, 2024),
            'type' => fake()->randomElement(['Red', 'White', 'Rosé']),
            'enrichment_status' => WineCatalogWine::ENRICHMENT_PENDING,
            'enrichment_attempts' => 0,
        ];
    }

    public function complete(): static
    {
        return $this->state(fn () => [
            'enrichment_status' => WineCatalogWine::ENRICHMENT_COMPLETE,
            'enriched_at' => now(),
            'description' => fake()->paragraph(),
            'image_url' => fake()->imageUrl(),
        ]);
    }
}
