<?php

namespace App\Models\Github;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubStatsSnapshot extends Model
{
    protected $fillable = [
        'user_id',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
