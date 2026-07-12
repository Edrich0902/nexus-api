<?php

namespace App\Models\Github;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GithubRepo extends Model
{
    protected $fillable = [
        'user_id',
        'github_id',
        'owner_login',
        'name',
        'full_name',
        'private',
        'default_branch',
        'html_url',
        'description',
        'pushed_at',
        'language',
        'starred',
    ];

    protected function casts(): array
    {
        return [
            'private' => 'boolean',
            'starred' => 'boolean',
            'pushed_at' => 'datetime',
            'github_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
