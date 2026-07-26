<?php

namespace App\Services\Cellar;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\WineApi\WineApiIntegration;
use App\Jobs\FoodDrink\EnrichWineJob;
use App\Models\Cellar\CellarWine;
use App\Models\User;
use App\Models\WineCatalog\WineCatalogWine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WineMatchService
{
    public function __construct(
        private readonly WineApiIntegration $wineApi,
        private readonly CellarWineService $wines,
    ) {}

    /**
     * @return array{candidates: list<array<string, mixed>>, from_cache: bool, quota: array<string, mixed>}
     */
    public function candidates(User $user, CellarWine $wine, ?string $query = null): array
    {
        $this->wines->assertOwned($user, $wine);

        $search = trim($query ?? $this->defaultQuery($wine));
        if ($search === '') {
            return [
                'candidates' => [],
                'from_cache' => false,
                'quota' => $this->wineApi->quotaSnapshot(),
            ];
        }

        $cacheKey = 'wineapi:search:'.md5(mb_strtolower($search));
        $ttl = max(60, (int) config('services.wineapi.search_cache_seconds', 3600));

        $fromCache = Cache::has($cacheKey);

        try {
            $payload = Cache::remember($cacheKey, $ttl, function () use ($search) {
                return $this->wineApi->search($search, 20, 0);
            });
        } catch (IntegrationException $e) {
            if ($e->statusCode === 429) {
                return [
                    'candidates' => [],
                    'from_cache' => false,
                    'quota' => $this->wineApi->quotaSnapshot(),
                    'message' => 'Daily WineAPI search budget is reserved for enrichment. Try again after reset.',
                ];
            }

            throw $e;
        }

        $candidates = array_map(static function (array $row): array {
            return [
                'wineapi_id' => (string) ($row['id'] ?? ''),
                'name' => $row['name'] ?? null,
                'vintage' => $row['vintage'] ?? null,
                'type' => $row['type'] ?? null,
                'winery' => is_array($row['winery'] ?? null)
                    ? ($row['winery']['name'] ?? null)
                    : ($row['winery'] ?? null),
                'region' => is_array($row['region'] ?? null)
                    ? ($row['region']['name'] ?? null)
                    : ($row['region'] ?? null),
                'country' => $row['country'] ?? (is_array($row['region'] ?? null) ? ($row['region']['country'] ?? null) : null),
                'average_rating' => $row['averageRating'] ?? null,
                'ratings_count' => $row['ratingsCount'] ?? null,
                'confidence' => $row['confidence'] ?? null,
            ];
        }, $payload['results'] ?? []);

        return [
            'candidates' => $candidates,
            'from_cache' => $fromCache,
            'quota' => $this->wineApi->quotaSnapshot(),
        ];
    }

    public function confirmMatch(User $user, CellarWine $wine, string $wineapiId): CellarWine
    {
        $this->wines->assertOwned($user, $wine);

        if (! Str::isUuid($wineapiId)) {
            abort(422, 'Invalid WineAPI id.');
        }

        $catalog = WineCatalogWine::query()->firstOrCreate(
            ['wineapi_id' => $wineapiId],
            [
                'name' => $wine->name,
                'vintage' => $wine->vintage,
                'type' => $wine->wine_type,
                'enrichment_status' => WineCatalogWine::ENRICHMENT_QUEUED,
            ],
        );

        $wine->wine_catalog_wine_id = $catalog->id;
        $wine->match_status = CellarWine::MATCH_MATCHED;
        $wine->save();

        if ($catalog->enrichment_status !== WineCatalogWine::ENRICHMENT_COMPLETE) {
            $catalog->enrichment_status = WineCatalogWine::ENRICHMENT_QUEUED;
            $catalog->save();
            EnrichWineJob::dispatch($catalog->id);
        }

        return $this->wines->findOwned($user, (int) $wine->id);
    }

    public function markNoMatch(User $user, CellarWine $wine): CellarWine
    {
        $this->wines->assertOwned($user, $wine);

        $wine->wine_catalog_wine_id = null;
        $wine->match_status = CellarWine::MATCH_NO_MATCH;
        $wine->save();

        return $wine->fresh(['catalogWine', 'tastings']) ?? $wine;
    }

    public function clearMatch(User $user, CellarWine $wine): CellarWine
    {
        $this->wines->assertOwned($user, $wine);

        $wine->wine_catalog_wine_id = null;
        $wine->match_status = CellarWine::MATCH_UNMATCHED;
        $wine->save();

        return $wine->fresh(['catalogWine', 'tastings']) ?? $wine;
    }

    private function defaultQuery(CellarWine $wine): string
    {
        return trim(implode(' ', array_filter([
            $wine->producer_name,
            $wine->name,
            $wine->vintage ? (string) $wine->vintage : null,
        ])));
    }
}
