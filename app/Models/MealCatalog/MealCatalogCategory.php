<?php

namespace App\Models\MealCatalog;

use Illuminate\Database\Eloquent\Model;

class MealCatalogCategory extends Model
{
    protected $fillable = [
        'name',
        'thumb_url',
        'description',
    ];
}
