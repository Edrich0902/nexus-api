<?php

namespace App\Models\WineCatalog;

use App\Models\Concerns\HasCoverImage;
use Database\Factories\WineCatalogWineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WineCatalogWine extends Model
{
    /** @use HasFactory<WineCatalogWineFactory> */
    use HasCoverImage;
    use HasFactory;

    public const ENRICHMENT_PENDING = 'pending';

    public const ENRICHMENT_QUEUED = 'queued';

    public const ENRICHMENT_COMPLETE = 'complete';

    public const ENRICHMENT_FAILED = 'failed';

    protected static function newFactory(): WineCatalogWineFactory
    {
        return WineCatalogWineFactory::new();
    }
    protected $fillable = [
        'wineapi_id',
        'wine_catalog_winery_id',
        'wine_catalog_region_id',
        'name',
        'vintage',
        'type',
        'body',
        'acidity',
        'elaborate',
        'classification',
        'appellation',
        'average_rating',
        'ratings_count',
        'alcohol_content',
        'description',
        'lwin_code',
        'image_url',
        'media_asset_id',
        'media_public_id',
        'media_url',
        'enrichment_status',
        'enriched_at',
        'enrichment_attempts',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'vintage' => 'integer',
            'average_rating' => 'float',
            'ratings_count' => 'integer',
            'alcohol_content' => 'float',
            'enriched_at' => 'datetime',
            'enrichment_attempts' => 'integer',
            'raw' => 'array',
        ];
    }

    public function winery(): BelongsTo
    {
        return $this->belongsTo(WineCatalogWinery::class, 'wine_catalog_winery_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(WineCatalogRegion::class, 'wine_catalog_region_id');
    }

    public function grapes(): BelongsToMany
    {
        return $this->belongsToMany(
            WineCatalogGrape::class,
            'wine_catalog_wine_grape',
            'wine_catalog_wine_id',
            'wine_catalog_grape_id',
        );
    }

    public function scores(): HasMany
    {
        return $this->hasMany(WineCatalogScore::class, 'wine_catalog_wine_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(WineCatalogPrice::class, 'wine_catalog_wine_id');
    }

    public function pairings(): HasMany
    {
        return $this->hasMany(WineCatalogPairing::class, 'wine_catalog_wine_id');
    }
}
