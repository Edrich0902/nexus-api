<?php

namespace App\Services\Spotify;

use App\Models\Spotify\SpotifyRecentlyPlayed;
use App\Models\Spotify\SpotifyTasteSnapshot;
use App\Models\Spotify\SpotifyTopItem;
use App\Models\Spotify\SpotifyTrack;
use App\Models\User;
use Illuminate\Support\Carbon;
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
        $ranked = [];

        $topTracks = SpotifyTopItem::query()
            ->with('track')
            ->where('user_id', $user->id)
            ->where('type', 'track')
            ->where('time_range', 'short_term')
            ->orderBy('rank')
            ->limit(15)
            ->get();

        foreach ($topTracks as $row) {
            $track = $row->track;
            if ($track === null) {
                continue;
            }
            $this->pushSuggestion(
                $ranked,
                $track,
                100 - ((int) $row->rank),
                'Based on your short-term top tracks',
                'top'
            );
        }

        foreach ($this->onRepeatTracks($user, 30, 12) as $entry) {
            $this->pushSuggestion(
                $ranked,
                $entry['track'],
                70 + min(20, (int) $entry['play_count']),
                'On repeat ('.$entry['play_count'].' plays recently)',
                'on_repeat'
            );
        }

        $recent = SpotifyRecentlyPlayed::query()
            ->with('track')
            ->where('user_id', $user->id)
            ->orderByDesc('played_at')
            ->limit(40)
            ->get();

        foreach ($recent as $row) {
            $track = $row->track;
            if ($track === null) {
                continue;
            }
            $this->pushSuggestion(
                $ranked,
                $track,
                40,
                'From your recently played history',
                'recent'
            );
        }

        usort($ranked, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return collect($ranked)
            ->take($limit)
            ->map(fn (array $row) => [
                'reason' => $row['reason'],
                'source' => 'nexus_heuristic',
                'track' => $this->trackArray($row['track']),
            ])
            ->values()
            ->all();
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

        $onRepeat = collect($this->onRepeatTracks($user, 7, 10))
            ->map(fn (array $entry) => [
                'play_count' => $entry['play_count'],
                'window_days' => 7,
                'track' => $this->trackArray($entry['track']),
            ])
            ->values()
            ->all();

        return [
            'genres' => $genreHistogram,
            'top_artists' => $this->mapTopArtists($topArtists),
            'top_tracks' => $this->mapTopTracks($topTracks),
            'on_repeat' => $onRepeat,
            'time_of_day' => $this->timeOfDaySkew($user),
            'notes' => [
                'Spotify audio-features and recommendations APIs are unavailable for this app; taste is derived from tops, recent plays, and on-repeat heuristics.',
            ],
        ];
    }

    /**
     * @return list<array{track: SpotifyTrack, play_count: int}>
     */
    private function onRepeatTracks(User $user, int $days, int $limit): array
    {
        $since = Carbon::now()->subDays($days);

        $counts = SpotifyRecentlyPlayed::query()
            ->selectRaw('spotify_track_id, COUNT(*) as play_count')
            ->where('user_id', $user->id)
            ->where('played_at', '>=', $since)
            ->groupBy('spotify_track_id')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('play_count')
            ->limit($limit)
            ->get();

        if ($counts->isEmpty()) {
            return [];
        }

        $tracks = SpotifyTrack::query()
            ->whereIn('id', $counts->pluck('spotify_track_id'))
            ->get()
            ->keyBy('id');

        $out = [];
        foreach ($counts as $row) {
            $track = $tracks->get($row->spotify_track_id);
            if ($track === null) {
                continue;
            }
            $out[] = [
                'track' => $track,
                'play_count' => (int) $row->play_count,
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *     buckets: list<array{bucket: string, count: int}>,
     *     weekday: list<array{day: string, count: int}>,
     *     peak_bucket: string|null
     * }
     */
    private function timeOfDaySkew(User $user): array
    {
        $since = Carbon::now()->subDays(30);
        $plays = SpotifyRecentlyPlayed::query()
            ->where('user_id', $user->id)
            ->where('played_at', '>=', $since)
            ->get(['played_at']);

        $buckets = [
            'morning' => 0,
            'afternoon' => 0,
            'evening' => 0,
            'night' => 0,
        ];
        $weekdays = [
            'Mon' => 0,
            'Tue' => 0,
            'Wed' => 0,
            'Thu' => 0,
            'Fri' => 0,
            'Sat' => 0,
            'Sun' => 0,
        ];

        foreach ($plays as $play) {
            if ($play->played_at === null) {
                continue;
            }
            $hour = (int) $play->played_at->format('G');
            $bucket = match (true) {
                $hour >= 5 && $hour < 12 => 'morning',
                $hour >= 12 && $hour < 17 => 'afternoon',
                $hour >= 17 && $hour < 22 => 'evening',
                default => 'night',
            };
            $buckets[$bucket]++;
            $day = $play->played_at->format('D');
            if (isset($weekdays[$day])) {
                $weekdays[$day]++;
            }
        }

        arsort($buckets);
        $peak = array_key_first($buckets);

        return [
            'buckets' => collect($buckets)
                ->map(fn (int $count, string $bucket) => ['bucket' => $bucket, 'count' => $count])
                ->values()
                ->all(),
            'weekday' => collect($weekdays)
                ->map(fn (int $count, string $day) => ['day' => $day, 'count' => $count])
                ->values()
                ->all(),
            'peak_bucket' => ($buckets[$peak] ?? 0) > 0 ? $peak : null,
        ];
    }

    /**
     * @param  array<string, array{score: int, reason: string, source: string, track: SpotifyTrack}>  $ranked
     */
    private function pushSuggestion(
        array &$ranked,
        SpotifyTrack $track,
        int $score,
        string $reason,
        string $source,
    ): void {
        $id = $track->spotify_id;
        if (! isset($ranked[$id]) || $score > $ranked[$id]['score']) {
            $ranked[$id] = [
                'score' => $score,
                'reason' => $reason,
                'source' => $source,
                'track' => $track,
            ];
        }
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
