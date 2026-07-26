<?php

namespace App\Jobs\FoodDrink;

use App\Services\Cellar\WineEnrichmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class RetryPendingWineEnrichmentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue((string) config('services.wineapi.sync.queue', 'default'));
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('retry-pending-wine-enrichment'))
                ->releaseAfter(60)
                ->expireAfter(300),
        ];
    }

    public function handle(WineEnrichmentService $enrichment): void
    {
        $enrichment->dispatchPending(15);
    }
}
