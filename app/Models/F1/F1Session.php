<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class F1Session extends Model
{
    public const REPLAY_PENDING = 'pending';

    public const REPLAY_READY = 'ready';

    public const REPLAY_FAILED = 'failed';

    protected $table = 'f1_sessions';

    protected $fillable = [
        'session_key',
        'meeting_key',
        'year',
        'session_name',
        'session_type',
        'circuit_short_name',
        'country_name',
        'location',
        'gmt_offset',
        'date_start',
        'date_end',
        'is_cancelled',
        'detail_synced_at',
        'replay_synced_at',
        'replay_status',
        'replay_error',
    ];

    protected function casts(): array
    {
        return [
            'session_key' => 'integer',
            'meeting_key' => 'integer',
            'year' => 'integer',
            'date_start' => 'datetime',
            'date_end' => 'datetime',
            'is_cancelled' => 'boolean',
            'detail_synced_at' => 'datetime',
            'replay_synced_at' => 'datetime',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(F1Meeting::class, 'meeting_key', 'meeting_key');
    }

    public function drivers(): HasMany
    {
        return $this->hasMany(F1Driver::class, 'session_key', 'session_key');
    }

    public function isHistoricallyAvailable(): bool
    {
        if ($this->date_end === null) {
            return false;
        }

        $buffer = (int) config('services.openf1.sync.live_buffer_minutes', 35);

        return $this->date_end->copy()->addMinutes($buffer)->isPast();
    }
}
