<?php

namespace App\Services\Spirit;

use App\Models\Spirit\SpiritSpirit;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\FoodDrink\FoodDrinkDashboardService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class SpiritService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * @param  array{q?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, SpiritSpirit>
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        $query = SpiritSpirit::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at');

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhere('producer', 'like', $term)
                    ->orWhere('category', 'like', $term);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): SpiritSpirit
    {
        $this->assertValidRating($data['rating'] ?? null);

        $spirit = SpiritSpirit::query()->create([
            'user_id' => $user->id,
            'name' => $data['name'],
            'producer' => $data['producer'] ?? null,
            'category' => $data['category'] ?? null,
            'age_statement' => $data['age_statement'] ?? null,
            'abv' => $data['abv'] ?? null,
            'region' => $data['region'] ?? null,
            'country' => $data['country'] ?? null,
            'rating' => $data['rating'] ?? null,
            'notes' => $data['notes'] ?? null,
            'analysis_status' => 'none',
        ]);

        FoodDrinkDashboardService::forget($user);

        $this->activity->record(
            $user->id,
            'spirits',
            'spirit.added',
            $spirit->name,
            collect([$spirit->producer, $spirit->category])->filter()->implode(' · ') ?: null,
            $spirit,
            array_filter([
                'rating' => $spirit->rating !== null ? (float) $spirit->rating : null,
                'age' => $spirit->age_statement,
            ], fn ($v) => $v !== null),
        );

        return $spirit;
    }

    public function findOwned(User $user, int $spiritId): SpiritSpirit
    {
        $spirit = SpiritSpirit::query()
            ->where('user_id', $user->id)
            ->find($spiritId);

        if ($spirit === null) {
            abort(404, 'Spirit not found.');
        }

        return $spirit;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, SpiritSpirit $spirit, array $data): SpiritSpirit
    {
        $this->assertOwned($user, $spirit);
        $this->assertValidRating($data['rating'] ?? null);

        $spirit->fill(array_intersect_key($data, array_flip([
            'name',
            'producer',
            'category',
            'age_statement',
            'abv',
            'region',
            'country',
            'rating',
            'notes',
        ])));
        $spirit->save();
        FoodDrinkDashboardService::forget($user);

        return $spirit->fresh() ?? $spirit;
    }

    public function delete(User $user, SpiritSpirit $spirit): void
    {
        $this->assertOwned($user, $spirit);
        $spirit->delete();
        FoodDrinkDashboardService::forget($user);
    }

    public function assertOwned(User $user, SpiritSpirit $spirit): void
    {
        if ((int) $spirit->user_id !== (int) $user->id) {
            abort(404, 'Spirit not found.');
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
