<?php

namespace App\Models\Spotify;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpotifyListeningSettings extends Model
{
    protected $fillable = [
        'user_id',
        'engage_progress_ms',
        'engage_ratio',
        'full_listen_ratio',
        'auto_queue_enabled',
        'auto_queue_min_upcoming',
        'auto_queue_batch',
    ];

    protected function casts(): array
    {
        return [
            'engage_progress_ms' => 'integer',
            'engage_ratio' => 'float',
            'full_listen_ratio' => 'float',
            'auto_queue_enabled' => 'boolean',
            'auto_queue_min_upcoming' => 'integer',
            'auto_queue_batch' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'engage_progress_ms' => $this->engage_progress_ms,
            'engage_ratio' => $this->engage_ratio,
            'full_listen_ratio' => $this->full_listen_ratio,
            'auto_queue_enabled' => $this->auto_queue_enabled,
            'auto_queue_min_upcoming' => $this->auto_queue_min_upcoming,
            'auto_queue_batch' => $this->auto_queue_batch,
        ];
    }
}
