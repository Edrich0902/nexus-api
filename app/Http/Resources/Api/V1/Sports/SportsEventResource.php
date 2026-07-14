<?php

namespace App\Http\Resources\Api\V1\Sports;

use App\Services\Sports\SportsResultText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Sports\SportsEvent */
class SportsEventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sportsdb_id' => $this->sportsdb_id,
            'sport_slug' => $this->sport_slug,
            'name' => $this->name,
            'league_name' => $this->league_name,
            'league' => $this->whenLoaded('league', fn () => [
                'id' => $this->league?->id,
                'name' => $this->league?->name,
                'badge_url' => $this->league?->badge_url,
            ]),
            'event_date' => $this->starts_at?->toDateString() ?? $this->event_date?->toDateString(),
            'starts_at' => $this->starts_at?->toIso8601String(),
            'status' => $this->status,
            'home_team' => $this->home_team,
            'away_team' => $this->away_team,
            'home_badge_url' => $this->home_badge_url,
            'away_badge_url' => $this->away_badge_url,
            'league_badge_url' => $this->league_badge_url,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'venue' => $this->venue,
            'country' => $this->country,
            'thumb_url' => $this->thumb_url,
            'result_text' => SportsResultText::clean($this->result_text),
            'is_major' => $this->is_major,
            'series' => $this->series,
        ];
    }
}
