<?php

namespace App\Models\Spotify;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SpotifyArtist extends Model
{
    protected $fillable = [
        'spotify_id',
        'name',
        'genres',
        'images',
        'external_url',
    ];

    protected function casts(): array
    {
        return [
            'genres' => 'array',
            'images' => 'array',
        ];
    }

    public function topItems(): HasMany
    {
        return $this->hasMany(SpotifyTopItem::class);
    }
}
