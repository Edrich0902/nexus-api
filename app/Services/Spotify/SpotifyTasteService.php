<?php

namespace App\Services\Spotify;

use App\Models\Spotify\SpotifyRecentlyPlayed;
use App\Models\Spotify\SpotifyTasteSnapshot;
use App\Models\Spotify\SpotifyTopItem;
use App\Models\Spotify\SpotifyTrack;
use App\Models\User;
use Illuminate\Support\Collection;

class SpotifyTasteService
{
    /**
     * @return array<string, mixed>
     */
    public function recompute(User $user): array
    {
        $payload = $this->buildPayload($user);

        SpotifyTasteSnapshot::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'payload' => $payload,
                'computed_at' => now(),
            ],
        );

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        $snapshot = SpotifyTasteSnapshot::query()->where('user_id', $user->id)->first();

        if ($snapshot === null) {
            return $this->recompute($user);
        }

        return array_merge($snapshot->payload ?? [], [
            'computed_at' => $snapshot->computed_at?->toIso8601String(),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function suggestions(User $user, int $limit = 20): array
    {
        $recent = SpotifyRecentlyPlayed::query()
            ->with('track')
            ->where('user_id', $user->id)
            ->orderByDesc('played_at')
            ->limit(30)
            ->get();

        $topTracks = SpotifyTopItem::query()
            ->with('track')
            ->where('user_id', $user->id)
            ->where('type', 'track')
            ->where('time_range', 'short_term')
            ->orderBy('rank')
            ->limit(10)
            ->get();

        $seen = [];
        $suggestions = [];

        foreach ($topTracks->merge($recent) as $row) {
            $track = $row->track ?? null;
            if ($track === null || isset($seen[$track->spotify_id])) {
                continue;
            }

            $seen[$track->spotify_id] = true;
            $reason = $row instanceof SpotifyTopItem
                ? 'Based on your short-term top tracks'
                : 'From your recently played history';

            $suggestions[] = [
                'reason' => $reason,
                'source' => 'nexus_heuristic',
                'track' => $this->trackArray($track),
            ];

            if (count($suggestions) >= $limit) {
                break;
            }
        }

        return $suggestions;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(User $user): array
    {
        $topArtists = SpotifyTopItem::query()
            ->with('artist')
            ->where('user_id', $user->id)
            ->where('type', 'artist')
            ->orderBy('time_range')
            ->orderBy('rank')
            ->get()
            ->groupBy('time_range');

        $topTracks = SpotifyTopItem::query()
            ->with('track')
            ->where('user_id', $user->id)
            ->where('type', 'track')
            ->orderBy('time_range')
            ->orderBy('rank')
            ->get()
            ->groupBy('time_range');

        $genreHistogram = $this->genreHistogram($topArtists->get('medium_term', collect())
            ->merge($topArtists->get('long_term', collect()))
            ->merge($topArtists->get('short_term', collect())));

        return [
            'genres' => $genreHistogram,
            'top_artists' => $this->mapTopArtists($topArtists),
            'top_tracks' => $this->mapTopTracks($topTracks),
            'notes' => [
                'Spotify audio-features and recommendations APIs are unavailable for this app; taste is derived from top artists/tracks and genres when present.',
            ],
        ];
    }

    /**
     * @param  Collection<string, Collection<int, SpotifyTopItem>>  $grouped
     * @return array<string, list<array<string, mixed>>>
     */
    private function mapTopArtists(Collection $grouped): array
    {
        $result = [];

        foreach ($grouped as $range => $items) {
            $result[$range] = $items->map(function (SpotifyTopItem $item) {
                return [
                    'rank' => $item->rank,
                    'artist' => $item->artist ? [
                        'id' => $item->artist->spotify_id,
                        'name' => $item->artist->name,
                        'genres' => $item->artist->genres ?? [],
                        'images' => $item->artist->images ?? [],
                    ] : null,
                ];
            })->values()->all();
        }

        return $result;
    }

    /**
     * @param  Collection<string, Collection<int, SpotifyTopItem>>  $grouped
     * @return array<string, list<array<string, mixed>>>
     */
    private function mapTopTracks(Collection $grouped): array
    {
        $result = [];

        foreach ($grouped as $range => $items) {
            $result[$range] = $items->map(function (SpotifyTopItem $item) {
                return [
                    'rank' => $item->rank,
                    'track' => $item->track ? $this->trackArray($item->track) : null,
                ];
            })->values()->all();
        }

        return $result;
    }

    /**
     * @param  Collection<int, SpotifyTopItem>  $items
     * @return list<array{genre: string, count: int}>
     */
    private function genreHistogram(Collection $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            foreach ($item->artist?->genres ?? [] as $genre) {
                if (! is_string($genre) || $genre === '') {
                    continue;
                }
                $counts[$genre] = ($counts[$genre] ?? 0) + 1;
            }
        }

        arsort($counts);

        return collect($counts)
            ->take(20)
            ->map(fn (int $count, string $genre) => ['genre' => $genre, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function trackArray(SpotifyTrack $track): array
    {
        return [
            'id' => $track->spotify_id,
            'name' => $track->name,
            'uri' => $track->uri,
            'duration_ms' => $track->duration_ms,
            'explicit' => $track->explicit,
            'album_name' => $track->album_name,
            'album_image_url' => $track->album_image_url,
            'artists' => $track->artists ?? [],
            'external_url' => $track->external_url,
        ];
    }
}
