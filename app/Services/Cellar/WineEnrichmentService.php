<?php

namespace App\Services\Cellar;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\WineApi\WineApiIntegration;
use App\Jobs\FoodDrink\EnrichWineJob;
use App\Models\WineCatalog\WineCatalogGrape;
use App\Models\WineCatalog\WineCatalogPairing;
use App\Models\WineCatalog\WineCatalogPrice;
use App\Models\WineCatalog\WineCatalogRegion;
use App\Models\WineCatalog\WineCatalogScore;
use App\Models\WineCatalog\WineCatalogWine;
use App\Models\WineCatalog\WineCatalogWinery;
use Illuminate\Support\Facades\DB;

class WineEnrichmentService
{
    public function __construct(
        private readonly WineApiIntegration $wineApi,
    ) {}

    /**
     * @return array{status: string, retry_after: int|null}
     */
    public function enrich(WineCatalogWine $catalog): array
    {
        $maxAttempts = max(1, (int) config('services.wineapi.enrichment_max_attempts', 5));

        if ($catalog->enrichment_attempts >= $maxAttempts) {
            $catalog->enrichment_status = WineCatalogWine::ENRICHMENT_FAILED;
            $catalog->save();

            return ['status' => 'failed', 'retry_after' => null];
        }

        try {
            $result = $this->wineApi->wine((string) $catalog->wineapi_id);
        } catch (IntegrationException $e) {
            if ($e->statusCode === 429) {
                $catalog->enrichment_status = WineCatalogWine::ENRICHMENT_QUEUED;
                $catalog->save();

                return ['status' => 'queued', 'retry_after' => null];
            }

            $catalog->enrichment_attempts = (int) $catalog->enrichment_attempts + 1;
            $catalog->enrichment_status = WineCatalogWine::ENRICHMENT_FAILED;
            $catalog->save();

            throw $e;
        }

        $this->upsertFromPayload($catalog, $result['wine']);

        $catalog->enrichment_attempts = (int) $catalog->enrichment_attempts + 1;

        if (($result['update_status'] ?? null) === 'pending') {
            $catalog->enrichment_status = WineCatalogWine::ENRICHMENT_PENDING;
            $catalog->save();

            return [
                'status' => 'pending',
                'retry_after' => $result['retry_after'] ?? 30,
            ];
        }

        $catalog->enrichment_status = WineCatalogWine::ENRICHMENT_COMPLETE;
        $catalog->enriched_at = now();
        $catalog->save();

        return ['status' => 'complete', 'retry_after' => null];
    }

