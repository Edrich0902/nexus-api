<?php

namespace App\Models\Cellar;

use App\Models\User;
use Database\Factories\CellarWineTastingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CellarWineTasting extends Model
{
    /** @use HasFactory<CellarWineTastingFactory> */
    use HasFactory;

    protected static function newFactory(): CellarWineTastingFactory
    {
        return CellarWineTastingFactory::new();
    }
    protected $fillable = [
        'user_id',
        'cellar_wine_id',
        'tasted_on',
        'rating',
        'notes',
        'occasion',
        'location',
    ];

    protected function casts(): array
    {
        return [
            'tasted_on' => 'date',
            'rating' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wine(): BelongsTo
    {
        return $this->belongsTo(CellarWine::class, 'cellar_wine_id');
    }
}
