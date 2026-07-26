<?php

namespace Database\Factories;

use App\Models\Cellar\CellarWine;
use App\Models\Cellar\CellarWineTasting;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CellarWineTasting>
 */
class CellarWineTastingFactory extends Factory
{
    protected $model = CellarWineTasting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'cellar_wine_id' => CellarWine::factory(),
            'tasted_on' => fake()->date(),
            'rating' => fake()->randomElement([3.0, 3.5, 4.0, 4.5, 5.0]),
            'notes' => fake()->optional()->sentence(),
            'occasion' => fake()->optional()->word(),
            'location' => fake()->optional()->city(),
        ];
    }
}
