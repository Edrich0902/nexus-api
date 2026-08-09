<?php

namespace App\Services\Analysis;

use App\Integrations\Gemini\AnalysisInput;
use App\Integrations\Gemini\BaseGeminiAI;
use App\Integrations\Gemini\BeerGeminiAI;
use App\Integrations\Gemini\Exceptions\GeminiException;
use App\Integrations\Gemini\Exceptions\GeminiQuotaException;
use App\Integrations\Gemini\SpiritGeminiAI;
use App\Integrations\Gemini\WineGeminiAI;
use App\Jobs\Analysis\AnalyseDrinkJob;
use App\Models\Beer\BeerBeer;
use App\Models\Cellar\CellarWine;
use App\Models\Spirit\SpiritSpirit;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DrinkAnalysisService
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly WineGeminiAI $wineAi,
        private readonly BeerGeminiAI $beerAi,
        private readonly SpiritGeminiAI $spiritAi,
        private readonly DrinkAnalysisApplier $applier,
    ) {}

    /**
     * Queue or short-circuit analysis for a domain journal record.
     *
     * @return array{status: string, model: Model}
     */
    public function requestAnalysis(User $user, Model $record, bool $force = false, ?string $extraContext = null): array
    {
        $this->assertOwned($user, $record);

        if (! $force && method_exists($record, 'isAnalysisComplete') && $record->isAnalysisComplete()) {
            return ['status' => self::STATUS_COMPLETE, 'model' => $record->fresh()];
        }

        $record->forceFill([
            'analysis_status' => self::STATUS_PENDING,
            'analysis_error' => null,
        ])->save();

        AnalyseDrinkJob::dispatch(
            $this->morphType($record),
            (int) $record->getKey(),
            $force,
            $extraContext,
        )->onQueue((string) config('services.gemini.queue', 'default'));

        return ['status' => self::STATUS_PENDING, 'model' => $record->fresh()];
    }

    public function runAnalysis(string $type, int $id, bool $force = false, ?string $extraContext = null): Model
    {
        $record = $this->resolveRecord($type, $id);

        if (! $force && method_exists($record, 'isAnalysisComplete') && $record->isAnalysisComplete()) {
            return $record;
        }

        $record->forceFill([
            'analysis_status' => self::STATUS_PENDING,
            'analysis_error' => null,
        ])->save();

        $ai = $this->aiFor($type);
        $input = $this->buildInput($record, $type, $extraContext);

        try {
            $analysis = $ai->analyse($input);
            $this->applier->apply($record, $analysis, $type, $input);

            return $record->fresh() ?? $record;
        } catch (GeminiQuotaException $e) {
            $record->forceFill([
                'analysis_status' => self::STATUS_PENDING,
                'analysis_error' => 'Quota temporarily exhausted; will retry.',
            ])->save();
            throw $e;
        } catch (GeminiException $e) {
            $record->forceFill([
                'analysis_status' => self::STATUS_FAILED,
                'analysis_error' => Str::limit($e->getMessage(), 480),
            ])->save();
            throw $e;
        } catch (\Throwable $e) {
            Log::warning('drink.analysis.failed', [
                'type' => $type,
                'id' => $id,
                'message' => $e->getMessage(),
            ]);
            $record->forceFill([
                'analysis_status' => self::STATUS_FAILED,
                'analysis_error' => 'Analysis failed unexpectedly.',
            ])->save();
            throw $e;
        }
    }

    public function buildInput(Model $record, string $type, ?string $extraContext = null): AnalysisInput
    {
        $fields = match ($type) {
            'cellar_wine' => [
                'name' => $record->getAttribute('name'),
                'producer' => $record->getAttribute('producer_name'),
                'vintage' => $record->getAttribute('vintage'),
                'wine_type' => $record->getAttribute('wine_type'),
                'region' => $record->getAttribute('region_name'),
                'country' => $record->getAttribute('country'),
                'notes' => $record->getAttribute('notes'),
            ],
            'beer_beer' => [
                'name' => $record->getAttribute('name'),
                'producer' => $record->relationLoaded('brewery')
                    ? $record->getRelation('brewery')?->name
                    : $record->brewery?->name,
                'style' => $record->relationLoaded('style')
                    ? $record->getRelation('style')?->name
                    : $record->style?->name,
                'abv' => $record->getAttribute('abv'),
                'ibu' => $record->getAttribute('ibu'),
                'format' => $record->getAttribute('format'),
                'notes' => $record->getAttribute('notes'),
            ],
            'spirit_spirit' => [
                'name' => $record->getAttribute('name'),
                'producer' => $record->getAttribute('producer'),
                'category' => $record->getAttribute('category'),
                'age_statement' => $record->getAttribute('age_statement'),
                'abv' => $record->getAttribute('abv'),
                'region' => $record->getAttribute('region'),
                'country' => $record->getAttribute('country'),
                'notes' => $record->getAttribute('notes'),
            ],
            default => [],
        };

        return new AnalysisInput(
            fields: $fields,
            extraContext: $extraContext,
            imageUrl: $this->resolveCoverImageUrl($record),
        );
    }

    /**
     * Public image URL for Gemini remote fetch. Prefer Cloudinary card transform
     * so the model loads a compact label crop rather than the 2k master.
     */
    private function resolveCoverImageUrl(Model $record): ?string
    {
        $url = null;
        if (method_exists($record, 'resolvedImageUrl')) {
            $url = $record->resolvedImageUrl();
        }
        if (! is_string($url) || $url === '') {
            return null;
        }

        return $this->analysisImageUrl($url);
    }

    private function analysisImageUrl(string $url): string
    {
        if (! str_contains($url, 'res.cloudinary.com') || ! str_contains($url, '/image/upload/')) {
            return $url;
        }

        $rewritten = preg_replace(
            '#(/image/upload/)(?:t_[^/]+/)?#',
            '$1t_nexus_card/',
            $url,
            1,
        );

        return is_string($rewritten) && $rewritten !== '' ? $rewritten : $url;
    }

    private function aiFor(string $type): BaseGeminiAI
    {
        return match ($type) {
            'cellar_wine' => $this->wineAi,
            'beer_beer' => $this->beerAi,
            'spirit_spirit' => $this->spiritAi,
            default => throw new \InvalidArgumentException("Unsupported analysis type [{$type}]."),
        };
    }

    public function morphType(Model $record): string
    {
        return match (true) {
            $record instanceof CellarWine => 'cellar_wine',
            $record instanceof BeerBeer => 'beer_beer',
            $record instanceof SpiritSpirit => 'spirit_spirit',
            default => throw new \InvalidArgumentException('Unsupported analysis model.'),
        };
    }

    public function resolveRecord(string $type, int $id): Model
    {
        $model = match ($type) {
            'cellar_wine' => CellarWine::query()->find($id),
            'beer_beer' => BeerBeer::query()->with(['brewery', 'style'])->find($id),
            'spirit_spirit' => SpiritSpirit::query()->find($id),
            default => null,
        };

        if ($model === null) {
            throw new \RuntimeException("Record {$type}#{$id} not found for analysis.");
        }

        return $model;
    }

    private function assertOwned(User $user, Model $record): void
    {
        if ((int) $record->getAttribute('user_id') !== (int) $user->id) {
            abort(404);
        }
    }
}
