<?php

namespace App\Models\Spotify;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpotifyRecentlyPlayed extends Model
{
    protected $table = 'spotify_recently_played';

    protected $fillable = [
        'user_id',
        'spotify_track_id',
        'played_at',
        'context_uri',
        'context_type',
    ];

    protected function casts(): array
    {
        return [
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
}
