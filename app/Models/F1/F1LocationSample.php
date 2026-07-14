<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1LocationSample extends Model
{
    protected $table = 'f1_location_samples';

    protected $fillable = [
        'session_key',
        'driver_number',
        'date',
        'x',
        'y',
        'z',
    ];

    protected function casts(): array
    {
        return [
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'date' => 'datetime',
            'x' => 'integer',
            'y' => 'integer',
            'z' => 'integer',
        ];
    }
}
