<?php

namespace App\Services\Sports;

use App\Integrations\SportsDb\SportsDbIntegration;
use App\Integrations\Exceptions\IntegrationException;
use App\Models\Sports\SportsEvent;
use App\Models\Sports\SportsLeague;
use App\Models\Sports\SportsStanding;
use App\Models\Sports\SportsSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SportsSyncService
{
    public function __construct(
        private readonly SportsDbIntegration $sportsDb,
        private readonly SportsHomeService $home,
    ) {}

    /**
     * @return list<string>
     */
    public function sportSlugs(): array
    {
        return array_keys(config('services.sportsdb.leagues', []));
    }

    public function isValidSport(string $slug): bool
    {
        return array_key_exists($slug, config('services.sportsdb.leagues', []));
    }

    /**
     * @return Collection<int, array{id: int, name: string, sport_slug: string}>
     */
    public function whitelistedLeagues(?string $sportSlug = null): Collection
    {
        $leagues = collect();

        /** @var array<string, list<array{id: int, name: string}>> $config */
        $config = config('services.sportsdb.leagues', []);

        foreach ($config as $slug => $items) {
            if ($sportSlug !== null && $slug !== $sportSlug) {
                continue;
            }

            foreach ($items as $item) {
                $leagues->push([
                    'id' => (int) $item['id'],
                    'name' => (string) $item['name'],
                    'sport_slug' => $slug,
                ]);
            }
        }

        return $leagues;
    }

    public function syncLeagues(): SportsSyncRun
    {
        return $this->run('leagues', function (SportsSyncRun $run): void {
            foreach ($this->whitelistedLeagues() as $entry) {
                $rows = $this->sportsDb->lookupLeague($entry['id']);
                $run->increment('calls_used');
                $remote = $rows[0] ?? null;

                SportsLeague::query()->updateOrCreate(
                    ['sportsdb_id' => $entry['id']],
                    [
                        'sport_slug' => $entry['sport_slug'],
                        'name' => is_array($remote)
                            ? (string) ($remote['strLeague'] ?? $entry['name'])
                            : $entry['name'],
                        'badge_url' => is_array($remote) && isset($remote['strBadge'])
                            ? (string) $remote['strBadge']
                            : null,
                        'meta' => is_array($remote) ? [
                            'sport' => $remote['strSport'] ?? null,
                            'country' => $remote['strCountry'] ?? null,
                            'current_season' => $remote['strCurrentSeason'] ?? null,
                            'description' => $remote['strDescriptionEN'] ?? null,
                        ] : null,
                        'last_synced_at' => now(),
                    ],
                );
            }
        }, rebuildHome: false);
    }

    /**
     * Sync next + past fixtures for a slice of the whitelist.
     *
     * One queue worker invocation should only process {@see $chunkSize} leagues.
     * The job chains remaining slices so Laradock/low-memory containers are not OOM-killed.
     */
    public function syncFixtures(int $chunkSize = 3, ?int $offset = null, bool $rebuildHome = true): SportsSyncRun
    {
        return $this->run('fixtures', function (SportsSyncRun $run) use ($chunkSize, $offset): void {
            $all = $this->whitelistedLeagues()->values();
            $count = $all->count();
            if ($count === 0) {
                return;
            }

            $start = max(0, $offset ?? 0);
            if ($start >= $count) {
                return;
            }

            $this->syncFixtureChunk($all->slice($start, max(1, $chunkSize)), $run);
        }, $rebuildHome);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, array{id: int, name: string, sport_slug: string}>  $chunk
     */
    private function syncFixtureChunk(Collection $chunk, SportsSyncRun $run): void
    {
        $maxNext = max(1, (int) config('services.sportsdb.sync.max_next_events', 10));
        $maxPast = max(1, (int) config('services.sportsdb.sync.max_past_events', 10));
        $now = now();

        foreach ($chunk as $entry) {
            $league = SportsLeague::query()->firstOrCreate(
                ['sportsdb_id' => $entry['id']],
                [
                    'sport_slug' => $entry['sport_slug'],
                    'name' => $entry['name'],
                ],
            );

            $rows = [];

            try {
                $next = array_slice($this->sportsDb->listNextLeagueEvents($entry['id']), 0, $maxNext);
                $run->increment('calls_used');
            } catch (IntegrationException $e) {
                if ($e->statusCode === 429) {
                    throw $e;
                }
                $next = [];
            } catch (\Throwable) {
                $next = [];
            }

            try {
                $past = array_slice($this->sportsDb->listPastLeagueEvents($entry['id']), 0, $maxPast);
                $run->increment('calls_used');
            } catch (IntegrationException $e) {
                if ($e->statusCode === 429) {
                    throw $e;
                }
                $past = [];
            } catch (\Throwable) {
                $past = [];
            }

            foreach (array_merge($next, $past) as $event) {
                $row = $this->mapEventRow($event, $league, $entry['sport_slug'], $now);
                if ($row !== null) {
                    $rows[$row['sportsdb_id']] = $row;
                }
            }

            unset($next, $past);

            if ($rows !== []) {
                $this->upsertEventRows(array_values($rows));
            }

            $league->forceFill(['last_synced_at' => $now])->save();
            unset($rows);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertEventRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        SportsEvent::query()->upsert(
            $rows,
            ['sportsdb_id'],
            [
                'sports_league_id',
                'sport_slug',
                'name',
                'league_name',
                'event_date',
                'event_time',
                'status',
                'home_team',
                'away_team',
                'home_badge_url',
                'away_badge_url',
                'league_badge_url',
                'home_score',
                'away_score',
                'venue',
                'country',
                'thumb_url',
                'result_text',
                'is_major',
                'series',
                'raw',
                'updated_at',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>|null
     */
    private function mapEventRow(array $event, SportsLeague $league, string $sportSlug, Carbon $now): ?array
    {
        $sportsdbId = isset($event['idEvent']) ? (int) $event['idEvent'] : 0;
        if ($sportsdbId === 0) {
            return null;
        }

        $name = (string) ($event['strEvent'] ?? 'Event');
        [$isMajor, $series] = $this->majorFlags($sportSlug, $name);

        $homeTeam = isset($event['strHomeTeam']) && $event['strHomeTeam'] !== ''
            ? (string) $event['strHomeTeam']
            : null;
        $awayTeam = isset($event['strAwayTeam']) && $event['strAwayTeam'] !== ''
            ? (string) $event['strAwayTeam']
            : null;

        if ($homeTeam === null || $awayTeam === null) {
            [$parsedHome, $parsedAway] = $this->parseVsTeams($name, $series);
            $homeTeam = $homeTeam ?? $parsedHome;
            $awayTeam = $awayTeam ?? $parsedAway;
        }

        $resultText = isset($event['strResult']) && is_string($event['strResult'])
            ? SportsResultText::clean($event['strResult'])
            : null;
        $status = isset($event['strStatus']) && $event['strStatus'] !== ''
            ? (string) $event['strStatus']
            : ($resultText !== null ? 'FT' : null);

        return [
            'sportsdb_id' => $sportsdbId,
            'sports_league_id' => $league->id,
            'sport_slug' => $sportSlug,
            'name' => $name,
            'league_name' => isset($event['strLeague']) ? (string) $event['strLeague'] : $league->name,
            'event_date' => isset($event['dateEvent']) && is_string($event['dateEvent'])
                ? $event['dateEvent']
                : null,
            'event_time' => isset($event['strTime']) ? (string) $event['strTime'] : null,
            'status' => $status,
            'home_team' => $homeTeam,
            'away_team' => $awayTeam,
            'home_badge_url' => isset($event['strHomeTeamBadge']) ? (string) $event['strHomeTeamBadge'] : null,
            'away_badge_url' => isset($event['strAwayTeamBadge']) ? (string) $event['strAwayTeamBadge'] : null,
            'league_badge_url' => isset($event['strLeagueBadge']) ? (string) $event['strLeagueBadge'] : null,
            'home_score' => $this->nullableInt($event['intHomeScore'] ?? null),
            'away_score' => $this->nullableInt($event['intAwayScore'] ?? null),
            'venue' => isset($event['strVenue']) ? (string) $event['strVenue'] : null,
            'country' => isset($event['strCountry']) ? (string) $event['strCountry'] : null,
            'thumb_url' => isset($event['strThumb']) ? (string) $event['strThumb'] : null,
            'result_text' => $resultText,
            'is_major' => $isMajor,
            'series' => $series,
            'raw' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function upsertEvent(array $event, SportsLeague $league, string $sportSlug): void
    {
        $row = $this->mapEventRow($event, $league, $sportSlug, now());
        if ($row === null) {
            return;
        }

        unset($row['created_at'], $row['updated_at']);

        SportsEvent::query()->updateOrCreate(
            ['sportsdb_id' => $row['sportsdb_id']],
            $row,
        );
    }

    /**
     * Pull a short day slate for one sport (or all, when $sportSlug is null).
     * Prefer fixtures sync for depth — day jobs stay tiny to avoid OOM.
     */
    public function syncDay(?Carbon $day = null, ?string $sportSlug = null): SportsSyncRun
    {
        return $this->run('day', function (SportsSyncRun $run) use ($day, $sportSlug): void {
            $base = $day?->copy() ?? now();
            $sportNames = config('services.sportsdb.sport_api_names', []);
            if ($sportSlug !== null) {
                if (! isset($sportNames[$sportSlug])) {
                    return;
                }
                $sportNames = [$sportSlug => $sportNames[$sportSlug]];
            }

            $allowedIds = $this->whitelistedLeagues()->pluck('id')->all();
            $maxPerDay = max(1, (int) config('services.sportsdb.sync.max_day_events', 40));

            /** @var array<string, int> $lookback */
            $lookback = config('services.sportsdb.sync.day_lookback', [
                'tennis' => 2,
                'golf' => 2,
                'darts' => 1,
                'field-hockey' => 2,
                'football' => 1,
                'rugby' => 1,
            ]);

            $now = now();

            foreach ($sportNames as $slug => $apiName) {
                $days = max(1, (int) ($lookback[$slug] ?? 1));
                $dates = [];
                for ($i = 0; $i < $days; $i++) {
                    $dates[] = $base->copy()->subDays($i)->toDateString();
                }
                $dates[] = $base->copy()->addDay()->toDateString();

                foreach (array_unique($dates) as $date) {
                    try {
                        $events = array_slice(
                            $this->sportsDb->eventsOnDay($date, $apiName),
                            0,
                            $maxPerDay,
                        );
                        $run->increment('calls_used');
                    } catch (IntegrationException $e) {
                        if ($e->statusCode === 429) {
                            throw $e;
                        }
                        continue;
                    } catch (\Throwable) {
                        continue;
                    }

                    $rows = [];
                    foreach ($events as $event) {
                        $leagueId = isset($event['idLeague']) ? (int) $event['idLeague'] : 0;
                        if ($leagueId === 0) {
                            continue;
                        }

                        $eventName = (string) ($event['strEvent'] ?? '');
                        [$isMajor] = $this->majorFlags($slug, $eventName);
                        $allowed = in_array($leagueId, $allowedIds, true);

                        if (! $allowed && ! ($slug === 'tennis' && $isMajor)) {
                            continue;
                        }

                        $league = SportsLeague::query()->firstOrCreate(
                            ['sportsdb_id' => $leagueId],
                            [
                                'sport_slug' => $slug,
                                'name' => (string) ($event['strLeague'] ?? 'League'),
                            ],
                        );

                        $row = $this->mapEventRow($event, $league, $slug, $now);
                        if ($row !== null) {
                            $rows[$row['sportsdb_id']] = $row;
                        }
                    }

                    unset($events);
                    $this->upsertEventRows(array_values($rows));
                    unset($rows);
                }
            }
        }, rebuildHome: false);
    }

    public function syncStandings(): SportsSyncRun
    {
        return $this->run('standings', function (SportsSyncRun $run): void {
            foreach ($this->whitelistedLeagues('football') as $entry) {
                $league = SportsLeague::query()->firstOrCreate(
                    ['sportsdb_id' => $entry['id']],
                    [
                        'sport_slug' => 'football',
                        'name' => $entry['name'],
                    ],
                );

                try {
                    $rows = $this->sportsDb->lookupTable($entry['id']);
                    $run->increment('calls_used');
                } catch (IntegrationException $e) {
                    if ($e->statusCode === 429) {
                        throw $e;
                    }
                    continue;
                } catch (\Throwable) {
                    continue;
                }

                if ($rows === []) {
                    continue;
                }

                $season = isset($rows[0]['strSeason']) ? (string) $rows[0]['strSeason'] : null;

                SportsStanding::query()->updateOrCreate(
                    [
                        'sports_league_id' => $league->id,
                        'season' => $season ?? 'current',
                    ],
                    [
                        'rows' => $rows,
                        'synced_at' => now(),
                    ],
                );
            }
        }, rebuildHome: false);
    }

    public function rebuildHomeSnapshot(): void
    {
        $this->home->rebuild();
    }

    /**
     * @param  callable(SportsSyncRun): void  $callback
     */
    private function run(string $job, callable $callback, bool $rebuildHome = true): SportsSyncRun
    {
        $run = SportsSyncRun::query()->create([
            'provider' => SportsDbIntegration::PROVIDER,
            'job' => $job,
            'status' => SportsSyncRun::STATUS_RUNNING,
            'calls_used' => 0,
            'started_at' => now(),
        ]);

        try {
            $callback($run);
            $run->forceFill([
                'status' => SportsSyncRun::STATUS_OK,
                'finished_at' => now(),
            ])->save();

            if ($rebuildHome) {
                try {
                    $this->home->rebuild();
                } catch (\Throwable) {
                    // Snapshot rebuild is best-effort after sync.
                }
            }
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => SportsSyncRun::STATUS_FAILED,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        return $run->fresh();
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function parseVsTeams(string $eventName, ?string $series): array
    {
        if (! preg_match('/^(.*?)\s+vs\.?\s+(.*)$/i', $eventName, $matches)) {
            return [null, null];
        }

        $left = trim($matches[1]);
        $away = trim($matches[2]);
        if ($left === '' || $away === '') {
            return [null, null];
        }

        $prefixes = array_values(array_filter([
            $series,
            ...((array) config('services.sportsdb.major_keywords.tennis', [])),
            ...((array) config('services.sportsdb.major_keywords.golf', [])),
        ]));

        usort($prefixes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        foreach ($prefixes as $prefix) {
            if (! is_string($prefix) || $prefix === '') {
                continue;
            }
            if (stripos($left, $prefix) === 0) {
                $home = trim(substr($left, strlen($prefix)));
                if ($home !== '') {
                    return [$home, $away];
                }
            }
        }

        return [$left, $away];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function majorFlags(string $sportSlug, string $eventName): array
    {
        /** @var array<string, list<string>> $keywords */
        $keywords = config('services.sportsdb.major_keywords', []);
        $list = $keywords[$sportSlug] ?? [];

        foreach ($list as $keyword) {
            if ($keyword !== '' && stripos($eventName, $keyword) !== false) {
                return [true, $keyword];
            }
        }

        return [false, null];
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }
}
