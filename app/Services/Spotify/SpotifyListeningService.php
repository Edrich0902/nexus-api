<?php

namespace App\Services\Spotify;

use App\Models\Spotify\SpotifyRecentlyPlayed;
use App\Models\Spotify\SpotifyTopItem;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class SpotifyListeningService
{
    public function __construct(
        private readonly SpotifyTasteService $taste,
    ) {}

    public function recentlyPlayed(User $user, int $perPage = 50): LengthAwarePaginator
    {
        return SpotifyRecentlyPlayed::query()
            ->with('track')
            ->where('user_id', $user->id)
            ->orderByDesc('played_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, SpotifyTopItem>
     */
    public function topItems(User $user, string $type, string $timeRange = 'medium_term'): Collection
    {
        $singular = $type === 'artists' ? 'artist' : ($type === 'tracks' ? 'track' : $type);

        return SpotifyTopItem::query()
            ->with(['artist', 'track'])
            ->where('user_id', $user->id)
            ->where('type', $singular)
            ->where('time_range', $timeRange)
            ->orderBy('rank')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    public function taste(User $user): array
    {
        return $this->taste->forUser($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function suggestions(User $user): array
    {
        return $this->taste->suggestions($user);
    }
}
