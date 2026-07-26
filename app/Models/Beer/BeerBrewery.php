<?php

namespace App\Models\Beer;

use App\Models\User;
use Database\Factories\BeerBreweryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BeerBrewery extends Model
{
    /** @use HasFactory<BeerBreweryFactory> */
    use HasFactory;

    public const SOURCE_OPENBREWERYDB = 'openbrewerydb';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'obdb_id',
        'name',
        'brewery_type',
        'address_1',
        'address_2',
        'address_3',
        'city',
        'state_province',
        'postal_code',
        'country',
        'latitude',
        'longitude',
        'phone',
        'website_url',
        'source',
        'created_by_user_id',
        'raw',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'raw' => 'array',
        ];
    }

    protected static function newFactory(): BeerBreweryFactory
    {
        return BeerBreweryFactory::new();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function beers(): HasMany
    {
        return $this->hasMany(BeerBeer::class, 'beer_brewery_id');
    }
}
