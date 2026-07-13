<?php

namespace App\Models\Sports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SportsLeague extends Model
{
    protected $fillable = [
        'sportsdb_id',
        'sport_slug',
        'name',
        'badge_url',
        'meta',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'last_synced_at' => 'datetime',
            'sportsdb_id' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(SportsEvent::class, 'sports_league_id');
    }

    public function standings(): HasMany
    {
        return $this->hasMany(SportsStanding::class, 'sports_league_id');
    }
}
