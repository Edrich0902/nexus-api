<?php

namespace App\Models\F1;

use Illuminate\Database\Eloquent\Model;

class F1ChampionshipTeam extends Model
{
    protected $table = 'f1_championship_teams';

    protected $fillable = [
        'meeting_key',
        'session_key',
        'year',
        'team_name',
        'position_current',
        'position_start',
        'points_current',
        'points_start',
    ];

    protected function casts(): array
    {
        return [
            'meeting_key' => 'integer',
            'session_key' => 'integer',
            'year' => 'integer',
            'position_current' => 'integer',
            'position_start' => 'integer',
            'points_current' => 'float',
            'points_start' => 'float',
        ];
    }
}
