<?php

namespace Tests\Feature\Api\V1\Library;

use App\Models\Library\LibraryBook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LibraryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.openlibrary.base_url' => 'https://openlibrary.org',
            'services.openlibrary.covers_base_url' => 'https://covers.openlibrary.org',
            'services.openlibrary.search_cache_seconds' => 60,
            'services.openlibrary.work_cache_seconds' => 60,
            'services.rate_limits.openlibrary' => [
                'max_attempts' => 100,
                'decay_seconds' => 60,
                'max_wait_seconds' => 0,
            ],
            'media.mirrors.openlibrary.enabled' => false,
        ]);
    }

    public function test_book_crud_and_status_filter(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $bookId = $this->postJson('/api/v1/library/books', [
            'title' => 'Dune',
            'authors' => 'Frank Herbert',
            'status' => 'want',
            'rating' => 4.5,
        ])->assertCreated()
            ->assertJsonPath('title', 'Dune')
            ->assertJsonPath('status', 'want')
            ->assertJsonPath('match_status', 'unmatched')
            ->json('id');

        $this->getJson('/api/v1/library/books')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/library/books?status=reading')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->patchJson("/api/v1/library/books/{$bookId}", [
            'status' => 'read',
            'finished_at' => '2026-07-01',
        ])->assertOk()
            ->assertJsonPath('status', 'read')
            ->assertJsonPath('finished_at', '2026-07-01');

        $other = User::factory()->create();
        Sanctum::actingAs($other);
        $this->getJson("/api/v1/library/books/{$bookId}")->assertNotFound();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/v1/library/books/{$bookId}")->assertOk();
        $this->assertSoftDeleted('library_books', ['id' => $bookId]);
    }

    public function test_search_and_match_from_open_library(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'openlibrary.org/search.json*' => Http::response([
                'docs' => [
                    [
                        'key' => '/works/OL893415W',
                        'title' => 'Dune',
                        'author_name' => ['Frank Herbert'],
                        'first_publish_year' => 1965,
                        'isbn' => ['0441172717', '9780441172719'],
                        'cover_i' => 9255566,
                        'edition_key' => ['OL24342530M'],
                        'number_of_pages_median' => 604,
                    ],
                ],
            ]),
            'openlibrary.org/works/OL893415W.json' => Http::response([
                'key' => '/works/OL893415W',
                'title' => 'Dune',
                'description' => 'A desert planet epic.',
                'covers' => [9255566],
            ]),
        ]);

        $this->getJson('/api/v1/library/search?q=dune')
            ->assertOk()
            ->assertJsonPath('results.0.ol_work_key', '/works/OL893415W')
            ->assertJsonPath('results.0.title', 'Dune');

        $bookId = $this->postJson('/api/v1/library/books', [
            'title' => 'Dune',
            'authors' => 'Frank Herbert',
        ])->assertCreated()->json('id');

        $this->getJson("/api/v1/library/books/{$bookId}/candidates")
            ->assertOk()
            ->assertJsonPath('candidates.0.ol_work_key', '/works/OL893415W');

        $this->postJson("/api/v1/library/books/{$bookId}/match", [
            'ol_work_key' => '/works/OL893415W',
        ])->assertOk()
            ->assertJsonPath('match_status', 'matched')
            ->assertJsonPath('catalog.ol_work_key', '/works/OL893415W')
            ->assertJsonPath('catalog.title', 'Dune');

        $this->assertDatabaseHas('library_catalog_books', [
            'ol_work_key' => '/works/OL893415W',
            'title' => 'Dune',
        ]);

        $this->assertDatabaseHas('library_books', [
            'id' => $bookId,
            'match_status' => LibraryBook::MATCH_MATCHED,
        ]);
    }

    public function test_create_from_catalog(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Http::fake([
            'openlibrary.org/search.json*' => Http::response([
                'docs' => [
                    [
                        'key' => '/works/OL45804W',
                        'title' => 'The Hobbit',
                        'author_name' => ['J. R. R. Tolkien'],
                        'first_publish_year' => 1937,
                        'cover_i' => 14625765,
                        'isbn' => ['9780547928227'],
                    ],
                ],
            ]),
            'openlibrary.org/works/OL45804W.json' => Http::response([
                'key' => '/works/OL45804W',
                'title' => 'The Hobbit',
                'description' => ['value' => 'There and back again.'],
                'covers' => [14625765],
            ]),
        ]);

        $this->postJson('/api/v1/library/books/from-catalog', [
            'ol_work_key' => 'OL45804W',
            'status' => 'reading',
            'rating' => 5.0,
        ])->assertCreated()
            ->assertJsonPath('title', 'The Hobbit')
            ->assertJsonPath('status', 'reading')
            ->assertJsonPath('match_status', 'matched')
            ->assertJsonPath('catalog.ol_work_key', '/works/OL45804W');
    }

    public function test_invalid_rating_rejected(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/library/books', [
            'title' => 'Bad Rating',
            'rating' => 4.25,
        ])->assertStatus(422);
    }

    public function test_library_pulse(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        LibraryBook::factory()->create([
            'user_id' => $user->id,
            'title' => 'Want Book',
            'status' => LibraryBook::STATUS_WANT,
        ]);
        LibraryBook::factory()->create([
            'user_id' => $user->id,
            'title' => 'Reading Now',
            'status' => LibraryBook::STATUS_READING,
        ]);
        LibraryBook::factory()->create([
            'user_id' => $user->id,
            'title' => 'Finished Book',
            'status' => LibraryBook::STATUS_READ,
        ]);

        $this->getJson('/api/v1/library/pulse')
            ->assertOk()
            ->assertJsonPath('counts.want', 1)
            ->assertJsonPath('counts.reading', 1)
            ->assertJsonPath('counts.read', 1)
            ->assertJsonPath('counts.total', 3)
            ->assertJsonPath('reading.0.title', 'Reading Now')
            ->assertJsonCount(3, 'recent');
    }
}
