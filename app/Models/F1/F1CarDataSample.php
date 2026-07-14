<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1CarDataSample extends Model
{
    protected $table = 'f1_car_data_samples';

    protected $fillable = [
        'session_key',
        'driver_number',
        'date',
        'speed',
        'rpm',
        'n_gear',
        'throttle',
        'brake',
        'drs',
    ];

    protected function casts(): array
    {
        return [
            'session_key' => 'integer',
            'driver_number' => 'integer',
            'date' => 'datetime',
            'speed' => 'integer',
            'rpm' => 'integer',
            'n_gear' => 'integer',
            'throttle' => 'integer',
            'brake' => 'integer',
            'drs' => 'integer',
        ];
    }
}
