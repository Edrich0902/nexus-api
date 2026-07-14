<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1SyncRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $table = 'f1_sync_runs';

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
