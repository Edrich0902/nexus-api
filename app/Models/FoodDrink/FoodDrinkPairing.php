<?php

namespace App\Models\FoodDrink;

use App\Models\Kitchen\KitchenRecipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class FoodDrinkPairing extends Model
{
    public const VERDICT_GREAT = 'great';

    public const VERDICT_GOOD = 'good';

    public const VERDICT_POOR = 'poor';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_SUGGESTED = 'suggested';

    protected $fillable = [
        'user_id',
        'drinkable_type',
        'drinkable_id',
        'kitchen_recipe_id',
        'verdict',
        'notes',
        'source',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function drinkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(KitchenRecipe::class, 'kitchen_recipe_id');
    }
}
