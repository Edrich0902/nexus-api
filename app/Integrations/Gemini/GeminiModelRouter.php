<?php

namespace App\Integrations\Gemini;

use App\Integrations\Gemini\Exceptions\GeminiException;
use App\Integrations\Gemini\Exceptions\GeminiQuotaException;
use App\Integrations\Support\UpstreamDailyBudget;
use App\Integrations\Support\UpstreamRateGate;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ordered multi-model cascade over the Interactions API with per-model RPM/RPD.
 */
class GeminiModelRouter
{
    public function __construct(
        private readonly GeminiClient $client,
        private readonly UpstreamDailyBudget $budget,
        private readonly UpstreamRateGate $rateGate,
    ) {}

    /**
     * @param  string|list<array<string, mixed>>  $input
     */
    public function generateJson(
        string|array $input,
        ?string $systemInstruction = null,
        bool $requiresVision = false,
        array $generationConfig = [],
    ): GeminiGenerationResult {
        $models = $this->models($requiresVision);
        if ($models === []) {
            throw new GeminiQuotaException('No Gemini models configured.');
        }

        $lastError = null;
        $earliestRetry = 60;

        foreach ($models as $model) {
            if ($this->isCoolingDown($model)) {
                $earliestRetry = min($earliestRetry, max(1, $this->cooldownRemaining($model)));
                continue;
            }

            if (! $this->hasDailyBudget($model)) {
                $earliestRetry = min($earliestRetry, $this->budget->secondsUntilResetInTimezone($this->timezone()));
                continue;
            }

            if (! $this->rateGate->tryAcquire($model->budgetProviderKey(), $model->rpm, 60)) {
                $earliestRetry = min($earliestRetry, 60);
                continue;
            }

            if (! $this->budget->reserveWithDailyLimit($model->budgetProviderKey(), $model->rpd, $model->rpd)) {
                $earliestRetry = min($earliestRetry, $this->budget->secondsUntilResetInTimezone($this->timezone()));
                continue;
            }

            try {
                $result = $this->attemptModel($model, $input, $systemInstruction, $generationConfig);
                if ($result !== null) {
                    return $result;
                }
            } catch (GeminiException $e) {
                $lastError = $e;
                // Hard client errors stop cascade except 404/not-found (try next model).
                if ($e->statusCode >= 400 && $e->statusCode < 500
                    && $e->statusCode !== 429
                    && $e->statusCode !== 404) {
                    throw $e;
                }
            }
        }

        if ($lastError instanceof GeminiException && $lastError->statusCode >= 400 && $lastError->statusCode < 500
            && $lastError->statusCode !== 429 && $lastError->statusCode !== 404) {
            throw $lastError;
        }

        throw new GeminiQuotaException(
            'All Gemini models are rate-limited or exhausted.',
            max(1, $earliestRetry),
            $lastError,
        );
    }

    /**
     * @return array{models: list<array<string, mixed>>, any_available: bool}
     */
    public function snapshot(): array
    {
        $models = [];
        $any = false;
        foreach ($this->models(false) as $index => $model) {
            $used = $this->budget->usedWithTimezone($model->budgetProviderKey(), $this->timezone(), $model->rpd);
            $rpmRemaining = $this->rateGate->remaining($model->budgetProviderKey(), $model->rpm, 60);
            $coolUntil = $this->cooldownUntil($model);
            $available = $used < $model->rpd
                && $rpmRemaining > 0
                && ($coolUntil === null || $coolUntil <= time());

            if ($available) {
                $any = true;
            }

            $models[] = [
                'id' => $model->id,
                'label' => $model->label,
                'priority' => $index + 1,
                'rpm' => [
                    'limit' => $model->rpm,
                    'remaining' => $rpmRemaining,
                ],
                'rpd' => [
                    'limit' => $model->rpd,
                    'used' => $used,
                    'remaining' => max(0, $model->rpd - $used),
                ],
                'cooldown_until' => $coolUntil !== null ? date(DATE_ATOM, $coolUntil) : null,
                'available' => $available,
            ];
        }

        return [
            'models' => $models,
            'any_available' => $any,
        ];
    }

