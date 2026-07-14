<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1Stint extends Model
{
    protected $table = 'f1_stints';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'driver_number',
        'stint_number',
        'compound',
        'lap_start',
        'lap_end',
        'tyre_age_at_start',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'stint_number' => 'integer',
            'lap_start' => 'integer',
            'lap_end' => 'integer',
            'tyre_age_at_start' => 'integer',
        ];
    }
}
