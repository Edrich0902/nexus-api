<?php

namespace App\Models\Beer;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeerStyle extends Model
{
    protected $fillable = [
        'slug',
        'name',
        'family',
        'description',
    ];

    public function beers(): HasMany
    {
        return $this->hasMany(BeerBeer::class, 'beer_style_id');
    }
}
