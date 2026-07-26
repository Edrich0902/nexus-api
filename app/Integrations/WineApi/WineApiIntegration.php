<?php

namespace App\Integrations\WineApi;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\ProviderHttpClient;
use App\Integrations\Support\UpstreamDailyBudget;
use Illuminate\Http\Client\Response;

/**
 * WineAPI key-based client. Every outbound call reserves from the daily budget ledger.
 */
class WineApiIntegration
{
    public const PROVIDER = 'wineapi';

    public const PURPOSE_SEARCH = 'search';

    public const PURPOSE_ENRICHMENT = 'enrichment';

    public function __construct(
        private readonly ProviderHttpClient $http,
        private readonly UpstreamDailyBudget $budget,
    ) {}

    public function provider(): string
    {
        return self::PROVIDER;
    }

    /**
     * @return array{results: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function search(string $query, int $limit = 20, int $offset = 0): array
    {
        $this->reserveOrFail(self::PURPOSE_SEARCH);

        $response = $this->get('/wines/search', [
            'q' => $query,
            'limit' => max(1, min(100, $limit)),
            'offset' => max(0, $offset),
        ]);

        $payload = $response->json() ?? [];

        return [
            'results' => is_array($payload['results'] ?? null) ? array_values($payload['results']) : [],
            'total' => (int) ($payload['total'] ?? 0),
            'limit' => (int) ($payload['limit'] ?? $limit),
            'offset' => (int) ($payload['offset'] ?? $offset),
        ];
    }

    /**
     * @return array{wine: array<string, mixed>, update_status: string|null, retry_after: int|null}
     */
    public function wine(string $wineapiId): array
    {
        $this->reserveOrFail(self::PURPOSE_ENRICHMENT);

        $response = $this->get('/wines/'.ltrim($wineapiId, '/'));

        $payload = $response->json();
        if (! is_array($payload)) {
            throw new IntegrationException('[wineapi] Invalid wine detail payload.', 502);
        }

        $updateStatus = $response->header('X-Update-Status');
        $retryAfter = $response->header('Retry-After');

        return [
            'wine' => $payload,
            'update_status' => $updateStatus !== '' ? strtolower((string) $updateStatus) : null,
            'retry_after' => is_numeric($retryAfter) ? max(1, (int) $retryAfter) : null,
        ];
    }

    public function quotaSnapshot(): array
    {
        return $this->budget->snapshot(self::PROVIDER);
    }

    private function reserveOrFail(string $purpose): void
    {
        $ceiling = $purpose === self::PURPOSE_SEARCH
            ? $this->budget->searchCeiling(self::PROVIDER)
            : $this->budget->enrichmentCeiling(self::PROVIDER);

        if (! $this->budget->reserve(self::PROVIDER, $ceiling)) {
            throw new IntegrationException(
                '[wineapi] Daily request budget exhausted.',
                429,
            );
        }
    }

    /**
     * @param  array<string, scalar|null>  $query
     */
    private function get(string $path, array $query = []): Response
    {
        $base = rtrim((string) config('services.wineapi.base_url', 'https://api.wineapi.io'), '/');
        $key = (string) config('services.wineapi.api_key', '');

        if ($key === '') {
            throw new IntegrationException('[wineapi] API key is not configured.', 503);
        }

        $url = $base.'/'.ltrim($path, '/');

        return $this->http->send(self::PROVIDER, 'GET', $url, [
            'query' => $query,
            'headers' => [
                'X-API-Key' => $key,
            ],
            'timeout' => (int) config('services.wineapi.timeout', 15),
        ]);
    }
}
