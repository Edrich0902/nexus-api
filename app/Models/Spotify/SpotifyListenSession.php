<?php

namespace App\Models\Spotify;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SpotifyListenSession extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'user_id',
        'spotify_track_id',
        'spotify_id',
        'started_at',
        'last_progress_ms',
        'max_progress_ms',
        'duration_ms',
        'features_requested_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'features_requested_at' => 'datetime',
            'last_progress_ms' => 'integer',
            'max_progress_ms' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(SpotifyTrack::class, 'spotify_track_id');
    }

    public function sample(): HasOne
    {
        return $this->hasOne(SpotifyListenSample::class, 'session_id');
    }
}
