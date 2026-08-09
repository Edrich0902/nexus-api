<?php

namespace App\Models\Concerns;

/**
 * Columns: analysis_status, analysed_at, analysis_model, analysis_prompt_version,
 * analysis_error, ai_analysis, analysis_media_asset_id
 */
trait HasDrinkAnalysis
{
    public const ANALYSIS_NONE = 'none';

    public const ANALYSIS_PENDING = 'pending';

    public const ANALYSIS_COMPLETE = 'complete';

    public const ANALYSIS_FAILED = 'failed';

    public function isAnalysisComplete(): bool
    {
        return ($this->analysis_status ?? self::ANALYSIS_NONE) === self::ANALYSIS_COMPLETE
            && is_array($this->ai_analysis);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function analysisPayload(): ?array
    {
        if (! is_array($this->ai_analysis)) {
            return null;
        }

        return $this->ai_analysis;
    }

    /**
     * @return array<string, mixed>
     */
    public function analysisMetaForResource(): array
    {
        return [
            'analysis_status' => $this->analysis_status ?? self::ANALYSIS_NONE,
            'analysed_at' => $this->analysed_at?->toIso8601String(),
            'analysis_model' => $this->analysis_model,
            'analysis_prompt_version' => $this->analysis_prompt_version,
            'analysis_error' => $this->analysis_error,
            'ai_analysis' => $this->analysisPayload(),
        ];
    }
}
