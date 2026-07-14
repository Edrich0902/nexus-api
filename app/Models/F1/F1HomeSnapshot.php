<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1HomeSnapshot extends Model
{
    protected $table = 'f1_home_snapshots';

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
