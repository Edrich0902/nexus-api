<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1Overtake extends Model
{
    protected $table = 'f1_overtakes';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'date',
        'overtaking_driver_number',
        'overtaken_driver_number',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'date' => 'datetime',
            'overtaking_driver_number' => 'integer',
            'overtaken_driver_number' => 'integer',
            'position' => 'integer',
        ];
    }
}
