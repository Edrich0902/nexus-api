<?php

namespace App\Services\Spotify;

use App\Models\Spotify\SpotifyListenSample;
use App\Models\Spotify\TrackAudioFeatures;
use App\Models\User;
use Illuminate\Support\Carbon;

class ListeningProfileService
{
    /** @var list<string> */
    private const METRIC_KEYS = [
        'acousticness',
        'danceability',
        'energy',
        'instrumentalness',
        'liveness',
        'speechiness',
        'valence',
        'tempo',
        'loudness',
    ];

    /**
     * @return array<string, mixed>
     */
    public function forUser(User $user): array
    {
        return [
            'windows' => [
                '7d' => $this->window($user, 7),
                '30d' => $this->window($user, 30),
                'all' => $this->window($user, null),
            ],
            'sample_count_total' => SpotifyListenSample::query()->where('user_id', $user->id)->count(),
            'computed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Lightweight averages for taste snapshot embedding.
     *
     * @return array<string, mixed>
     */
    public function snapshotMetrics(User $user): array
    {
        $seven = $this->window($user, 7);
        $thirty = $this->window($user, 30);

        return [
            'audio_metrics' => [
                '7d' => $seven,
                '30d' => $thirty,
            ],
        ];
    }

    public function recompute(User $user): void
    {
        // Profile is computed on read from samples; hook reserved for future caching.
        unset($user);
    }

    /**
     * @return array{sample_count: int, total_weight: float, averages: array<string, float|null>}
     */
    private function window(User $user, ?int $days): array
    {
        $query = SpotifyListenSample::query()
            ->where('user_id', $user->id)
            ->with(['track.audioFeatures']);

        if ($days !== null) {
            $query->where('played_at', '>=', Carbon::now()->subDays($days));
        }

        $samples = $query->get();
        $sums = array_fill_keys(self::METRIC_KEYS, 0.0);
        $weights = array_fill_keys(self::METRIC_KEYS, 0.0);
        $totalWeight = 0.0;
        $countWithFeatures = 0;

        foreach ($samples as $sample) {
            $weight = (float) $sample->weight;
            if ($weight <= 0) {
                continue;
            }

            $totalWeight += $weight;
            $features = $sample->track?->audioFeatures;
            if (! $features instanceof TrackAudioFeatures || ! $features->isReady()) {
                continue;
            }

            $countWithFeatures++;
            foreach (self::METRIC_KEYS as $key) {
                $value = $features->{$key};
                if ($value === null) {
                    continue;
                }
                $sums[$key] += ((float) $value) * $weight;
                $weights[$key] += $weight;
            }
        }

        $averages = [];
        foreach (self::METRIC_KEYS as $key) {
            $averages[$key] = $weights[$key] > 0
                ? round($sums[$key] / $weights[$key], 4)
                : null;
        }

        return [
            'sample_count' => $samples->count(),
            'samples_with_features' => $countWithFeatures,
            'total_weight' => round($totalWeight, 2),
            'averages' => $averages,
        ];
    }
}
