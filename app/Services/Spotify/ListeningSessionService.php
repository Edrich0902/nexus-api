<?php

namespace App\Services\Spotify;

use App\Models\Spotify\SpotifyListenSample;
use App\Models\Spotify\SpotifyListenSession;
use App\Models\Spotify\SpotifyListeningSettings;
use App\Models\Spotify\SpotifyTrack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ListeningSessionService
{
    public function __construct(
        private readonly TrackAudioFeaturesService $features,
        private readonly ListeningProfileService $profile,
    ) {}

    /**
     * @param  array{spotify_id: string, progress_ms: int, duration_ms?: int|null, is_playing?: bool, name?: string|null, artists?: list<array{id?: string|null, name?: string|null}>|null, album_name?: string|null, album_image_url?: string|null, uri?: string|null}  $payload
     * @return array<string, mixed>
     */
    public function heartbeat(User $user, array $payload): array
    {
        $spotifyId = $payload['spotify_id'];
        $progressMs = max(0, (int) $payload['progress_ms']);
        $durationMs = isset($payload['duration_ms']) ? max(0, (int) $payload['duration_ms']) : null;
        $isPlaying = (bool) ($payload['is_playing'] ?? true);

        $track = $this->ensureTrack($spotifyId, $payload);

        return DB::transaction(function () use ($user, $track, $spotifyId, $progressMs, $durationMs, $isPlaying) {
            $active = SpotifyListenSession::query()
                ->where('user_id', $user->id)
                ->where('status', SpotifyListenSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->first();

            $profileDirty = false;

            if ($active !== null && $active->spotify_id !== $spotifyId) {
                $profileDirty = $this->closeSession($active) || $profileDirty;
                $active = null;
            }

            if ($active === null) {
                $active = SpotifyListenSession::query()->create([
                    'user_id' => $user->id,
                    'spotify_track_id' => $track->id,
                    'spotify_id' => $spotifyId,
                    'started_at' => now(),
                    'last_progress_ms' => $progressMs,
                    'max_progress_ms' => $progressMs,
                    'duration_ms' => $durationMs,
                    'status' => SpotifyListenSession::STATUS_ACTIVE,
                ]);
            } else {
                $active->last_progress_ms = $progressMs;
                $active->max_progress_ms = max((int) $active->max_progress_ms, $progressMs);
                if ($durationMs !== null) {
                    $active->duration_ms = $durationMs;
                }
                $active->save();
            }

            $settings = $this->settingsFor($user);
            $engaged = $this->isEngaged($active, $settings);

            $featureStatus = 'idle';
            $featurePayload = null;

            if ($engaged) {
                $justRequested = false;
                if ($active->features_requested_at === null) {
                    $active->features_requested_at = now();
                    $active->save();
                    $justRequested = true;
                }

                $cached = $this->features->findCached($track);
                if ($cached?->isReady()) {
                    $featureStatus = 'ready';
                    $featurePayload = $cached->toFeatureArray();
                } elseif ($cached?->isUnavailable() && ! $justRequested) {
                    $featureStatus = 'unavailable';
                } elseif ($justRequested) {
                    // Synchronously fetch once so UI is not stuck if the queue worker is idle.
                    $resolved = $this->features->resolve($track, async: false);
                    $featureStatus = $resolved['status'];
                    $featurePayload = $resolved['features'];
                } else {
                    $featureStatus = 'loading';
                }
            }

            if ($profileDirty) {
                $this->profile->recompute($user);
            }

            return [
                'session_id' => $active->id,
                'spotify_id' => $spotifyId,
                'engaged' => $engaged,
                'max_progress_ms' => (int) $active->max_progress_ms,
                'features_status' => $featureStatus,
                'features' => $featurePayload,
                'settings' => [
                    'auto_queue_enabled' => $settings->auto_queue_enabled,
                    'auto_queue_min_upcoming' => $settings->auto_queue_min_upcoming,
                    'auto_queue_batch' => $settings->auto_queue_batch,
                ],
            ];
        });
    }

    public function settingsFor(User $user): SpotifyListeningSettings
    {
        $defaults = config('services.spotify.listening', []);

        return SpotifyListeningSettings::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'engage_progress_ms' => (int) ($defaults['engage_progress_ms'] ?? 30_000),
                'engage_ratio' => (float) ($defaults['engage_ratio'] ?? 0.25),
                'full_listen_ratio' => (float) ($defaults['full_listen_ratio'] ?? 0.5),
                'auto_queue_enabled' => false,
                'auto_queue_min_upcoming' => (int) ($defaults['auto_queue_min_upcoming'] ?? 3),
                'auto_queue_batch' => (int) ($defaults['auto_queue_batch'] ?? 2),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateSettings(User $user, array $attributes): SpotifyListeningSettings
    {
        $settings = $this->settingsFor($user);
        $settings->fill($attributes);
        $settings->save();

        return $settings->fresh() ?? $settings;
    }

    public function isEngaged(SpotifyListenSession $session, SpotifyListeningSettings $settings): bool
    {
        $progress = (int) $session->max_progress_ms;
        if ($progress >= (int) $settings->engage_progress_ms) {
            return true;
        }

        $duration = (int) ($session->duration_ms ?? 0);
        if ($duration <= 0) {
            return false;
        }

        return ($progress / $duration) >= (float) $settings->engage_ratio;
    }

    /**
     * @return bool whether a sample was written
     */
    public function closeSession(SpotifyListenSession $session): bool
    {
        if ($session->status === SpotifyListenSession::STATUS_CLOSED) {
            return false;
        }

        $settings = $this->settingsFor($session->user);
        $weight = $this->weightFor($session, $settings);
        $wrote = false;

        if ($weight > 0) {
            SpotifyListenSample::query()->firstOrCreate(
                ['session_id' => $session->id],
                [
                    'user_id' => $session->user_id,
                    'spotify_track_id' => $session->spotify_track_id,
                    'weight' => $weight,
                    'listened_ms' => (int) $session->max_progress_ms,
                    'played_at' => $session->started_at ?? now(),
                ],
            );
            $wrote = true;
        }

        $session->status = SpotifyListenSession::STATUS_CLOSED;
        $session->save();

        return $wrote;
    }

    public function weightFor(SpotifyListenSession $session, SpotifyListeningSettings $settings): float
    {
        if (! $this->isEngaged($session, $settings)) {
            return 0.0;
        }

        $progress = (int) $session->max_progress_ms;
        $duration = (int) ($session->duration_ms ?? 0);
        $fullRatio = (float) $settings->full_listen_ratio;
        $light = (float) config('services.spotify.listening.light_weight', 0.5);
        $full = (float) config('services.spotify.listening.full_weight', 1.0);

        if ($duration > 0 && ($progress / $duration) >= $fullRatio) {
            return $full;
        }

        return $light;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ensureTrack(string $spotifyId, array $payload): SpotifyTrack
    {
        $artists = [];
        if (is_array($payload['artists'] ?? null)) {
            foreach ($payload['artists'] as $artist) {
                if (! is_array($artist)) {
                    continue;
                }
                $artists[] = [
                    'id' => $artist['id'] ?? null,
                    'name' => $artist['name'] ?? null,
                ];
            }
        }

        $attributes = [
            'name' => is_string($payload['name'] ?? null) && $payload['name'] !== ''
                ? $payload['name']
                : 'Unknown Track',
            'duration_ms' => isset($payload['duration_ms']) ? (int) $payload['duration_ms'] : null,
            'uri' => is_string($payload['uri'] ?? null) && $payload['uri'] !== ''
                ? $payload['uri']
                : 'spotify:track:'.$spotifyId,
        ];

        if ($artists !== []) {
            $attributes['artists'] = $artists;
        }
        if (is_string($payload['album_name'] ?? null)) {
            $attributes['album_name'] = $payload['album_name'];
        }
        if (is_string($payload['album_image_url'] ?? null)) {
            $attributes['album_image_url'] = $payload['album_image_url'];
        }

        $existing = SpotifyTrack::query()->where('spotify_id', $spotifyId)->first();
        if ($existing !== null) {
            // Do not overwrite a richer catalog name with "Unknown Track".
            if (($attributes['name'] ?? '') === 'Unknown Track') {
                unset($attributes['name']);
            }
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        return SpotifyTrack::query()->create(array_merge([
            'spotify_id' => $spotifyId,
        ], $attributes));
    }
}
