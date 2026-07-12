<?php

namespace App\Models\Spotify;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpotifyPlaylistItem extends Model
{
    protected $fillable = [
        'spotify_playlist_id',
        'spotify_track_id',
        'position',
        'added_at',
    ];

    protected function casts(): array
    {
        return [
            'added_at' => 'datetime',
        ];
    }

    public function playlist(): BelongsTo
    {
        return $this->belongsTo(SpotifyPlaylist::class, 'spotify_playlist_id');
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(SpotifyTrack::class, 'spotify_track_id');
    }
}
