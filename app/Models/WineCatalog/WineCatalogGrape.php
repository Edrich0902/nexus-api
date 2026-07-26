<?php

namespace App\Models\WineCatalog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WineCatalogGrape extends Model
{
    use HasFactory;

    protected $fillable = [
        'wineapi_id',
        'name',
        'color',
    ];

    public function wines(): BelongsToMany
    {
        return $this->belongsToMany(
            WineCatalogWine::class,
            'wine_catalog_wine_grape',
            'wine_catalog_grape_id',
            'wine_catalog_wine_id',
        );
    }
}
