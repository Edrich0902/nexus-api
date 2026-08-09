<?php

namespace App\Models\Spirit;

use App\Models\Concerns\HasCoverImage;
use App\Models\Concerns\HasDrinkAnalysis;
use App\Models\User;
use Database\Factories\SpiritSpiritFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SpiritSpirit extends Model
{
    /** @use HasFactory<SpiritSpiritFactory> */
    use HasCoverImage;
    use HasDrinkAnalysis;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'name',
        'producer',
        'category',
        'age_statement',
        'abv',
        'region',
        'country',
        'rating',
        'notes',
        'analysis_status',
        'analysed_at',
        'analysis_model',
        'analysis_prompt_version',
        'analysis_error',
        'ai_analysis',
        'analysis_media_asset_id',
        'media_asset_id',
        'media_public_id',
        'media_url',
    ];

    protected function casts(): array
    {
        return [
            'abv' => 'float',
            'rating' => 'float',
            'ai_analysis' => 'array',
            'analysed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SpiritSpiritFactory
    {
        return SpiritSpiritFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
