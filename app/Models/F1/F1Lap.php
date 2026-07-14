<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1Lap extends Model
{
    protected $table = 'f1_laps';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'driver_number',
        'lap_number',
        'date_start',
        'lap_duration',
        'duration_sector_1',
        'duration_sector_2',
        'duration_sector_3',
        'i1_speed',
        'i2_speed',
        'st_speed',
        'is_pit_out_lap',
        'segments_sector_1',
        'segments_sector_2',
        'segments_sector_3',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'lap_number' => 'integer',
            'date_start' => 'datetime',
            'lap_duration' => 'float',
            'duration_sector_1' => 'float',
            'duration_sector_2' => 'float',
            'duration_sector_3' => 'float',
            'i1_speed' => 'integer',
            'i2_speed' => 'integer',
            'st_speed' => 'integer',
            'is_pit_out_lap' => 'boolean',
            'segments_sector_1' => 'array',
            'segments_sector_2' => 'array',
            'segments_sector_3' => 'array',
        ];
    }
}
