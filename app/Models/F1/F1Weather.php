<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1Weather extends Model
{
    protected $table = 'f1_weather';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'date',
        'air_temperature',
        'track_temperature',
        'humidity',
        'pressure',
        'rainfall',
        'wind_direction',
        'wind_speed',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'date' => 'datetime',
            'air_temperature' => 'float',
            'track_temperature' => 'float',
            'humidity' => 'integer',
            'pressure' => 'float',
            'rainfall' => 'boolean',
            'wind_direction' => 'integer',
            'wind_speed' => 'float',
        ];
    }
}
