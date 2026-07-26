<?php

namespace App\Models\MealCatalog;

use Database\Factories\MealCatalogMealFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MealCatalogMeal extends Model
{
    /** @use HasFactory<MealCatalogMealFactory> */
    use HasFactory;

    protected static function newFactory(): MealCatalogMealFactory
    {
        return MealCatalogMealFactory::new();
    }

    protected $fillable = [
        'mealdb_id',
        'name',
        'category',
        'area',
        'instructions',
        'thumb_url',
        'tags',
        'youtube_url',
        'source_url',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'raw' => 'array',
        ];
    }

    public function ingredients(): BelongsToMany
    {
        return $this->belongsToMany(
            MealCatalogIngredient::class,
            'meal_catalog_meal_ingredient',
            'meal_catalog_meal_id',
            'meal_catalog_ingredient_id',
        )->withPivot(['measure', 'position'])->orderByPivot('position');
    }
}
