<?php

namespace App\Models\Sports;

use Illuminate\Database\Eloquent\Model;

class SportsSyncRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'provider',
        'job',
        'status',
        'calls_used',
        'error',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'calls_used' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
