<?php

namespace Tests\Feature\Api\V1\Sports;

use App\Jobs\Sports\SyncSportsDayJob;
use App\Jobs\Sports\SyncSportsFixturesJob;
use App\Jobs\Sports\SyncSportsLeaguesJob;
use App\Models\Sports\SportsEvent;
use App\Models\Sports\SportsLeague;
use App\Models\User;
use App\Services\Sports\SportsHomeService;
use App\Services\Sports\SportsSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SportsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sportsdb.api_key' => '123',
            'services.sportsdb.base_url' => 'https://www.thesportsdb.com/api/v1/json',
            'services.rate_limits.sportsdb' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
            ],
        ]);
    }

    public function test_status_requires_authentication(): void
    {
        $this->getJson('/api/v1/sports/status')->assertUnauthorized();
    }

    public function test_status_and_home_return_payloads(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        SportsLeague::query()->create([
            'sportsdb_id' => 4328,
            'sport_slug' => 'football',
            'name' => 'Premier League',
        ]);

        $this->getJson('/api/v1/sports/status')
            ->assertOk()
            ->assertJsonPath('provider', 'sportsdb')
            ->assertJsonPath('league_count', 1);

        $this->getJson('/api/v1/sports/home')
            ->assertOk()
            ->assertJsonStructure(['upcoming', 'recent', 'events_this_week_by_sport', 'league_count']);
    }

    public function test_sport_overview_is_scoped(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $league = SportsLeague::query()->create([
            'sportsdb_id' => 4328,
            'sport_slug' => 'football',
            'name' => 'Premier League',
        ]);

        SportsEvent::query()->create([
            'sportsdb_id' => 1001,
            'sports_league_id' => $league->id,
            'sport_slug' => 'football',
            'name' => 'Arsenal vs Chelsea',
            'event_date' => now()->addDay()->toDateString(),
            'starts_at' => now()->addDay(),
            'home_team' => 'Arsenal',
            'away_team' => 'Chelsea',
        ]);

        SportsEvent::query()->create([
            'sportsdb_id' => 1002,
            'sport_slug' => 'tennis',
            'name' => 'Player A vs Player B',
            'event_date' => now()->addDay()->toDateString(),
            'starts_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/v1/sports/football')
            ->assertOk()
            ->assertJsonPath('sport', 'football')
            ->assertJsonCount(1, 'upcoming')
            ->assertJsonPath('upcoming.0.name', 'Arsenal vs Chelsea');

        $startsAt = $response->json('upcoming.0.starts_at');
        $this->assertIsString($startsAt);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $startsAt);
    }

    public function test_sync_queues_jobs(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sports/sync', ['type' => 'fixtures'])
            ->assertOk()
            ->assertJsonPath('message', 'Sports sync queued.');

        Bus::assertDispatched(SyncSportsFixturesJob::class);
    }

    public function test_sync_all_queues_core_jobs(): void
    {
        Bus::fake();
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sports/sync')->assertOk();

        Bus::assertDispatched(SyncSportsLeaguesJob::class);
        Bus::assertDispatched(SyncSportsFixturesJob::class);
    }

    public function test_fixtures_sync_upserts_events_from_http(): void
    {
        Http::fake([
            '*/eventsnextleague.php*' => Http::response([
                'events' => [[
                    'idEvent' => '555001',
                    'idLeague' => '4328',
                    'strEvent' => 'Arsenal vs Liverpool',
                    'strLeague' => 'Premier League',
                    'dateEvent' => now()->addDays(2)->toDateString(),
                    'strTime' => '15:00:00',
                    'strHomeTeam' => 'Arsenal',
                    'strAwayTeam' => 'Liverpool',
                    'strStatus' => 'NS',
                ]],
            ], 200),
            '*/eventspastleague.php*' => Http::response([
                'events' => [[
                    'idEvent' => '555002',
                    'idLeague' => '4328',
                    'strEvent' => 'Chelsea vs Spurs',
                    'strLeague' => 'Premier League',
                    'dateEvent' => now()->subDay()->toDateString(),
                    'strHomeTeam' => 'Chelsea',
                    'strAwayTeam' => 'Spurs',
                    'intHomeScore' => 2,
                    'intAwayScore' => 1,
                    'strStatus' => 'FT',
                ]],
            ], 200),
        ]);

        config([
            'services.sportsdb.leagues' => [
                'football' => [
                    ['id' => 4328, 'name' => 'Premier League'],
                ],
            ],
            'services.sportsdb.sport_api_names' => [
                'football' => 'Soccer',
            ],
            'services.sportsdb.major_keywords' => [],
        ]);

        $run = app(SportsSyncService::class)->syncFixtures(8, 0);

        $this->assertSame('ok', $run->status);
        $this->assertDatabaseHas('sports_events', [
            'sportsdb_id' => 555001,
            'name' => 'Arsenal vs Liverpool',
            'sport_slug' => 'football',
        ]);
        $this->assertDatabaseHas('sports_events', [
            'sportsdb_id' => 555002,
            'home_score' => 2,
            'away_score' => 1,
        ]);

        $upcoming = SportsEvent::query()->where('sportsdb_id', 555001)->first();
        $this->assertNotNull($upcoming);
        $this->assertNotNull($upcoming->starts_at);
        $this->assertSame('15:00:00', $upcoming->starts_at->timezone('UTC')->format('H:i:s'));
        $this->assertSame(
            now()->addDays(2)->toDateString(),
            $upcoming->starts_at->timezone('UTC')->toDateString(),
        );

        $snapshot = app(SportsHomeService::class)->getSnapshot();
        $this->assertGreaterThanOrEqual(1, $snapshot['event_count']);
        $this->assertArrayHasKey('featured', $snapshot);
        $this->assertArrayHasKey('football', $snapshot['featured']);
        $this->assertSame(['football', 'rugby', 'golf'], $snapshot['featured_sports']);

        $featuredNext = $snapshot['featured']['football']['next'] ?? null;
        if (is_array($featuredNext)) {
            $this->assertArrayHasKey('starts_at', $featuredNext);
            $this->assertNotNull($featuredNext['starts_at']);
        }
    }

    public function test_fixtures_job_chains_remaining_league_chunks(): void
    {
        Http::fake([
            '*/eventsnextleague.php*' => Http::response(['events' => []], 200),
            '*/eventspastleague.php*' => Http::response(['events' => []], 200),
        ]);

        config([
            'services.sportsdb.leagues' => [
                'football' => [
                    ['id' => 4328, 'name' => 'Premier League'],
                    ['id' => 4335, 'name' => 'La Liga'],
                    ['id' => 4331, 'name' => 'Bundesliga'],
                ],
            ],
            'services.sportsdb.sync.fixture_chunk_size' => 2,
            'services.sportsdb.sync.fixture_chain_delay_seconds' => 1,
        ]);

        Bus::fake();

        (new SyncSportsFixturesJob(2, 0, true))
            ->handle(app(SportsSyncService::class));

        Bus::assertDispatched(SyncSportsFixturesJob::class, function (SyncSportsFixturesJob $job): bool {
            return $job->chunkSize === 2
                && $job->offset === 2
                && $job->chainRemaining === true;
        });
    }

    public function test_fixtures_job_does_not_chain_after_final_chunk(): void
    {
        Http::fake([
            '*/eventsnextleague.php*' => Http::response(['events' => []], 200),
            '*/eventspastleague.php*' => Http::response(['events' => []], 200),
        ]);

        config([
            'services.sportsdb.leagues' => [
                'football' => [
                    ['id' => 4328, 'name' => 'Premier League'],
                ],
            ],
        ]);

        Bus::fake();

        (new SyncSportsFixturesJob(3, 0, true))
            ->handle(app(SportsSyncService::class));

        Bus::assertNotDispatched(SyncSportsFixturesJob::class);
    }

    public function test_day_job_chains_next_sport(): void
    {
        Http::fake([
            '*/eventsday.php*' => Http::response(['events' => []], 200),
        ]);

        config([
            'services.sportsdb.sport_api_names' => [
                'football' => 'Soccer',
                'rugby' => 'Rugby',
            ],
            'services.sportsdb.sync.day_lookback' => [
                'football' => 1,
                'rugby' => 1,
            ],
        ]);

        Bus::fake();

        (new SyncSportsDayJob('football', true))
            ->handle(app(SportsSyncService::class));

        Bus::assertDispatched(SyncSportsDayJob::class, function (SyncSportsDayJob $job): bool {
            return $job->sportSlug === 'rugby' && $job->chainRemaining === true;
        });
    }
}
