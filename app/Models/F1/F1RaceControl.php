<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1RaceControl extends Model
{
    protected $table = 'f1_race_control';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'date',
        'category',
        'flag',
        'scope',
        'driver_number',
        'lap_number',
        'sector',
        'qualifying_phase',
        'message',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'date' => 'datetime',
            'driver_number' => 'integer',
            'lap_number' => 'integer',
            'sector' => 'integer',
            'qualifying_phase' => 'integer',
        ];
    }
}
