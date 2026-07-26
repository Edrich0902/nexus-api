<?php

namespace App\Models\WineCatalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WineCatalogPrice extends Model
{
    protected $fillable = [
        'wine_catalog_wine_id',
        'merchant_name',
        'price',
        'currency',
        'url',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'float',
            'fetched_at' => 'datetime',
        ];
    }

    public function wine(): BelongsTo
    {
        return $this->belongsTo(WineCatalogWine::class, 'wine_catalog_wine_id');
    }
}
