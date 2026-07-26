<?php

namespace App\Services\Cellar;

use App\Models\Cellar\CellarWine;
use App\Models\Cellar\CellarWineTasting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class WineTastingService
{
    public function __construct(
        private readonly CellarWineService $wines,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, CellarWine $wine, array $data): CellarWineTasting
    {
        $this->wines->assertOwned($user, $wine);
        $this->assertValidRating($data['rating'] ?? null);

        return CellarWineTasting::query()->create([
            'user_id' => $user->id,
            'cellar_wine_id' => $wine->id,
            'tasted_on' => $data['tasted_on'],
            'rating' => $data['rating'] ?? null,
            'notes' => $data['notes'] ?? null,
            'occasion' => $data['occasion'] ?? null,
            'location' => $data['location'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, CellarWineTasting $tasting, array $data): CellarWineTasting
    {
        $this->assertOwned($user, $tasting);
        $this->assertValidRating($data['rating'] ?? null);

        $tasting->fill(array_intersect_key($data, array_flip([
            'tasted_on',
            'rating',
            'notes',
            'occasion',
            'location',
        ])));
        $tasting->save();

        return $tasting;
    }

    public function delete(User $user, CellarWineTasting $tasting): void
    {
        $this->assertOwned($user, $tasting);
        $tasting->delete();
    }

    public function findOwned(User $user, int $tastingId): CellarWineTasting
    {
        $tasting = CellarWineTasting::query()
            ->where('user_id', $user->id)
            ->find($tastingId);

        if ($tasting === null) {
            abort(404, 'Tasting not found.');
        }

        return $tasting;
    }

    private function assertOwned(User $user, CellarWineTasting $tasting): void
    {
        if ((int) $tasting->user_id !== (int) $user->id) {
            abort(404, 'Tasting not found.');
        }
    }

    private function assertValidRating(mixed $rating): void
    {
        if ($rating === null) {
            return;
        }

        $value = (float) $rating;
        if ($value < 0.5 || $value > 5.0) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must be between 0.5 and 5.0.'],
            ]);
        }

        if (abs(($value * 2) - round($value * 2)) > 0.001) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must use half-step increments (e.g. 3.5, 4.0).'],
            ]);
        }
    }
}
