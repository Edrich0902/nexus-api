<?php

namespace App\Services\Beer;

use App\Models\Beer\BeerBeer;
use App\Models\Beer\BeerStyle;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class BeerService
{
    /**
     * @param  array{q?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, BeerBeer>
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        $query = BeerBeer::query()
            ->where('user_id', $user->id)
            ->with(['brewery', 'style'])
            ->orderByDesc('created_at');

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhereHas('brewery', fn ($q) => $q->where('name', 'like', $term));
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): BeerBeer
    {
        $this->assertValidRating($data['rating'] ?? null);

        return BeerBeer::query()->create([
            'user_id' => $user->id,
            'beer_brewery_id' => $data['beer_brewery_id'] ?? null,
            'beer_style_id' => $data['beer_style_id'] ?? null,
            'name' => $data['name'],
            'abv' => $data['abv'] ?? null,
            'ibu' => $data['ibu'] ?? null,
            'format' => $data['format'] ?? null,
            'rating' => $data['rating'] ?? null,
            'notes' => $data['notes'] ?? null,
        ])->load(['brewery', 'style']);
    }

    public function findOwned(User $user, int $beerId): BeerBeer
    {
        $beer = BeerBeer::query()
            ->where('user_id', $user->id)
            ->with(['brewery', 'style'])
            ->find($beerId);

        if ($beer === null) {
            abort(404, 'Beer not found.');
        }

        return $beer;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, BeerBeer $beer, array $data): BeerBeer
    {
        $this->assertOwned($user, $beer);
        $this->assertValidRating($data['rating'] ?? null);

        $beer->fill(array_intersect_key($data, array_flip([
            'beer_brewery_id',
            'beer_style_id',
            'name',
            'abv',
            'ibu',
            'format',
            'rating',
            'notes',
        ])));
        $beer->save();

        return $beer->fresh(['brewery', 'style']) ?? $beer;
    }

    public function delete(User $user, BeerBeer $beer): void
    {
        $this->assertOwned($user, $beer);
        $beer->delete();
    }

    /**
     * @return Collection<int, BeerStyle>
     */
    public function styles(): Collection
    {
        return BeerStyle::query()->orderBy('family')->orderBy('name')->get();
    }

    public function assertOwned(User $user, BeerBeer $beer): void
    {
        if ((int) $beer->user_id !== (int) $user->id) {
            abort(404, 'Beer not found.');
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
