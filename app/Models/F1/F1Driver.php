<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1Driver extends Model
{
    protected $table = 'f1_drivers';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'driver_number',
        'broadcast_name',
        'full_name',
        'first_name',
        'last_name',
        'name_acronym',
        'team_name',
        'team_colour',
        'headshot_url',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'driver_number' => 'integer',
        ];
    }
}
