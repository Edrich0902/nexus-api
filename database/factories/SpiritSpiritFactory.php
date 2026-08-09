<?php

namespace Database\Factories;

use App\Models\Spirit\SpiritSpirit;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpiritSpirit>
 */
class SpiritSpiritFactory extends Factory
{
    protected $model = SpiritSpirit::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'producer' => fake()->company(),
            'category' => fake()->randomElement(['whiskey', 'gin', 'rum', 'vodka', 'tequila']),
            'age_statement' => null,
            'abv' => fake()->randomFloat(1, 35, 55),
            'region' => null,
            'country' => fake()->country(),
            'rating' => null,
            'notes' => null,
            'analysis_status' => 'none',
        ];
    }
}
