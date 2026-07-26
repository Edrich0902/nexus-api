<?php

namespace App\Models\WineCatalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WineCatalogPairing extends Model
{
    protected $fillable = [
        'wine_catalog_wine_id',
        'food',
        'confidence',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
        ];
    }

    public function wine(): BelongsTo
    {
        return $this->belongsTo(WineCatalogWine::class, 'wine_catalog_wine_id');
    }
}
