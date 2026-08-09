<?php

namespace App\Services\Analysis;

use App\Integrations\Gemini\AnalysisInput;
use App\Integrations\Gemini\BaseGeminiAI;
use App\Integrations\Gemini\DrinkAnalysis;
use App\Models\Beer\BeerBeer;
use App\Models\Beer\BeerStyle;
use App\Models\Cellar\CellarWine;
use App\Models\Spirit\SpiritSpirit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DrinkAnalysisApplier
{
    public function apply(Model $record, DrinkAnalysis $analysis, string $type, AnalysisInput $input): void
    {
        $identity = $analysis->identity;
        $updates = [
            'ai_analysis' => $analysis->toArray(),
            'analysis_status' => 'complete',
            'analysed_at' => now(),
            'analysis_model' => $analysis->modelId,
            'analysis_prompt_version' => BaseGeminiAI::PROMPT_VERSION,
            'analysis_error' => null,
            'analysis_media_asset_id' => $record->getAttribute('media_asset_id'),
        ];

        match ($type) {
            'cellar_wine' => $this->mergeWine($record, $identity, $updates),
            'beer_beer' => $this->mergeBeer($record, $identity, $updates),
            'spirit_spirit' => $this->mergeSpirit($record, $identity, $updates),
            default => $record->forceFill($updates)->save(),
        };
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $updates
     */
    private function mergeWine(Model $record, array $identity, array $updates): void
    {
        /** @var CellarWine $record */
        $this->fillIfEmpty($updates, $record, 'name', $identity['name'] ?? null);
        $this->fillIfEmpty($updates, $record, 'producer_name', $identity['producer'] ?? null);
        $this->fillIfEmpty($updates, $record, 'wine_type', $identity['category'] ?? $identity['style'] ?? null);
        $this->fillIfEmpty($updates, $record, 'region_name', $identity['region'] ?? null);
        $this->fillIfEmpty($updates, $record, 'country', $identity['country'] ?? null);

        $vintage = $identity['vintage_or_age'] ?? null;
        if ($this->isEmpty($record->getAttribute('vintage')) && is_string($vintage) && preg_match('/\d{4}/', $vintage, $m)) {
            $updates['vintage'] = (int) $m[0];
        }

        $record->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $updates
     */
    private function mergeBeer(Model $record, array $identity, array $updates): void
    {
        /** @var BeerBeer $record */
        $this->fillIfEmpty($updates, $record, 'name', $identity['name'] ?? null);
        $this->fillIfEmpty($updates, $record, 'abv', $identity['abv'] ?? null);

        $payload = is_array($updates['ai_analysis'] ?? null) ? $updates['ai_analysis'] : [];
        $sensory = is_array($payload['sensory'] ?? null) ? $payload['sensory'] : [];

        if ($this->isEmpty($record->getAttribute('ibu')) && isset($sensory['bitterness_ibu']) && is_numeric($sensory['bitterness_ibu'])) {
            $updates['ibu'] = (int) $sensory['bitterness_ibu'];
        }
        if ($this->isEmpty($record->getAttribute('beer_style_id')) && ! empty($identity['style'])) {
            $styleId = $this->mapBeerStyle((string) $identity['style']);
            if ($styleId !== null) {
                $updates['beer_style_id'] = $styleId;
            }
        }

        $record->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  array<string, mixed>  $updates
     */
    private function mergeSpirit(Model $record, array $identity, array $updates): void
    {
        /** @var SpiritSpirit $record */
        $this->fillIfEmpty($updates, $record, 'name', $identity['name'] ?? null);
        $this->fillIfEmpty($updates, $record, 'producer', $identity['producer'] ?? null);
        $this->fillIfEmpty($updates, $record, 'category', $identity['category'] ?? $identity['style'] ?? null);
        $this->fillIfEmpty($updates, $record, 'age_statement', $identity['vintage_or_age'] ?? null);
        $this->fillIfEmpty($updates, $record, 'region', $identity['region'] ?? null);
        $this->fillIfEmpty($updates, $record, 'country', $identity['country'] ?? null);
        $this->fillIfEmpty($updates, $record, 'abv', $identity['abv'] ?? null);

        $record->forceFill($updates)->save();
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function fillIfEmpty(array &$updates, Model $record, string $column, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! $this->isEmpty($record->getAttribute($column))) {
            return;
        }
        $updates[$column] = $value;
    }

    private function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function mapBeerStyle(string $styleName): ?int
    {
        $needle = Str::lower(trim($styleName));
        if ($needle === '') {
            return null;
        }

        $styles = BeerStyle::query()->get(['id', 'name', 'slug']);
        foreach ($styles as $style) {
            if (Str::lower($style->name) === $needle || Str::lower($style->slug) === $needle) {
                return (int) $style->id;
            }
        }
        foreach ($styles as $style) {
            if (str_contains(Str::lower($style->name), $needle) || str_contains($needle, Str::lower($style->name))) {
                return (int) $style->id;
            }
        }

        return null;
    }
}
