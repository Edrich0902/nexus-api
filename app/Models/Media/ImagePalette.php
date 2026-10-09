<?php

namespace App\Models\Media;

use Illuminate\Database\Eloquent\Model;

class ImagePalette extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'url_hash',
        'url',
        'status',
        'palette',
        'attempts',
    ];

    protected function casts(): array
    {
        return [
            'palette' => 'array',
            'attempts' => 'integer',
        ];
    }

    public static function hashFor(string $url): string
    {
        return hash('sha256', $url);
    }
}
