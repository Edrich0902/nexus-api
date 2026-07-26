<?php

namespace Database\Factories;

use App\Models\Beer\BeerBrewery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BeerBrewery>
 */
class BeerBreweryFactory extends Factory
{
    protected $model = BeerBrewery::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company().' Brewing',
            'city' => fake()->city(),
            'country' => 'South Africa',
            'source' => BeerBrewery::SOURCE_MANUAL,
        ];
    }
}
