<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1Position extends Model
{
    protected $table = 'f1_positions';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'driver_number',
        'date',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'date' => 'datetime',
            'position' => 'integer',
        ];
    }
}
