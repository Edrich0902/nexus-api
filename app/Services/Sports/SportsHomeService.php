<?php

namespace App\Services\Sports;

use App\Models\Sports\SportsEvent;
use App\Models\Sports\SportsHomeSnapshot;
use App\Models\Sports\SportsLeague;
use App\Models\Sports\SportsStanding;
use App\Models\Sports\SportsSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SportsHomeService
{
    /**
     * Sports highlighted on the hub home cards.
     *
     * @var list<string>
     */
    public const FEATURED_SPORTS = ['football', 'rugby', 'golf'];

    public function getSnapshot(): array
    {
        $row = SportsHomeSnapshot::query()->where('key', 'default')->first();

        if ($row === null) {
            return $this->rebuild();
        }

        $payload = $row->payload ?? [];

        // Rebuild if older payload shape is missing featured lanes or starts_at.
        if (! isset($payload['featured']) || ! is_array($payload['featured'])) {
            return $this->rebuild();
        }

        $sample = $payload['upcoming'][0] ?? $payload['featured']['football']['next'] ?? null;
        if (is_array($sample) && ! array_key_exists('starts_at', $sample)) {
            return $this->rebuild();
        }

        return array_merge($payload, [
            'computed_at' => $row->computed_at?->toIso8601String(),
        ]);
    }

    public function rebuild(): array
    {
        $now = Carbon::now('UTC');
        $weekEnd = $now->copy()->addDays(7);

        $featured = [];
        foreach (self::FEATURED_SPORTS as $slug) {
            $upcoming = SportsEvent::query()
                ->where('sport_slug', $slug)
                ->where('starts_at', '>=', $now)
                ->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereNotIn('status', ['FT', 'AET', 'PEN', 'Match Finished']);
                })
                ->whereNull('home_score')
                ->whereNull('away_score')
                ->orderBy('starts_at')
                ->limit(3)
                ->get();

            $recent = SportsEvent::query()
                ->where('sport_slug', $slug)
                ->where('starts_at', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->where('starts_at', '<', $now->copy()->startOfDay())
                        ->orWhere(function ($done) {
                            $done->whereIn('status', ['FT', 'AET', 'PEN', 'Match Finished'])
                                ->orWhereNotNull('home_score')
                                ->orWhereNotNull('away_score')
                                ->orWhere(function ($rt) {
                                    $rt->whereNotNull('result_text')
                                        ->where('result_text', '!=', '');
                                });
                        });
                })
                ->orderByDesc('starts_at')
                ->limit(2)
                ->get();

            $featured[$slug] = [
                'upcoming' => $upcoming->map(fn (SportsEvent $e) => $this->mapEvent($e))->values()->all(),
                'recent' => $recent->map(fn (SportsEvent $e) => $this->mapEvent($e))->values()->all(),
                'next' => $upcoming->first() ? $this->mapEvent($upcoming->first()) : null,
                'last' => $recent->first() ? $this->mapEvent($recent->first()) : null,
            ];
        }

        $upcoming = $this->pickFeaturedFlat($featured, 'upcoming', 6);
        $recent = $this->pickFeaturedFlat($featured, 'recent', 6);

        $bySport = SportsEvent::query()
            ->whereIn('sport_slug', self::FEATURED_SPORTS)
            ->whereBetween('starts_at', [$now, $weekEnd])
            ->selectRaw('sport_slug, count(*) as total')
            ->groupBy('sport_slug')
            ->pluck('total', 'sport_slug')
            ->all();

        $tableLeaders = [];
        $premier = SportsStanding::query()
            ->whereHas('league', fn ($q) => $q->where('sportsdb_id', 4328))
            ->with('league')
            ->first();

        if ($premier !== null) {
            $rows = $premier->rows ?? [];
            $tableLeaders[] = [
                'league' => $premier->league?->name,
                'sport_slug' => 'football',
                'season' => $premier->season,
                'top' => array_map(static function (array $row): array {
                    return [
                        'team' => $row['strTeam'] ?? $row['name'] ?? null,
                        'badge' => $row['strBadge'] ?? null,
                        'played' => $row['intPlayed'] ?? null,
                        'points' => $row['intPoints'] ?? null,
                        'rank' => $row['intRank'] ?? null,
                    ];
                }, array_slice($rows, 0, 5)),
            ];
        }

        $lastSync = SportsSyncRun::query()
            ->where('status', SportsSyncRun::STATUS_OK)
            ->orderByDesc('finished_at')
            ->first();

        $payload = [
            'featured' => $featured,
            'featured_sports' => self::FEATURED_SPORTS,
            'upcoming' => $upcoming,
            'recent' => $recent,
            'next_majors' => [],
            'events_this_week_by_sport' => $bySport,
            'football_table_leaders' => $tableLeaders,
            'league_count' => SportsLeague::query()->count(),
            'event_count' => SportsEvent::query()->count(),
            'last_sync_at' => $lastSync?->finished_at?->toIso8601String(),
        ];

        SportsHomeSnapshot::query()->updateOrCreate(
            ['key' => 'default'],
            [
                'payload' => $payload,
                'computed_at' => now(),
            ],
        );

        return array_merge($payload, [
            'computed_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, array{upcoming: list<array<string, mixed>>, recent: list<array<string, mixed>>}>  $featured
     * @return list<array<string, mixed>>
     */
    private function pickFeaturedFlat(array $featured, string $key, int $limit): array
    {
        /** @var Collection<int, array<string, mixed>> $items */
        $items = collect();

        foreach (self::FEATURED_SPORTS as $slug) {
            foreach ($featured[$slug][$key] ?? [] as $event) {
                $items->push($event);
            }
        }

        return $items
            ->sortBy(function (array $event) {
                return (string) ($event['starts_at'] ?? '1970-01-01T00:00:00+00:00');
            }, descending: $key === 'recent')
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function mapEvent(SportsEvent $event): array
    {
        return [
            'id' => $event->id,
            'sportsdb_id' => $event->sportsdb_id,
            'sport_slug' => $event->sport_slug,
            'name' => $event->name,
            'league_name' => $event->league_name,
            'event_date' => $event->starts_at?->toDateString() ?? $event->event_date?->toDateString(),
            'starts_at' => $event->starts_at?->toIso8601String(),
            'status' => $event->status,
            'home_team' => $event->home_team,
            'away_team' => $event->away_team,
            'home_badge_url' => $event->home_badge_url,
            'away_badge_url' => $event->away_badge_url,
            'league_badge_url' => $event->league_badge_url,
            'home_score' => $event->home_score,
            'away_score' => $event->away_score,
            'venue' => $event->venue,
            'thumb_url' => $event->thumb_url,
            'result_text' => SportsResultText::clean($event->result_text),
            'is_major' => $event->is_major,
            'series' => $event->series,
        ];
    }
}
