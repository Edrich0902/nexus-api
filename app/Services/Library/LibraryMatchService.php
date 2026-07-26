<?php

namespace App\Services\Library;

use App\Integrations\OpenLibrary\OpenLibraryIntegration;
use App\Models\Library\LibraryBook;
use App\Models\LibraryCatalog\LibraryCatalogBook;
use App\Models\User;
use App\Services\Media\MediaMirrorService;
use Illuminate\Support\Arr;

class LibraryMatchService
{
    public function __construct(
        private readonly OpenLibraryIntegration $openLibrary,
        private readonly LibraryBookService $books,
        private readonly MediaMirrorService $mediaMirrors,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function searchUpstream(string $query, int $limit = 20): array
    {
        return $this->openLibrary->search($query, $limit);
    }

    /**
     * @return array{candidates: list<array<string, mixed>>, from_cache: bool}
     */
    public function candidates(User $user, LibraryBook $book, ?string $query = null): array
    {
        $this->books->assertOwned($user, $book);

        $search = trim($query ?? $this->defaultQuery($book));
        if ($search === '') {
            return [
                'candidates' => [],
                'from_cache' => false,
            ];
        }

        $cacheKey = 'openlibrary:search:'.md5(mb_strtolower($search).'|20');
        $fromCache = cache()->has($cacheKey);

        return [
            'candidates' => $this->openLibrary->search($search, 20),
            'from_cache' => $fromCache,
        ];
    }

    public function confirmMatch(User $user, LibraryBook $book, string $olWorkKey): LibraryBook
    {
        $this->books->assertOwned($user, $book);

        $catalog = $this->upsertCatalogFromWorkKey($olWorkKey);
        $this->applyCatalogToBook($book, $catalog);
        $this->queueCoverMirror($user, $book, $catalog);

        return $this->books->findOwned($user, (int) $book->id);
    }

    public function createFromCatalog(User $user, string $olWorkKey, array $overrides = []): LibraryBook
    {
        $catalog = $this->upsertCatalogFromWorkKey($olWorkKey);

        $book = $this->books->create($user, [
            'title' => $overrides['title'] ?? $catalog->title,
            'authors' => $overrides['authors'] ?? $catalog->authorsLabel(),
            'isbn' => $overrides['isbn'] ?? ($catalog->isbn_13 ?: $catalog->isbn_10),
            'status' => $overrides['status'] ?? LibraryBook::STATUS_WANT,
            'rating' => $overrides['rating'] ?? null,
            'notes' => $overrides['notes'] ?? null,
            'started_at' => $overrides['started_at'] ?? null,
            'finished_at' => $overrides['finished_at'] ?? null,
        ]);

        $this->applyCatalogToBook($book, $catalog);
        $this->queueCoverMirror($user, $book, $catalog);

        return $this->books->findOwned($user, (int) $book->id);
    }

    public function markNoMatch(User $user, LibraryBook $book): LibraryBook
    {
        $this->books->assertOwned($user, $book);

        $book->library_catalog_book_id = null;
        $book->match_status = LibraryBook::MATCH_NO_MATCH;
        $book->save();

        return $book->fresh(['catalogBook']) ?? $book;
    }

    public function clearMatch(User $user, LibraryBook $book): LibraryBook
    {
        $this->books->assertOwned($user, $book);

        $book->library_catalog_book_id = null;
        $book->match_status = LibraryBook::MATCH_UNMATCHED;
        $book->save();

        return $book->fresh(['catalogBook']) ?? $book;
    }

    public function upsertCatalogFromWorkKey(string $olWorkKey): LibraryCatalogBook
    {
        $key = $this->openLibrary->normalizeWorkKey($olWorkKey);
        if ($key === null) {
            abort(422, 'Invalid Open Library work key.');
        }

        $existing = LibraryCatalogBook::query()->where('ol_work_key', $key)->first();
        $work = $this->openLibrary->work($key);
        $searchHint = $this->openLibrary->search('key:'.$key, 5);
        $fromSearch = collect($searchHint)->firstWhere('ol_work_key', $key)
            ?? collect($searchHint)->first();

        $title = is_array($work)
            ? (string) ($work['title'] ?? Arr::get($fromSearch, 'title') ?? 'Untitled')
            : (string) (Arr::get($fromSearch, 'title') ?? 'Untitled');

        $description = null;
        if (is_array($work)) {
            $desc = $work['description'] ?? null;
            if (is_string($desc)) {
                $description = $desc;
            } elseif (is_array($desc) && isset($desc['value'])) {
                $description = (string) $desc['value'];
            }
        }

        $authors = Arr::get($fromSearch, 'authors');
        if (! is_array($authors) || $authors === []) {
            $authors = $this->extractAuthorNamesFromWork($work);
        }

        $coverI = Arr::get($fromSearch, 'cover_i');
        if ($coverI === null && is_array($work) && isset($work['covers'][0]) && is_numeric($work['covers'][0])) {
            $coverI = (int) $work['covers'][0];
        }

        $attributes = [
            'ol_edition_key' => Arr::get($fromSearch, 'ol_edition_key'),
            'title' => $title,
            'authors' => $authors,
            'isbn_10' => Arr::get($fromSearch, 'isbn_10'),
            'isbn_13' => Arr::get($fromSearch, 'isbn_13'),
            'publish_year' => Arr::get($fromSearch, 'publish_year'),
            'page_count' => Arr::get($fromSearch, 'page_count'),
            'description' => $description,
            'cover_i' => is_numeric($coverI) ? (int) $coverI : null,
            'cover_url' => $this->openLibrary->coverUrl(is_numeric($coverI) ? (int) $coverI : null),
            'raw' => [
                'work' => $work,
                'search' => $fromSearch,
            ],
        ];

        if ($existing !== null) {
            $existing->fill($attributes);
            $existing->save();

            return $existing;
        }

        return LibraryCatalogBook::query()->create([
            'ol_work_key' => $key,
            ...$attributes,
        ]);
    }

    private function applyCatalogToBook(LibraryBook $book, LibraryCatalogBook $catalog): void
    {
        $book->library_catalog_book_id = $catalog->id;
        $book->match_status = LibraryBook::MATCH_MATCHED;

        if (trim((string) $book->title) === '' || $book->title === 'Untitled') {
            $book->title = $catalog->title;
        }

        if ($book->authors === null || trim($book->authors) === '') {
            $book->authors = $catalog->authorsLabel();
        }

        if ($book->isbn === null || trim($book->isbn) === '') {
            $book->isbn = $catalog->isbn_13 ?: $catalog->isbn_10;
        }

        $book->save();
    }

    private function queueCoverMirror(User $user, LibraryBook $book, LibraryCatalogBook $catalog): void
    {
        if (! is_string($catalog->cover_url) || $catalog->cover_url === '') {
            return;
        }

        $this->mediaMirrors->queueMirror(
            provider: 'openlibrary',
            url: $catalog->cover_url,
            sourceRef: str_replace('/', '_', $catalog->ol_work_key),
            actingUser: $user,
            attachTo: [
                'type' => 'library_book',
                'id' => (int) $book->id,
            ],
            sourceMeta: [
                'ol_work_key' => $catalog->ol_work_key,
                'catalog_id' => $catalog->id,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $work
     * @return list<string>
     */
    private function extractAuthorNamesFromWork(?array $work): array
    {
        if ($work === null || ! is_array($work['authors'] ?? null)) {
            return [];
        }

        $names = [];
        foreach ($work['authors'] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $author = $row['author'] ?? null;
            if (is_array($author) && isset($author['key'])) {
                $names[] = (string) $author['key'];
            }
        }

        return $names;
    }

    private function defaultQuery(LibraryBook $book): string
    {
        if ($book->isbn !== null && trim($book->isbn) !== '') {
            return trim($book->isbn);
        }

        return trim(implode(' ', array_filter([
            $book->title,
            $book->authors,
        ])));
    }
}
