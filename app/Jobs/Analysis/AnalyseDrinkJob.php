<?php

namespace App\Jobs\Analysis;

use App\Integrations\Gemini\Exceptions\GeminiQuotaException;
use App\Services\Analysis\DrinkAnalysisService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnalyseDrinkJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Hard ceiling so a stuck Gemini call cannot park the worker forever.
     * Must exceed one cascade pass of per-model HTTP timeouts.
     */
    public int $timeout = 180;

    public int $tries = 5;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $type,
        public int $id,
        public bool $force = false,
        public ?string $extraContext = null,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("analyse-drink:{$this->type}:{$this->id}"))
                ->releaseAfter(30)
                ->expireAfter(200),
        ];
    }

    public function handle(DrinkAnalysisService $service): void
    {
        try {
            $service->runAnalysis($this->type, $this->id, $this->force, $this->extraContext);
        } catch (GeminiQuotaException $e) {
            // Keep re-trying while attempts remain; last try must surface as failed
            // so the wine does not stick on "Analysing…" forever.
            if ($this->attempts() >= $this->tries) {
                throw $e;
            }

            $delay = max(15, min(300, $e->retryAfterSeconds));
            Log::info('analyse_drink.release', [
                'type' => $this->type,
                'id' => $this->id,
                'delay' => $delay,
                'attempt' => $this->attempts(),
                'tries' => $this->tries,
            ]);
            $this->release($delay);
        }
    }

    public function failed(?\Throwable $e): void
    {
        try {
            $service = app(DrinkAnalysisService::class);
            $record = $service->resolveRecord($this->type, $this->id);
            $record->forceFill([
                'analysis_status' => 'failed',
                'analysis_error' => Str::limit($e?->getMessage() ?? 'Analysis failed.', 480),
            ])->save();
        } catch (\Throwable) {
            // record may be gone
        }
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 30, 60, 120];
    }
}
