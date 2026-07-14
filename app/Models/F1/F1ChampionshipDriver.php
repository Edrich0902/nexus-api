<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1ChampionshipDriver extends Model
{
    protected $table = 'f1_championship_drivers';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'year',
        'driver_number',
        'position_current',
        'position_start',
        'points_current',
        'points_start',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'year' => 'integer',
            'driver_number' => 'integer',
            'position_current' => 'integer',
            'position_start' => 'integer',
            'points_current' => 'float',
            'points_start' => 'float',
        ];
    }
}
