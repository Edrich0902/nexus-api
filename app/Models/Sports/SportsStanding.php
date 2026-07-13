<?php

namespace App\Models\Sports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SportsStanding extends Model
{
    protected $fillable = [
        'sports_league_id',
        'season',
        'rows',
        'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'rows' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(SportsLeague::class, 'sports_league_id');
    }
}
