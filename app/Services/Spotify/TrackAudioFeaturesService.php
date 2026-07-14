<?php

namespace App\Services\Spotify;

use App\Integrations\ReccoBeats\ReccoBeatsIntegration;
use App\Jobs\Spotify\FetchTrackAudioFeaturesJob;
use App\Models\Spotify\SpotifyTrack;
use App\Models\Spotify\TrackAudioFeatures;
use Illuminate\Support\Facades\Log;

class TrackAudioFeaturesService
{
    public function __construct(
        private readonly ReccoBeatsIntegration $reccobeats,
    ) {}

    public function findCached(SpotifyTrack $track): ?TrackAudioFeatures
    {
        return TrackAudioFeatures::query()
            ->where('spotify_track_id', $track->id)
            ->first();
    }

    /**
     * Return cached features when ready; otherwise enqueue a fetch (or sync).
     *
     * @return array{status: string, features: array<string, mixed>|null}
     */
    public function resolve(SpotifyTrack $track, bool $async = true): array
    {
        $row = $this->findCached($track);

        if ($row?->isReady()) {
            return [
                'status' => 'ready',
                'features' => $row->toFeatureArray(),
            ];
        }

        if ($row?->isUnavailable() && ! $this->shouldRetryFailure($row)) {
            return [
                'status' => 'unavailable',
                'features' => null,
            ];
        }

        if ($async) {
            FetchTrackAudioFeaturesJob::dispatch($track->id);

            return [
                'status' => 'loading',
                'features' => null,
            ];
        }

        $row = $this->fetchAndStore($track);

        if ($row?->isReady()) {
            return [
                'status' => 'ready',
                'features' => $row->toFeatureArray(),
            ];
        }

        return [
            'status' => 'unavailable',
            'features' => null,
        ];
    }

    public function fetchAndStore(SpotifyTrack $track): ?TrackAudioFeatures
    {
        try {
            $rows = $this->reccobeats->audioFeatures([$track->spotify_id]);
        } catch (\Throwable $e) {
            Log::warning('ReccoBeats audio features fetch failed', [
                'spotify_id' => $track->spotify_id,
                'message' => $e->getMessage(),
            ]);

            return $this->markFailed($track, $e->getMessage());
        }

        $payload = $this->matchPayload($rows, $track->spotify_id);

        if ($payload === null) {
            return $this->markFailed($track, 'Audio features not found in ReccoBeats catalog.');
        }

        return TrackAudioFeatures::query()->updateOrCreate(
            ['spotify_track_id' => $track->id],
            [
                'provider' => ReccoBeatsIntegration::PROVIDER,
                'provider_track_id' => is_string($payload['id'] ?? null) ? $payload['id'] : null,
                'isrc' => is_string($payload['isrc'] ?? null) ? $payload['isrc'] : null,
                'acousticness' => $this->floatOrNull($payload['acousticness'] ?? null),
                'danceability' => $this->floatOrNull($payload['danceability'] ?? null),
                'energy' => $this->floatOrNull($payload['energy'] ?? null),
                'instrumentalness' => $this->floatOrNull($payload['instrumentalness'] ?? null),
                'key' => $this->intOrNull($payload['key'] ?? null),
                'liveness' => $this->floatOrNull($payload['liveness'] ?? null),
                'loudness' => $this->floatOrNull($payload['loudness'] ?? null),
                'mode' => $this->intOrNull($payload['mode'] ?? null),
                'speechiness' => $this->floatOrNull($payload['speechiness'] ?? null),
                'tempo' => $this->floatOrNull($payload['tempo'] ?? null),
                'valence' => $this->floatOrNull($payload['valence'] ?? null),
                'raw' => $payload,
                'fetched_at' => now(),
                'failed_at' => null,
                'fail_reason' => null,
            ],
        );
    }

    private function shouldRetryFailure(TrackAudioFeatures $row): bool
    {
        if ($row->failed_at === null) {
            return true;
        }

        $minutes = (int) config('services.spotify.listening.feature_retry_minutes', 60);

        return $row->failed_at->lte(now()->subMinutes($minutes));
    }

    private function markFailed(SpotifyTrack $track, string $reason): TrackAudioFeatures
    {
        return TrackAudioFeatures::query()->updateOrCreate(
            ['spotify_track_id' => $track->id],
            [
                'provider' => ReccoBeatsIntegration::PROVIDER,
                'failed_at' => now(),
                'fail_reason' => mb_substr($reason, 0, 250),
            ],
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private function matchPayload(array $rows, string $spotifyId): ?array
    {
        foreach ($rows as $row) {
            $hrefId = ReccoBeatsIntegration::spotifyIdFromHref(
                is_string($row['href'] ?? null) ? $row['href'] : null,
            );
            if ($hrefId === $spotifyId) {
                return $row;
            }
        }

        return $rows[0] ?? null;
    }

    private function floatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
