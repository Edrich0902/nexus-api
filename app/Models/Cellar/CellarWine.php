<?php

namespace App\Models\Cellar;

use App\Models\Concerns\HasCoverImage;
use App\Models\Concerns\HasDrinkAnalysis;
use App\Models\User;
use App\Models\WineCatalog\WineCatalogWine;
use Database\Factories\CellarWineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CellarWine extends Model
{
    /** @use HasFactory<CellarWineFactory> */
    use HasCoverImage;
    use HasDrinkAnalysis;
    use HasFactory;
    use SoftDeletes;

    public const MATCH_UNMATCHED = 'unmatched';

    public const MATCH_MATCHING = 'matching';

    public const MATCH_MATCHED = 'matched';

    public const MATCH_NO_MATCH = 'no_match';

    protected static function newFactory(): CellarWineFactory
    {
        return CellarWineFactory::new();
    }
    protected $fillable = [
        'user_id',
        'wine_catalog_wine_id',
        'media_asset_id',
        'media_public_id',
        'media_url',
        'producer_name',
        'name',
        'vintage',
        'wine_type',
        'region_name',
        'country',
        'rating',
        'notes',
        'match_status',
        'analysis_status',
        'analysed_at',
        'analysis_model',
        'analysis_prompt_version',
        'analysis_error',
        'ai_analysis',
        'analysis_media_asset_id',
    ];

    protected function casts(): array
    {
        return [
            'vintage' => 'integer',
            'rating' => 'float',
            'ai_analysis' => 'array',
            'analysed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function catalogWine(): BelongsTo
    {
        return $this->belongsTo(WineCatalogWine::class, 'wine_catalog_wine_id');
    }

    public function tastings(): HasMany
    {
        return $this->hasMany(CellarWineTasting::class, 'cellar_wine_id');
    }
}
