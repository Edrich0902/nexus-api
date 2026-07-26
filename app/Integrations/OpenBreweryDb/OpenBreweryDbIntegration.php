<?php

namespace App\Integrations\OpenBreweryDb;

use App\Integrations\Support\ProviderHttpClient;
use Illuminate\Http\Client\Response;

class OpenBreweryDbIntegration
{
    public const PROVIDER = 'openbrewerydb';

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
    public function search(string $query, int $page = 1, int $perPage = 20): array
    {
        $payload = $this->get('/breweries/search', [
            'query' => $query,
            'page' => max(1, $page),
            'per_page' => max(1, min(200, $perPage)),
        ])->json();

        return is_array($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $obdbId): ?array
    {
        $payload = $this->get('/breweries/'.ltrim($obdbId, '/'))->json();

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        $base = rtrim((string) config('services.openbrewerydb.base_url', 'https://api.openbrewerydb.org/v1'), '/');
        $url = $base.'/'.ltrim($path, '/');

        return $this->http->send(self::PROVIDER, 'GET', $url, [
            'query' => $query,
            'timeout' => (int) config('services.openbrewerydb.timeout', 12),
        ]);
    }
}
