<?php

namespace Database\Factories;

use App\Models\Cellar\CellarWine;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CellarWine>
 */
class CellarWineFactory extends Factory
{
    protected $model = CellarWine::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'wine_catalog_wine_id' => null,
            'producer_name' => fake()->company(),
            'name' => fake()->words(3, true),
            'vintage' => fake()->numberBetween(2010, 2024),
            'wine_type' => fake()->randomElement(['Red', 'White', 'Rosé', 'Sparkling']),
            'region_name' => fake()->city(),
            'country' => fake()->country(),
            'rating' => fake()->randomElement([3.0, 3.5, 4.0, 4.5, 5.0]),
            'notes' => fake()->optional()->sentence(),
            'match_status' => CellarWine::MATCH_UNMATCHED,
        ];
    }

    public function matched(): static
    {
        return $this->state(fn () => [
            'match_status' => CellarWine::MATCH_MATCHED,
        ]);
    }
}
