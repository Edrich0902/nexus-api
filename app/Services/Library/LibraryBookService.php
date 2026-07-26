<?php

namespace App\Services\Library;

use App\Models\Library\LibraryBook;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class LibraryBookService
{
    /**
     * @param  array{q?: string, status?: string, match_status?: string, per_page?: int}  $filters
     * @return LengthAwarePaginator<int, LibraryBook>
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $perPage = max(1, min(50, (int) ($filters['per_page'] ?? 20)));

        $query = LibraryBook::query()
            ->where('user_id', $user->id)
            ->with(['catalogBook'])
            ->orderByDesc('updated_at');

        if (! empty($filters['status']) && in_array($filters['status'], LibraryBook::STATUSES, true)) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['match_status'])) {
            $query->where('match_status', $filters['match_status']);
        }

        if (! empty($filters['q'])) {
            $term = '%'.trim((string) $filters['q']).'%';
            $query->where(function ($inner) use ($term): void {
                $inner->where('title', 'like', $term)
                    ->orWhere('authors', 'like', $term)
                    ->orWhere('isbn', 'like', $term);
            });
        }

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): LibraryBook
    {
        $this->assertValidRating($data['rating'] ?? null);
        $status = $data['status'] ?? LibraryBook::STATUS_WANT;
        $this->assertValidStatus($status);

        return LibraryBook::query()->create([
            'user_id' => $user->id,
            'title' => $data['title'],
            'authors' => $data['authors'] ?? null,
            'isbn' => $data['isbn'] ?? null,
            'status' => $status,
            'rating' => $data['rating'] ?? null,
            'notes' => $data['notes'] ?? null,
            'started_at' => $data['started_at'] ?? null,
            'finished_at' => $data['finished_at'] ?? null,
            'match_status' => LibraryBook::MATCH_UNMATCHED,
        ])->load(['catalogBook']);
    }

    public function findOwned(User $user, int $bookId): LibraryBook
    {
        $book = LibraryBook::query()
            ->where('user_id', $user->id)
            ->with(['catalogBook'])
            ->find($bookId);

        if ($book === null) {
            abort(404, 'Book not found.');
        }

        return $book;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, LibraryBook $book, array $data): LibraryBook
    {
        $this->assertOwned($user, $book);
        $this->assertValidRating($data['rating'] ?? null);

        if (array_key_exists('status', $data)) {
            $this->assertValidStatus($data['status']);
        }

        $book->fill(array_intersect_key($data, array_flip([
            'title',
            'authors',
            'isbn',
            'status',
            'rating',
            'notes',
            'started_at',
            'finished_at',
        ])));
        $book->save();

        return $book->fresh(['catalogBook']) ?? $book;
    }

    public function delete(User $user, LibraryBook $book): void
    {
        $this->assertOwned($user, $book);
        $book->delete();
    }

    public function assertOwned(User $user, LibraryBook $book): void
    {
        if ((int) $book->user_id !== (int) $user->id) {
            abort(404, 'Book not found.');
        }
    }

    private function assertValidStatus(mixed $status): void
    {
        if (! is_string($status) || ! in_array($status, LibraryBook::STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => ['Status must be want, reading, or read.'],
            ]);
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
