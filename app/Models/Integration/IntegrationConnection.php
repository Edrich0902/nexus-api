<?php

namespace App\Models\Integration;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationConnection extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_NEEDS_REAUTH = 'needs_reauth';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'user_id',
        'provider',
        'external_user_id',
        'scopes',
        'access_token',
        'refresh_token',
        'access_token_expires_at',
        'connected_at',
        'last_synced_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function needsReauth(): bool
    {
        return $this->status === self::STATUS_NEEDS_REAUTH;
    }

    /**
     * @return list<string>
     */
    public function scopeList(): array
    {
        if ($this->scopes === null || $this->scopes === '') {
            return [];
        }

        return array_values(array_filter(preg_split('/\s+/', $this->scopes) ?: []));
    }
}
