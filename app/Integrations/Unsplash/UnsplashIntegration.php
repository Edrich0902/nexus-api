<?php

namespace App\Integrations\Unsplash;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ProviderHttpClient;
use Illuminate\Support\Facades\Cache;

class UnsplashIntegration
{
    public const PROVIDER = 'unsplash';

    public function __construct(
        private readonly ProviderHttpClient $http,
    ) {}

    /**
     * @return array{results: list<array<string, mixed>>, total: int, total_pages: int}
     */
    public function search(string $query, int $page = 1, ?int $perPage = null): array
    {
        $perPage = $perPage ?? (int) config('media.unsplash.per_page', 20);
        $perPage = max(1, min(30, $perPage));
        $page = max(1, $page);
        $cacheKey = 'unsplash:search:'.md5(strtolower(trim($query))."|{$page}|{$perPage}");
        $ttl = max(60, (int) config('media.unsplash.search_cache_seconds', 300));

        /** @var array{results: list<array<string, mixed>>, total: int, total_pages: int} $payload */
        $payload = Cache::remember($cacheKey, $ttl, function () use ($query, $page, $perPage): array {
            $response = $this->http->send(self::PROVIDER, 'GET', $this->url('search/photos'), [
                'timeout' => (int) config('media.unsplash.timeout', 12),
                'headers' => $this->headers(),
                'query' => [
                    'query' => $query,
                    'page' => $page,
                    'per_page' => $perPage,
                    'orientation' => 'squarish',
                ],
            ]);

            $json = $response->json();
            $results = is_array($json['results'] ?? null) ? $json['results'] : [];

            return [
                'results' => array_map(fn (array $photo) => $this->mapPhoto($photo), $results),
                'total' => (int) ($json['total'] ?? 0),
                'total_pages' => (int) ($json['total_pages'] ?? 0),
            ];
        });

        return $payload;
    }

    /**
     * Trigger Unsplash download tracking (required by API guidelines when a photo is selected).
     */
    public function trackDownload(string $downloadLocation): void
    {
        if ($downloadLocation === '') {
            return;
        }

        $this->http->send(self::PROVIDER, 'GET', $downloadLocation, [
            'timeout' => (int) config('media.unsplash.timeout', 12),
            'headers' => $this->headers(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $photo
     * @return array<string, mixed>
     */
    private function mapPhoto(array $photo): array
    {
        $user = is_array($photo['user'] ?? null) ? $photo['user'] : [];
        $urls = is_array($photo['urls'] ?? null) ? $photo['urls'] : [];
        $links = is_array($photo['links'] ?? null) ? $photo['links'] : [];

        return [
            'id' => (string) ($photo['id'] ?? ''),
            'description' => $photo['description'] ?? $photo['alt_description'] ?? null,
            'width' => isset($photo['width']) ? (int) $photo['width'] : null,
            'height' => isset($photo['height']) ? (int) $photo['height'] : null,
            'color' => $photo['color'] ?? null,
            'urls' => [
                'thumb' => $urls['thumb'] ?? null,
                'small' => $urls['small'] ?? null,
                'regular' => $urls['regular'] ?? null,
                'full' => $urls['full'] ?? null,
                'raw' => $urls['raw'] ?? null,
            ],
            'links' => [
                'html' => $links['html'] ?? null,
                'download' => $links['download'] ?? null,
                'download_location' => $links['download_location'] ?? null,
            ],
            'user' => [
                'name' => $user['name'] ?? null,
                'username' => $user['username'] ?? null,
                'profile_url' => is_array($user['links'] ?? null) ? ($user['links']['html'] ?? null) : null,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $key = (string) config('media.unsplash.access_key');
        if ($key === '') {
            throw new IntegrationException('[unsplash] Missing UNSPLASH_ACCESS_KEY.', 503);
        }

        return [
            'Authorization' => 'Client-ID '.$key,
            'Accept-Version' => 'v1',
        ];
    }

    private function url(string $path): string
    {
        return rtrim((string) config('media.unsplash.base_url'), '/').'/'.ltrim($path, '/');
    }
}
