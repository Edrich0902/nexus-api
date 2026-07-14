<?php

namespace App\Models\Spotify;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpotifyListenSample extends Model
{
    protected $fillable = [
        'user_id',
        'spotify_track_id',
        'session_id',
        'weight',
        'listened_ms',
        'played_at',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'float',
            'listened_ms' => 'integer',
            'played_at' => 'datetime',
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

    public function session(): BelongsTo
    {
        return $this->belongsTo(SpotifyListenSession::class, 'session_id');
    }
}
