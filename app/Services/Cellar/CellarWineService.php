<?php

namespace App\Services\Cellar;

use App\Models\Cellar\CellarWine;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\FoodDrink\FoodDrinkDashboardService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class CellarWineService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  array{match_status?: string, q?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, CellarWine>
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        $query = CellarWine::query()
            ->where('user_id', $user->id)
            ->with(['catalogWine.winery', 'catalogWine.region', 'catalogWine.grapes'])
            ->withCount('tastings')
            ->orderByDesc('created_at');

        if (! empty($filters['match_status'])) {
            $query->where('match_status', $filters['match_status']);
        }

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhere('producer_name', 'like', $term)
                    ->orWhere('region_name', 'like', $term)
                    ->orWhere('country', 'like', $term);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): CellarWine
    {
        $this->assertValidRating($data['rating'] ?? null);

        $wine = CellarWine::query()->create([
            'user_id' => $user->id,
            'producer_name' => $data['producer_name'] ?? null,
            'name' => $data['name'],
            'vintage' => $data['vintage'] ?? null,
            'wine_type' => $data['wine_type'] ?? null,
            'region_name' => $data['region_name'] ?? null,
            'country' => $data['country'] ?? null,
            'rating' => $data['rating'] ?? null,
            'notes' => $data['notes'] ?? null,
            'match_status' => CellarWine::MATCH_UNMATCHED,
        ]);

        FoodDrinkDashboardService::forget($user);

        $this->activity->record(
            $user->id,
            'cellar',
            'wine.added',
            trim(($wine->vintage ? $wine->vintage.' ' : '').$wine->name),
            $wine->producer_name,
            $wine,
            array_filter([
                'wine_type' => $wine->wine_type,
                'rating' => $wine->rating !== null ? (float) $wine->rating : null,
            ], fn ($v) => $v !== null),
        );

        return $wine;
    }

    public function findOwned(User $user, int $wineId): CellarWine
    {
        $wine = CellarWine::query()
            ->where('user_id', $user->id)
            ->with([
                'catalogWine.winery',
                'catalogWine.region',
                'catalogWine.grapes',
                'catalogWine.scores',
                'catalogWine.prices',
                'catalogWine.pairings',
                'tastings' => fn ($q) => $q->orderByDesc('tasted_on'),
            ])
            ->find($wineId);

        if ($wine === null) {
            abort(404, 'Wine not found.');
        }

        return $wine;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, CellarWine $wine, array $data): CellarWine
    {
        $this->assertOwned($user, $wine);
        $this->assertValidRating($data['rating'] ?? null);

        $wine->fill(array_intersect_key($data, array_flip([
            'producer_name',
            'name',
            'vintage',
            'wine_type',
            'region_name',
            'country',
            'rating',
            'notes',
        ])));
        $wine->save();
        FoodDrinkDashboardService::forget($user);

        return $wine->fresh([
            'catalogWine.winery',
            'catalogWine.region',
            'catalogWine.grapes',
            'tastings',
        ]) ?? $wine;
    }

    public function delete(User $user, CellarWine $wine): void
    {
        $this->assertOwned($user, $wine);
        $wine->delete();
        FoodDrinkDashboardService::forget($user);
    }

    public function assertOwned(User $user, CellarWine $wine): void
    {
        if ((int) $wine->user_id !== (int) $user->id) {
            abort(404, 'Wine not found.');
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

        // Half-step only: value * 2 must be an integer.
        if (abs(($value * 2) - round($value * 2)) > 0.001) {
            throw ValidationException::withMessages([
                'rating' => ['Rating must use half-step increments (e.g. 3.5, 4.0).'],
            ]);
        }
    }
}
