<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1Pit extends Model
{
    protected $table = 'f1_pits';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'driver_number',
        'date',
        'lap_number',
        'lane_duration',
        'stop_duration',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'date' => 'datetime',
            'lap_number' => 'integer',
            'lane_duration' => 'float',
            'stop_duration' => 'float',
        ];
    }
}
