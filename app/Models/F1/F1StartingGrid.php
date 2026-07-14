<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1StartingGrid extends Model
{
    protected $table = 'f1_starting_grids';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'driver_number',
        'position',
        'lap_duration',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'position' => 'integer',
            'lap_duration' => 'float',
        ];
    }
}
