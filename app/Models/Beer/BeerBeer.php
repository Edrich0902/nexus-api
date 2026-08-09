<?php

namespace App\Models\Beer;

use App\Models\Concerns\HasCoverImage;
use App\Models\Concerns\HasDrinkAnalysis;
use App\Models\User;
use Database\Factories\BeerBeerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BeerBeer extends Model
{
    /** @use HasFactory<BeerBeerFactory> */
    use HasCoverImage;
    use HasDrinkAnalysis;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'beer_brewery_id',
        'beer_style_id',
        'media_asset_id',
        'media_public_id',
        'media_url',
        'name',
        'abv',
        'ibu',
        'format',
        'rating',
        'notes',
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
            'abv' => 'float',
            'ibu' => 'integer',
            'rating' => 'float',
            'ai_analysis' => 'array',
            'analysed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): BeerBeerFactory
    {
        return BeerBeerFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function brewery(): BelongsTo
    {
        return $this->belongsTo(BeerBrewery::class, 'beer_brewery_id');
    }

    public function style(): BelongsTo
    {
        return $this->belongsTo(BeerStyle::class, 'beer_style_id');
    }
}
