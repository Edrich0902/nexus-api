<?php

namespace Tests\Feature\Api\V1\Hub;

use App\Models\Activity\ActivityEvent;
use App\Models\Cellar\CellarWine;
use App\Models\F1\F1Meeting;
use App\Models\F1\F1Session;
use App\Models\Library\LibraryBook;
use App\Models\Media\ImagePalette;
use App\Models\Spotify\SpotifyListenSession;
use App\Models\Spotify\SpotifyTrack;
use App\Models\User;
use App\Services\Activity\ActivityRecorder;
use App\Services\Spotify\ListeningSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HubApiTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_hub_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/activity')->assertUnauthorized();
        $this->getJson('/api/v1/now')->assertUnauthorized();
        $this->getJson('/api/v1/search?q=wine')->assertUnauthorized();
        $this->getJson('/api/v1/palette?url=https://i.scdn.co/image/abc')->assertUnauthorized();
    }

    public function test_services_record_activity_and_feed_is_scoped_and_filtered(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wineId = $this->postJson('/api/v1/cellar/wines', [
            'name' => 'Paul Sauer',
            'producer_name' => 'Kanonkop',
            'vintage' => 2016,
        ])->assertCreated()->json('id');

        $bookId = $this->postJson('/api/v1/library/books', [
            'title' => 'The Overstory',
            'authors' => 'Richard Powers',
        ])->assertCreated()->json('id');

        $this->patchJson("/api/v1/library/books/{$bookId}", ['status' => 'reading'])->assertOk();

        $other = User::factory()->create();
        app(ActivityRecorder::class)->record($other->id, 'cellar', 'wine.added', 'Someone else');

        $this->getJson('/api/v1/activity')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonMissing(['title' => 'Someone else'])
            ->assertJsonFragment(['type' => 'book.started', 'title' => 'The Overstory'])
            ->assertJsonFragment([
                'type' => 'wine.added',
                'title' => '2016 Paul Sauer',
                'subject' => ['type' => 'cellar_wine', 'id' => $wineId],
            ]);

        $this->getJson('/api/v1/activity?module=library')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_activity_uses_cursor_pagination_with_a_hard_limit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $recorder = app(ActivityRecorder::class);
        foreach (range(1, 3) as $i) {
            $recorder->record($user->id, 'beer', 'beer.added', "Beer {$i}", occurredAt: now()->subMinutes(10 - $i));
        }

        $first = $this->getJson('/api/v1/activity?limit=2')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(['Beer 3', 'Beer 2'], array_column($first->json('data'), 'title'));
        $cursor = $first->json('next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->getJson('/api/v1/activity?limit=2&cursor='.urlencode($cursor))->assertOk();
        $this->assertSame(['Beer 1'], array_column($second->json('data'), 'title'));
        $this->assertNull($second->json('next_cursor'));

        $this->getJson('/api/v1/activity?limit=31')->assertUnprocessable();
        $this->getJson('/api/v1/activity?module=nope')->assertUnprocessable();
    }

    public function test_listening_plays_fold_into_one_block(): void
    {
        $user = User::factory()->create();
        $sessions = app(ListeningSessionService::class);

        foreach (['t1' => 'Time (You and I)', 't2' => 'Maria También'] as $spotifyId => $name) {
            $track = SpotifyTrack::query()->create([
                'spotify_id' => $spotifyId,
                'name' => $name,
                'duration_ms' => 200_000,
                'artists' => [['id' => 'a1', 'name' => 'Khruangbin']],
            ]);
            $session = SpotifyListenSession::query()->create([
                'user_id' => $user->id,
                'spotify_track_id' => $track->id,
                'spotify_id' => $spotifyId,
                'started_at' => now(),
                'last_progress_ms' => 190_000,
                'max_progress_ms' => 190_000,
                'duration_ms' => 200_000,
                'status' => SpotifyListenSession::STATUS_ACTIVE,
            ]);
            $sessions->closeSession($session);
        }

        $events = ActivityEvent::query()->where('user_id', $user->id)->get();
        $this->assertCount(1, $events);
        $this->assertSame('listening.block', $events[0]->type);
        $this->assertSame('Maria También', $events[0]->title);
        $this->assertSame(2, $events[0]->meta['tracks']);
    }

    public function test_search_is_owner_scoped_grouped_and_capped(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($user);

        foreach (range(1, 7) as $i) {
            CellarWine::query()->create(['user_id' => $user->id, 'name' => "Sauer {$i}", 'match_status' => 'unmatched']);
        }
        CellarWine::query()->create(['user_id' => $other->id, 'name' => 'Sauer secret', 'match_status' => 'unmatched']);
        LibraryBook::query()->create(['user_id' => $user->id, 'title' => 'Sauerkraut stories', 'status' => 'want', 'match_status' => 'unmatched']);

        $response = $this->getJson('/api/v1/search?q=sauer')
            ->assertOk()
            ->assertJsonPath('query', 'sauer')
            ->assertJsonMissing(['title' => 'Sauer secret']);

        $groups = collect($response->json('groups'))->keyBy('key');
        $this->assertCount(5, $groups['cellar']['items']);
        $this->assertSame('library_book', $groups['library']['items'][0]['type']);
        $this->assertFalse($groups->has('beer'));

        $this->getJson('/api/v1/search?q=a')->assertUnprocessable();
        $this->getJson('/api/v1/search?q=%20%20b%20')->assertUnprocessable();
        $this->getJson('/api/v1/search?q='.str_repeat('x', 101))->assertUnprocessable();
    }

    public function test_now_defaults_to_a_daypart_greeting(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 07:30:00', 'Africa/Johannesburg'));
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/now?tz=Africa/Johannesburg')
            ->assertOk()
            ->assertJsonPath('moment', 'morning')
            ->assertJsonPath('title', 'Good')
            ->assertJsonPath('accent', 'morning')
            ->assertJsonPath('live', false);

        $this->getJson('/api/v1/now?tz=Mars/Olympus')->assertUnprocessable();
    }

    public function test_now_prefers_live_music(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $track = SpotifyTrack::query()->create([
            'spotify_id' => 'trk',
            'name' => 'Time (You and I)',
            'album_name' => 'Mordechai',
            'album_image_url' => 'https://i.scdn.co/image/abc',
            'duration_ms' => 200_000,
            'artists' => [['id' => 'a', 'name' => 'Khruangbin']],
        ]);
        SpotifyListenSession::query()->create([
            'user_id' => $user->id,
            'spotify_track_id' => $track->id,
            'spotify_id' => 'trk',
            'started_at' => now(),
            'last_progress_ms' => 50_000,
            'max_progress_ms' => 50_000,
            'duration_ms' => 200_000,
            'status' => SpotifyListenSession::STATUS_ACTIVE,
        ]);

        Http::fake(['i.scdn.co/*' => Http::response('', 404)]);

        $this->getJson('/api/v1/now')
            ->assertOk()
            ->assertJsonPath('moment', 'music')
            ->assertJsonPath('live', true)
            ->assertJsonPath('title', 'Time (You and I)')
            ->assertJsonPath('lede', 'Khruangbin · Mordechai')
            ->assertJsonPath('progress', 0.25);
    }

    public function test_now_shows_an_upcoming_race(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 10:00:00', 'UTC'));
        Sanctum::actingAs(User::factory()->create());

        F1Meeting::query()->create([
            'meeting_key' => 1250,
            'year' => 2026,
            'meeting_name' => 'Singapore Grand Prix',
            'circuit_short_name' => 'Marina Bay',
            'location' => 'Singapore',
        ]);
        F1Session::query()->create([
            'session_key' => 9900,
            'meeting_key' => 1250,
            'year' => 2026,
            'session_name' => 'Race',
            'date_start' => Carbon::parse('2026-10-04 12:00:00', 'UTC'),
            'date_end' => Carbon::parse('2026-10-04 14:00:00', 'UTC'),
        ]);

        $this->getJson('/api/v1/now?tz=UTC')
            ->assertOk()
            ->assertJsonPath('moment', 'race')
            ->assertJsonPath('title', 'Singapore')
            ->assertJsonPath('accent', 'Grand Prix')
            ->assertJsonPath('eyebrow', 'Race · 12:00')
            ->assertJsonPath('live', false)
            ->assertJsonPath('actions.0.to', '/f1/sessions/9900');
    }

    public function test_now_suggests_a_bottle_in_the_evening(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 19:00:00', 'UTC'));
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $wine = CellarWine::query()->create([
            'user_id' => $user->id,
            'name' => 'Paul Sauer',
            'producer_name' => 'Kanonkop',
            'vintage' => 2016,
            'rating' => 4.5,
            'match_status' => 'unmatched',
        ]);

        $this->getJson('/api/v1/now?tz=UTC')
            ->assertOk()
            ->assertJsonPath('moment', 'cellar')
            ->assertJsonPath('accent', '2016 Paul Sauer')
            ->assertJsonPath('subject.id', $wine->id);
    }

    public function test_palette_only_accepts_allowlisted_https_hosts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/palette?url='.urlencode('https://evil.example.com/a.png'))->assertUnprocessable();
        $this->getJson('/api/v1/palette?url='.urlencode('http://i.scdn.co/image/a'))->assertUnprocessable();
        $this->getJson('/api/v1/palette?url='.urlencode('https://i.scdn.co.evil.com/a'))->assertUnprocessable();
        $this->getJson('/api/v1/palette?url='.urlencode('https://127.0.0.1/a'))->assertUnprocessable();
    }

    public function test_palette_is_extracted_from_allowed_artwork(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 220, 30, 30));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        Http::fake(['i.scdn.co/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $url = 'https://i.scdn.co/image/red';

        $this->getJson('/api/v1/palette?url='.urlencode($url))->assertOk();

        $this->getJson('/api/v1/palette?url='.urlencode($url))
            ->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('palette.dominant', '#dc1e1e');

        $this->assertSame(1, ImagePalette::query()->count());
    }

    public function test_palette_batch_requires_auth_and_bounds_input(): void
    {
        $this->postJson('/api/v1/palettes', ['urls' => ['https://i.scdn.co/image/a']])->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/palettes', ['urls' => []])->assertUnprocessable();
        $this->postJson('/api/v1/palettes', ['urls' => array_fill(0, 61, 'https://i.scdn.co/image/a')])
            ->assertUnprocessable();
        $this->postJson('/api/v1/palettes', ['urls' => [str_repeat('a', 1025)]])->assertUnprocessable();
    }

    public function test_palette_batch_returns_ready_palettes_and_skips_disallowed_hosts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 30, 220));
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();

        Http::fake(['i.scdn.co/*' => Http::response($png, 200, ['Content-Type' => 'image/png'])]);
        $urls = ['https://i.scdn.co/image/blue', 'https://evil.example.com/a.png'];

        $this->postJson('/api/v1/palettes', ['urls' => $urls])->assertOk();

        $palettes = $this->postJson('/api/v1/palettes', ['urls' => $urls])->assertOk()->json('palettes');

        $this->assertSame(['https://i.scdn.co/image/blue'], array_keys($palettes));
        $this->assertSame('#1e1edc', $palettes['https://i.scdn.co/image/blue']['dominant']);

        $this->assertSame(1, ImagePalette::query()->count());
    }
}
