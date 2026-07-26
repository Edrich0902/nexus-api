<?php

namespace App\Models\Media;

use App\Models\User;
use Database\Factories\MediaAssetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MediaAsset extends Model
{
    /** @use HasFactory<MediaAssetFactory> */
    use HasFactory;
    use SoftDeletes;

    public const SOURCE_UPLOAD = 'upload';

    public const SOURCE_UNSPLASH = 'unsplash';

    public const SOURCE_MIRROR = 'mirror';

    protected $fillable = [
        'user_id',
        'public_id',
        'asset_id',
        'folder',
        'collection',
        'secure_url',
        'format',
        'resource_type',
        'bytes',
        'width',
        'height',
        'version',
        'etag',
        'alt_text',
        'source',
        'source_provider',
        'source_ref',
        'source_meta',
        'tags',
    ];

    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'version' => 'integer',
            'source_meta' => 'array',
            'tags' => 'array',
        ];
    }

    protected static function newFactory(): MediaAssetFactory
    {
        return MediaAssetFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Mediable::class, 'media_asset_id');
    }

    /**
     * @return array{id: int, public_id: string, url: string}
     */
    public function toImagePayload(): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'url' => $this->secure_url,
        ];
    }
}
