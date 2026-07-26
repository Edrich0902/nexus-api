<?php

namespace App\Models\WineCatalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WineCatalogScore extends Model
{
    protected $fillable = [
        'wine_catalog_wine_id',
        'score',
        'score_text',
        'reviewer',
        'review_date',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'review_date' => 'date',
        ];
    }

    public function wine(): BelongsTo
    {
        return $this->belongsTo(WineCatalogWine::class, 'wine_catalog_wine_id');
    }
}
