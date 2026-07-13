<?php

namespace App\Services\Sports;

use App\Models\Sports\SportsEvent;
use App\Models\Sports\SportsLeague;
use App\Models\Sports\SportsStanding;
use App\Models\Sports\SportsSyncRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class SportsOverviewService
{
    public function __construct(
        private readonly SportsSyncService $sync,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $lastByJob = SportsSyncRun::query()
            ->where('status', SportsSyncRun::STATUS_OK)
            ->orderByDesc('finished_at')
            ->get()
            ->unique('job')
            ->mapWithKeys(fn (SportsSyncRun $run) => [
                $run->job => $run->finished_at?->toIso8601String(),
            ])
            ->all();

        return [
            'provider' => 'sportsdb',
            'league_count' => SportsLeague::query()->count(),
            'event_count' => SportsEvent::query()->count(),
            'sports' => $this->sync->sportSlugs(),
            'last_sync' => $lastByJob,
            'last_failed' => SportsSyncRun::query()
                ->where('status', SportsSyncRun::STATUS_FAILED)
                ->orderByDesc('finished_at')
                ->first([
                    'job',
                    'error',
                    'finished_at',
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(string $sportSlug): array
    {
        $this->assertSport($sportSlug);
        $today = Carbon::today();

        $leagues = SportsLeague::query()
            ->where('sport_slug', $sportSlug)
            ->orderBy('name')
            ->get();

        $upcoming = SportsEvent::query()
            ->where('sport_slug', $sportSlug)
            ->whereDate('event_date', '>=', $today)
            ->orderBy('event_date')
            ->orderBy('event_time')
            ->limit(12)
            ->get();

        $recent = SportsEvent::query()
            ->where('sport_slug', $sportSlug)
            ->whereDate('event_date', '<=', $today)
            ->orderByDesc('event_date')
            ->orderByDesc('event_time')
            ->limit(12)
            ->get();

        $majors = SportsEvent::query()
            ->where('sport_slug', $sportSlug)
            ->where('is_major', true)
            ->orderByDesc('event_date')
            ->limit(8)
            ->get();

        $standings = [];
        if ($sportSlug === 'football') {
            $standings = SportsStanding::query()
                ->whereIn('sports_league_id', $leagues->pluck('id'))
                ->with('league')
                ->get()
                ->map(fn (SportsStanding $s) => [
                    'league_id' => $s->sports_league_id,
                    'league' => $s->league?->name,
                    'season' => $s->season,
                    'synced_at' => $s->synced_at?->toIso8601String(),
                    'rows' => array_slice($s->rows ?? [], 0, 20),
                ])
                ->values()
                ->all();
        }

        return [
            'sport' => $sportSlug,
            'leagues' => $leagues->map(fn (SportsLeague $l) => [
                'id' => $l->id,
                'sportsdb_id' => $l->sportsdb_id,
                'name' => $l->name,
                'badge_url' => $l->badge_url,
                'last_synced_at' => $l->last_synced_at?->toIso8601String(),
            ])->values()->all(),
            'upcoming' => $upcoming->map(fn (SportsEvent $e) => $this->mapEvent($e))->values()->all(),
            'recent' => $recent->map(fn (SportsEvent $e) => $this->mapEvent($e))->values()->all(),
            'majors' => $majors->map(fn (SportsEvent $e) => $this->mapEvent($e))->values()->all(),
            'standings' => $standings,
        ];
    }

    public function events(string $sportSlug, int $perPage = 20): LengthAwarePaginator
    {
        $this->assertSport($sportSlug);

        return SportsEvent::query()
            ->where('sport_slug', $sportSlug)
            ->orderByDesc('event_date')
            ->orderByDesc('event_time')
            ->paginate(min(50, max(1, $perPage)));
    }

    public function event(int $id): SportsEvent
    {
        return SportsEvent::query()->with('league')->findOrFail($id);
    }

    private function assertSport(string $sportSlug): void
    {
        if (! $this->sync->isValidSport($sportSlug)) {
            throw ValidationException::withMessages([
                'sport' => "Unknown sport [{$sportSlug}].",
            ]);
        }
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
            'event_date' => $event->event_date?->toDateString(),
            'event_time' => $event->event_time,
            'status' => $event->status,
            'home_team' => $event->home_team,
            'away_team' => $event->away_team,
            'home_badge_url' => $event->home_badge_url,
            'away_badge_url' => $event->away_badge_url,
            'league_badge_url' => $event->league_badge_url,
            'home_score' => $event->home_score,
            'away_score' => $event->away_score,
            'venue' => $event->venue,
            'country' => $event->country,
            'thumb_url' => $event->thumb_url,
            'result_text' => SportsResultText::clean($event->result_text),
            'is_major' => $event->is_major,
            'series' => $event->series,
        ];
    }
}
