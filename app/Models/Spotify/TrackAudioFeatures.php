<?php

namespace App\Models\Spotify;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackAudioFeatures extends Model
{
    protected $table = 'track_audio_features';

    protected $fillable = [
        'spotify_track_id',
        'provider',
        'provider_track_id',
        'isrc',
        'acousticness',
        'danceability',
        'energy',
        'instrumentalness',
        'key',
        'liveness',
        'loudness',
        'mode',
        'speechiness',
        'tempo',
        'valence',
        'raw',
        'fetched_at',
        'failed_at',
        'fail_reason',
    ];

    protected function casts(): array
    {
        return [
            'acousticness' => 'float',
            'danceability' => 'float',
            'energy' => 'float',
            'instrumentalness' => 'float',
            'key' => 'integer',
            'liveness' => 'float',
            'loudness' => 'float',
            'mode' => 'integer',
            'speechiness' => 'float',
            'tempo' => 'float',
            'valence' => 'float',
            'raw' => 'array',
            'fetched_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(SpotifyTrack::class, 'spotify_track_id');
    }

    public function isReady(): bool
    {
        return $this->fetched_at !== null && $this->fail_reason === null;
    }

    public function isUnavailable(): bool
    {
        return $this->failed_at !== null && $this->fetched_at === null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toFeatureArray(): ?array
    {
        if (! $this->isReady()) {
            return null;
        }

        return [
            'provider' => $this->provider,
            'acousticness' => $this->acousticness,
            'danceability' => $this->danceability,
            'energy' => $this->energy,
            'instrumentalness' => $this->instrumentalness,
            'key' => $this->key,
            'liveness' => $this->liveness,
            'loudness' => $this->loudness,
            'mode' => $this->mode,
            'speechiness' => $this->speechiness,
            'tempo' => $this->tempo,
            'valence' => $this->valence,
            'fetched_at' => $this->fetched_at?->toIso8601String(),
        ];
    }
}
