<?php

namespace App\Integrations\OpenLibrary;

use App\Integrations\Support\ProviderHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

class OpenLibraryIntegration
{
    public const PROVIDER = 'openlibrary';

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min(40, $limit));
        $cacheKey = 'openlibrary:search:'.md5(mb_strtolower($query).'|'.$limit);
        $ttl = max(60, (int) config('services.openlibrary.search_cache_seconds', 3600));

        /** @var list<array<string, mixed>> $docs */
        $docs = Cache::remember($cacheKey, $ttl, function () use ($query, $limit) {
            $payload = $this->get('/search.json', [
                'q' => $query,
                'limit' => $limit,
                'fields' => 'key,title,author_name,first_publish_year,isbn,cover_i,edition_key,number_of_pages_median,subtitle',
            ])->json();

            $rows = is_array($payload['docs'] ?? null) ? $payload['docs'] : [];

            return array_values(array_filter($rows, 'is_array'));
        });

        return array_map(fn (array $doc) => $this->normalizeSearchDoc($doc), $docs);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function work(string $workKey): ?array
    {
        $key = $this->normalizeWorkKey($workKey);
        if ($key === null) {
            return null;
        }

        $cacheKey = 'openlibrary:work:'.$key;
        $ttl = max(60, (int) config('services.openlibrary.work_cache_seconds', 86400));

        return Cache::remember($cacheKey, $ttl, function () use ($key) {
            $payload = $this->get('/'.$key.'.json')->json();

            return is_array($payload) ? $payload : null;
        });
    }

    public function coverUrl(?int $coverI, string $size = 'L'): ?string
    {
        if ($coverI === null || $coverI <= 0) {
            return null;
        }

        $size = in_array($size, ['S', 'M', 'L'], true) ? $size : 'L';
        $base = rtrim((string) config('services.openlibrary.covers_base_url', 'https://covers.openlibrary.org'), '/');

        return "{$base}/b/id/{$coverI}-{$size}.jpg";
    }

    public function normalizeWorkKey(string $workKey): ?string
    {
        $workKey = trim($workKey);
        if ($workKey === '') {
            return null;
        }

        if (preg_match('#(?:^|/)(OL\d+W)$#i', $workKey, $matches) !== 1) {
            return null;
        }

        return '/works/'.strtoupper($matches[1]);
    }

    /**
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>
     */
    private function normalizeSearchDoc(array $doc): array
    {
        $workKey = $this->normalizeWorkKey((string) ($doc['key'] ?? '')) ?? (string) ($doc['key'] ?? '');
        $coverI = isset($doc['cover_i']) && is_numeric($doc['cover_i']) ? (int) $doc['cover_i'] : null;
        $authors = is_array($doc['author_name'] ?? null)
            ? array_values(array_map('strval', $doc['author_name']))
            : [];
        $isbns = is_array($doc['isbn'] ?? null)
            ? array_values(array_map('strval', $doc['isbn']))
            : [];
        $editionKeys = is_array($doc['edition_key'] ?? null)
            ? array_values(array_map('strval', $doc['edition_key']))
            : [];

        $isbn13 = $this->firstIsbn($isbns, 13);
        $isbn10 = $this->firstIsbn($isbns, 10);

        return [
            'ol_work_key' => $workKey,
            'ol_edition_key' => $editionKeys[0] ?? null,
            'title' => isset($doc['title']) ? (string) $doc['title'] : null,
            'subtitle' => isset($doc['subtitle']) ? (string) $doc['subtitle'] : null,
            'authors' => $authors,
            'publish_year' => isset($doc['first_publish_year']) && is_numeric($doc['first_publish_year'])
                ? (int) $doc['first_publish_year']
                : null,
            'isbn_10' => $isbn10,
            'isbn_13' => $isbn13,
            'page_count' => isset($doc['number_of_pages_median']) && is_numeric($doc['number_of_pages_median'])
                ? (int) $doc['number_of_pages_median']
                : null,
            'cover_i' => $coverI,
            'cover_url' => $this->coverUrl($coverI),
        ];
    }

    /**
     * @param  list<string>  $isbns
     */
    private function firstIsbn(array $isbns, int $length): ?string
    {
        foreach ($isbns as $isbn) {
            $digits = preg_replace('/[^0-9Xx]/', '', $isbn) ?? '';
            if (strlen($digits) === $length) {
                return strtoupper($digits);
            }
        }

        return null;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        $base = rtrim((string) config('services.openlibrary.base_url', 'https://openlibrary.org'), '/');
        $url = $base.'/'.ltrim($path, '/');

        return $this->http->send(self::PROVIDER, 'GET', $url, [
            'query' => $query,
            'timeout' => (int) config('services.openlibrary.timeout', 15),
            'headers' => [
                'User-Agent' => (string) config('services.openlibrary.user_agent', 'NexusHub/1.0 (personal library)'),
            ],
        ]);
    }
}
