<?php

namespace App\Models\MealCatalog;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MealCatalogIngredient extends Model
{
    protected $fillable = [
        'name',
        'thumb_url',
        'description',
    ];

    public function meals(): BelongsToMany
    {
        return $this->belongsToMany(
            MealCatalogMeal::class,
            'meal_catalog_meal_ingredient',
            'meal_catalog_ingredient_id',
            'meal_catalog_meal_id',
        )->withPivot(['measure', 'position']);
    }
}
