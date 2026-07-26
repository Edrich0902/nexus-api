<?php

namespace App\Models\WineCatalog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WineCatalogRegion extends Model
{
    use HasFactory;

    protected $fillable = [
        'wineapi_id',
        'name',
        'country',
    ];

    public function wines(): HasMany
    {
        return $this->hasMany(WineCatalogWine::class, 'wine_catalog_region_id');
    }
}
