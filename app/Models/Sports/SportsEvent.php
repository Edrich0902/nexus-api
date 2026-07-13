<?php

namespace App\Models\Sports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsEvent extends Model
{
    protected $fillable = [
        'sportsdb_id',
        'sports_league_id',
        'sport_slug',
        'name',
        'league_name',
        'event_date',
        'event_time',
        'status',
        'home_team',
        'away_team',
        'home_badge_url',
        'away_badge_url',
        'league_badge_url',
        'home_score',
        'away_score',
        'venue',
        'country',
        'thumb_url',
        'result_text',
        'is_major',
        'series',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'sportsdb_id' => 'integer',
            'home_score' => 'integer',
            'away_score' => 'integer',
            'is_major' => 'boolean',
            'event_date' => 'date',
            'raw' => 'array',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(SportsLeague::class, 'sports_league_id');
    }
}
