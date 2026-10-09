<?php

namespace App\Models\Activity;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityEvent extends Model
{
    use MassPrunable;

    public const MODULES = [
        'listening',
        'cellar',
        'beer',
        'spirits',
        'kitchen',
        'library',
        'code',
    ];

    protected $fillable = [
        'user_id',
        'module',
        'type',
        'subject_type',
        'subject_id',
        'title',
        'body',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
            'subject_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('occurred_at', '<', now()->subMonths(18));
    }
}
