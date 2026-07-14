<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1SessionResult extends Model
{
    protected $table = 'f1_session_results';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'driver_number',
        'position',
        'duration',
        'gap_to_leader',
        'number_of_laps',
        'dnf',
        'dns',
        'dsq',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'position' => 'integer',
            'duration' => 'array',
            'gap_to_leader' => 'array',
            'number_of_laps' => 'integer',
            'dnf' => 'boolean',
            'dns' => 'boolean',
            'dsq' => 'boolean',
        ];
    }
}
