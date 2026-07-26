<?php

namespace App\Models\WineCatalog;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WineCatalogWinery extends Model
{
    use HasFactory;

    protected $fillable = [
        'wineapi_id',
        'name',
    ];

    public function wines(): HasMany
    {
        return $this->hasMany(WineCatalogWine::class, 'wine_catalog_winery_id');
    }
}
