<?php

namespace App\Jobs\FoodDrink;

use App\Integrations\Exceptions\IntegrationException;
use App\Integrations\Support\UpstreamDailyBudget;
use App\Integrations\WineApi\WineApiIntegration;
use App\Models\WineCatalog\WineCatalogWine;
use App\Services\Cellar\WineEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class EnrichWineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 8;

    public int $timeout = 60;

    public function __construct(
        public readonly int $wineCatalogWineId,
    ) {
        $this->onQueue((string) config('services.wineapi.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('enrich-wine:'.$this->wineCatalogWineId))
                ->releaseAfter(30)
                ->expireAfter(120),
        ];
    }

    public function handle(
        WineEnrichmentService $enrichment,
        UpstreamDailyBudget $budget,
    ): void {
        $catalog = WineCatalogWine::query()->find($this->wineCatalogWineId);
        if ($catalog === null) {
            return;
        }

        if ($catalog->enrichment_status === WineCatalogWine::ENRICHMENT_COMPLETE) {
            return;
        }

        try {
            $result = $enrichment->enrich($catalog);
        } catch (IntegrationException $e) {
            if ($e->statusCode === 429) {
                $this->release($budget->secondsUntilReset(WineApiIntegration::PROVIDER));

                return;
            }

            throw $e;
        }

        if ($result['status'] === 'queued') {
            $this->release($budget->secondsUntilReset(WineApiIntegration::PROVIDER));

            return;
        }

        if ($result['status'] === 'pending') {
            $delay = max(15, (int) ($result['retry_after'] ?? 30));
            self::dispatch($this->wineCatalogWineId)->delay(now()->addSeconds($delay));
        }
    }
}
