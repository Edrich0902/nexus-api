<?php

namespace Database\Factories;

use App\Models\Library\LibraryBook;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LibraryBook>
 */
class LibraryBookFactory extends Factory
{
    protected $model = LibraryBook::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'authors' => fake()->name(),
            'status' => LibraryBook::STATUS_WANT,
            'match_status' => LibraryBook::MATCH_UNMATCHED,
            'rating' => fake()->optional()->randomElement([3.5, 4.0, 4.5, 5.0]),
        ];
    }
}
