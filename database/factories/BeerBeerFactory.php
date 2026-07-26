<?php

namespace Database\Factories;

use App\Models\Beer\BeerBeer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BeerBeer>
 */
class BeerBeerFactory extends Factory
{
    protected $model = BeerBeer::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' Ale',
            'abv' => fake()->randomFloat(1, 3, 12),
            'rating' => fake()->randomElement([3.5, 4.0, 4.5]),
            'format' => 'can',
        ];
    }
}