    public function dispatchPending(int $limit = 10): int
    {
        $ids = WineCatalogWine::query()
            ->whereIn('enrichment_status', [
                WineCatalogWine::ENRICHMENT_QUEUED,
                WineCatalogWine::ENRICHMENT_PENDING,
            ])
            ->orderBy('updated_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            EnrichWineJob::dispatch((int) $id);
        }

        return $ids->count();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function upsertFromPayload(WineCatalogWine $catalog, array $payload): void
    {
        DB::transaction(function () use ($catalog, $payload): void {
            $wineryId = null;
            if (is_array($payload['winery'] ?? null) && ! empty($payload['winery']['name'])) {
                $wineryId = $this->resolveWinery($payload['winery']);
            }

            $regionId = null;
            if (is_array($payload['region'] ?? null) && ! empty($payload['region']['name'])) {
                $regionId = $this->resolveRegion($payload['region']);
            }

            $catalog->fill([
                'wine_catalog_winery_id' => $wineryId,
                'wine_catalog_region_id' => $regionId,
                'name' => (string) ($payload['name'] ?? $catalog->name),
                'vintage' => $payload['vintage'] ?? $catalog->vintage,
                'type' => $payload['type'] ?? $catalog->type,
                'body' => $payload['body'] ?? null,
                'acidity' => $payload['acidity'] ?? null,
                'elaborate' => $payload['elaborate'] ?? null,
                'classification' => $payload['classification'] ?? null,
                'appellation' => is_array($payload['appellation'] ?? null)
                    ? ($payload['appellation']['name'] ?? null)
                    : ($payload['appellation'] ?? null),
                'average_rating' => $payload['averageRating'] ?? null,
                'ratings_count' => $payload['ratingsCount'] ?? null,
                'alcohol_content' => $payload['alcoholContent'] ?? null,
                'description' => $payload['description'] ?? null,
                'lwin_code' => $payload['lwinCode'] ?? null,
                'image_url' => $payload['imageUrl'] ?? null,
                'raw' => $payload,
            ]);
            $catalog->save();

            $grapeIds = [];
            foreach ($payload['grapes'] ?? [] as $grapeRow) {
                if (! is_array($grapeRow) || empty($grapeRow['name'])) {
                    continue;
                }
                $grapeIds[] = $this->resolveGrape($grapeRow);
            }
            $catalog->grapes()->sync($grapeIds);

            WineCatalogScore::query()->where('wine_catalog_wine_id', $catalog->id)->delete();
            foreach ($payload['scores'] ?? [] as $scoreRow) {
                if (! is_array($scoreRow)) {
                    continue;
                }
                WineCatalogScore::query()->create([
                    'wine_catalog_wine_id' => $catalog->id,
                    'score' => $scoreRow['score'] ?? null,
                    'score_text' => $scoreRow['scoreText'] ?? null,
                    'reviewer' => $scoreRow['reviewer'] ?? null,
                    'review_date' => $scoreRow['reviewDate'] ?? null,
                ]);
            }

            WineCatalogPrice::query()->where('wine_catalog_wine_id', $catalog->id)->delete();
            foreach ($payload['prices'] ?? [] as $priceRow) {
                if (! is_array($priceRow)) {
                    continue;
                }
                WineCatalogPrice::query()->create([
                    'wine_catalog_wine_id' => $catalog->id,
                    'merchant_name' => $priceRow['merchantName'] ?? null,
                    'price' => $priceRow['price'] ?? null,
                    'currency' => $priceRow['currency'] ?? null,
                    'url' => $priceRow['url'] ?? null,
                    'fetched_at' => $priceRow['fetchedAt'] ?? null,
                ]);
            }

            WineCatalogPairing::query()->where('wine_catalog_wine_id', $catalog->id)->delete();
            foreach ($payload['pairings'] ?? [] as $pairingRow) {
                if (! is_array($pairingRow) || empty($pairingRow['food'])) {
                    continue;
                }
                WineCatalogPairing::query()->create([
                    'wine_catalog_wine_id' => $catalog->id,
                    'food' => (string) $pairingRow['food'],
                    'confidence' => $pairingRow['confidence'] ?? null,
                    'notes' => $pairingRow['notes'] ?? null,
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $winery
     */
    private function resolveWinery(array $winery): int
    {
        $wineapiId = isset($winery['id']) ? (string) $winery['id'] : null;
        $name = (string) $winery['name'];

        if ($wineapiId) {
            return WineCatalogWinery::query()->updateOrCreate(
                ['wineapi_id' => $wineapiId],
                ['name' => $name],
            )->id;
        }

        return WineCatalogWinery::query()->firstOrCreate(
            ['name' => $name],
            ['wineapi_id' => null],
        )->id;
    }

    /**
     * @param  array<string, mixed>  $region
     */
    private function resolveRegion(array $region): int
    {
        $wineapiId = isset($region['id']) ? (string) $region['id'] : null;
        $name = (string) $region['name'];
        $country = $region['country'] ?? null;

        if ($wineapiId) {
            return WineCatalogRegion::query()->updateOrCreate(
                ['wineapi_id' => $wineapiId],
                ['name' => $name, 'country' => $country],
            )->id;
        }

        return WineCatalogRegion::query()->firstOrCreate(
            ['name' => $name],
            ['wineapi_id' => null, 'country' => $country],
        )->id;
    }

    /**
     * @param  array<string, mixed>  $grape
     */
    private function resolveGrape(array $grape): int
    {
        $wineapiId = isset($grape['id']) ? (string) $grape['id'] : null;
        $name = (string) $grape['name'];
        $color = $grape['color'] ?? null;

        if ($wineapiId) {
            return WineCatalogGrape::query()->updateOrCreate(
                ['wineapi_id' => $wineapiId],
                ['name' => $name, 'color' => $color],
            )->id;
        }

        return WineCatalogGrape::query()->firstOrCreate(
            ['name' => $name],
            ['wineapi_id' => null, 'color' => $color],
        )->id;
    }
}
