<?php

namespace App\Models\Sports;

use Illuminate\Database\Eloquent\Model;

class SportsHomeSnapshot extends Model
{
    protected $fillable = [
        'key',
        'payload',
        'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'computed_at' => 'datetime',
        ];
    }
}
