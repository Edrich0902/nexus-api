<?php

namespace App\Models\Spotify;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpotifyPlaylist extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'spotify_id',
        'name',
        'description',
        'public',
        'collaborative',
        'is_owner',
        'image_url',
        'snapshot_id',
        'uri',
        'item_count',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'public' => 'boolean',
            'collaborative' => 'boolean',
            'is_owner' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SpotifyPlaylistItem::class)->orderBy('position');
    }
}
