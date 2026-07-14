<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class F1Meeting extends Model
{
    protected $table = 'f1_meetings';

    protected $fillable = [
        'meeting_key',
        'year',
        'meeting_name',
        'meeting_official_name',
        'circuit_short_name',
        'circuit_key',
        'circuit_image',
        'circuit_info_url',
        'circuit_type',
        'country_code',
        'country_name',
        'country_flag',
        'country_key',
        'location',
        'gmt_offset',
        'date_start',
        'date_end',
        'is_cancelled',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'year' => 'integer',
            'circuit_key' => 'integer',
            'country_key' => 'integer',
            'date_start' => 'datetime',
            'date_end' => 'datetime',
            'is_cancelled' => 'boolean',
        ];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(F1Session::class, 'meeting_key', 'meeting_key');
    }
}