    /**
     * @param  string|list<array<string, mixed>>  $input
     * @param  array<string, mixed>  $generationConfig
     */
    private function attemptModel(
        GeminiModelDefinition $model,
        string|array $input,
        ?string $systemInstruction,
        array $generationConfig,
    ): ?GeminiGenerationResult {
        try {
            $response = $this->client->createInteraction(
                $model->id,
                $input,
                $systemInstruction,
                $generationConfig,
            );
        } catch (GeminiException $e) {
            Log::info('gemini.fallback', [
                'from' => $model->id,
                'reason' => 'connection',
                'message' => $e->getMessage(),
            ]);

            return null;
        }

        $status = $response->status();
        $bodyPreview = Str::limit((string) $response->body(), 240);

        if ($status === 429 || ($status >= 400 && $this->isRateLimitPayload($response))) {
            $retryAfter = $this->retryAfterSeconds($response);
            $this->setCooldown($model, $retryAfter);
            $this->rateGate->exhaust($model->budgetProviderKey());
            Log::info('gemini.fallback', [
                'from' => $model->id,
                'reason' => '429',
                'retry_after' => $retryAfter,
                'body' => $bodyPreview,
            ]);

            return null;
        }

        if ($status === 404 || $this->isModelUnavailablePayload($response)) {
            Log::info('gemini.fallback', [
                'from' => $model->id,
                'reason' => 'not_found',
                'status' => $status,
                'body' => $bodyPreview,
            ]);

            return null;
        }

        if ($status >= 500) {
            Log::info('gemini.fallback', [
                'from' => $model->id,
                'reason' => '5xx',
                'status' => $status,
                'body' => $bodyPreview,
            ]);

            return null;
        }

        if ($status >= 400) {
            $payload = $response->json();
            $message = is_array($payload)
                ? (string) data_get($payload, 'error.message', data_get($payload, 'message', 'Gemini request failed.'))
                : 'Gemini request failed.';
            throw new GeminiException("[{$model->id}] {$message}", $status, is_array($payload) ? $payload : null);
        }

        $json = $response->json();
        $interactionStatus = is_array($json) ? (string) ($json['status'] ?? 'completed') : 'completed';
        if ($interactionStatus !== '' && $interactionStatus !== 'completed') {
            Log::info('gemini.fallback', [
                'from' => $model->id,
                'reason' => 'interaction_status',
                'status' => $interactionStatus,
            ]);

            return null;
        }

        $text = $this->client->extractText($response);
        if ($text === '') {
            Log::info('gemini.fallback', [
                'from' => $model->id,
                'reason' => 'empty',
                'body' => $bodyPreview,
            ]);

            return null;
        }

        return new GeminiGenerationResult($text, $model->id);
    }

    private function isRateLimitPayload(Response $response): bool
    {
        $body = strtolower((string) $response->body());

        return str_contains($body, 'resource_exhausted')
            || str_contains($body, 'rate limit')
            || str_contains($body, 'quota exceeded')
            || str_contains($body, 'exceeded your current quota')
            || str_contains($body, 'too_many_requests')
            || str_contains($body, '"code":"too_many_requests"');
    }

    private function isModelUnavailablePayload(Response $response): bool
    {
        $body = strtolower((string) $response->body());

        return str_contains($body, 'no longer available')
            || str_contains($body, 'not_found')
            || str_contains($body, '"code":"not_found"');
    }

    private function retryAfterSeconds(Response $response): int
    {
        $header = (int) $response->header('Retry-After', 0);
        if ($header > 0) {
            return max(1, min(300, $header));
        }

        $body = (string) $response->body();
        if (preg_match('/retry in\s+([\d.]+)\s*s/i', $body, $m) === 1) {
            return max(1, min(300, (int) ceil((float) $m[1])));
        }

        return 60;
    }

    /**
     * @return list<GeminiModelDefinition>
     */
    public function models(bool $requiresVision = false): array
    {
        /** @var list<string> $cascade */
        $cascade = config('services.gemini.cascade', []);
        /** @var array<string, array<string, mixed>> $defs */
        $defs = config('services.gemini.models', []);
        $out = [];
        $priority = 0;

        foreach ($cascade as $id) {
            $id = trim((string) $id);
            if ($id === '' || ! isset($defs[$id])) {
                continue;
            }
            $def = $defs[$id];
            $supportsVision = (bool) ($def['supports_vision'] ?? true);
            if ($requiresVision && ! $supportsVision) {
                continue;
            }
            $priority++;
            $out[] = new GeminiModelDefinition(
                id: $id,
                label: (string) ($def['label'] ?? $id),
                rpm: max(1, (int) ($def['rpm'] ?? 15)),
                rpd: max(1, (int) ($def['rpd'] ?? 500)),
                supportsVision: $supportsVision,
                priority: $priority,
            );
        }

        return $out;
    }

    private function hasDailyBudget(GeminiModelDefinition $model): bool
    {
        $used = $this->budget->usedWithTimezone(
            $model->budgetProviderKey(),
            $this->timezone(),
            $model->rpd,
        );

        return $used < $model->rpd;
    }

    private function timezone(): string
    {
        return (string) config('services.gemini.budget_timezone', 'UTC');
    }

    private function cooldownCacheKey(GeminiModelDefinition $model): string
    {
        return 'gemini:cooldown:'.$model->id;
    }

    private function isCoolingDown(GeminiModelDefinition $model): bool
    {
        $until = $this->cooldownUntil($model);

        return $until !== null && $until > time();
    }

    private function cooldownUntil(GeminiModelDefinition $model): ?int
    {
        $until = Cache::get($this->cooldownCacheKey($model));

        return is_numeric($until) ? (int) $until : null;
    }

    private function cooldownRemaining(GeminiModelDefinition $model): int
    {
        $until = $this->cooldownUntil($model);
        if ($until === null) {
            return 0;
        }

        return max(0, $until - time());
    }

    private function setCooldown(GeminiModelDefinition $model, int $seconds): int
    {
        $seconds = max(1, min(3600, $seconds));
        Cache::put($this->cooldownCacheKey($model), time() + $seconds, $seconds);

        return $seconds;
    }
}
