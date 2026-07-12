<?php

namespace App\Models\Spotify;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpotifyTrack extends Model
{
    protected $fillable = [
        'spotify_id',
        'name',
        'duration_ms',
        'explicit',
        'album_name',
        'album_image_url',
        'artists',
        'external_url',
        'uri',
    ];

    protected function casts(): array
    {
        return [
            'explicit' => 'boolean',
            'artists' => 'array',
        ];
    }

    public function recentlyPlayed(): HasMany
    {
        return $this->hasMany(SpotifyRecentlyPlayed::class);
    }
}
