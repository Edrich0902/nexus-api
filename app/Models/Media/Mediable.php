<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Mediable extends Model
{
    protected $table = 'mediables';

    protected $fillable = [
        'media_asset_id',
        'mediable_type',
        'mediable_id',
        'role',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function mediaAsset(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'media_asset_id');
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }
}
