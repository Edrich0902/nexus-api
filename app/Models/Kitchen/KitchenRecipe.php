<?php

namespace App\Models\Kitchen;

use App\Models\Concerns\HasCoverImage;
use App\Models\MealCatalog\MealCatalogMeal;
use App\Models\User;
use Database\Factories\KitchenRecipeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class KitchenRecipe extends Model
{
    /** @use HasFactory<KitchenRecipeFactory> */
    use HasCoverImage;
    use HasFactory;
    use SoftDeletes;

    public const SOURCE_MEALDB = 'mealdb';

    protected $fillable = [
        'user_id',
        'meal_catalog_meal_id',
        'media_asset_id',
        'media_public_id',
        'media_url',
        'source',
        'rating',
        'notes',
        'cooked_count',
        'last_cooked_on',
        'is_favourite',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'float',
            'cooked_count' => 'integer',
            'last_cooked_on' => 'date',
            'is_favourite' => 'boolean',
        ];
    }

    protected static function newFactory(): KitchenRecipeFactory
    {
        return KitchenRecipeFactory::new();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function meal(): BelongsTo
    {
        return $this->belongsTo(MealCatalogMeal::class, 'meal_catalog_meal_id');
    }
}
