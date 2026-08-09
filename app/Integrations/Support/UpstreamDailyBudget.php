<?php

namespace App\Integrations\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persistent daily request budget for hard-capped upstream APIs (e.g. WineAPI 100/day).
 *
 * Search and enrichment use different ceilings so interactive browsing cannot starve
 * background enrichment jobs.
 */
class UpstreamDailyBudget
{
    public function reserve(string $provider, int $ceiling): bool
    {
        return $this->reserveWithDailyLimit($provider, $ceiling, $this->dailyLimit($provider));
    }

    /**
     * Reserve against an explicit daily cap (for multi-model pools whose limits live outside services.{provider}).
     */
    public function reserveWithDailyLimit(string $provider, int $ceiling, int $dailyLimit): bool
    {
        $ceiling = max(0, $ceiling);
        $dailyLimit = max(1, $dailyLimit);
        if ($ceiling === 0) {
            return false;
        }

        $usageDate = $this->today($provider);
        $this->ensureRow($provider, $usageDate, $dailyLimit);

        $granted = DB::table('upstream_request_budgets')
            ->where('provider', $provider)
            ->where('usage_date', $usageDate)
            ->where('used', '<', min($ceiling, $dailyLimit))
            ->increment('used');

        return $granted === 1;
    }

    public function usedWithTimezone(string $provider, string $timezone, int $dailyLimit): int
    {
        $usageDate = CarbonImmutable::now($timezone)->toDateString();
        $this->ensureRow($provider, $usageDate, $dailyLimit);

        $row = DB::table('upstream_request_budgets')
            ->where('provider', $provider)
            ->where('usage_date', $usageDate)
            ->first();

        return (int) ($row->used ?? 0);
    }

    public function todayInTimezone(string $timezone): string
    {
        return CarbonImmutable::now($timezone)->toDateString();
    }

    public function secondsUntilResetInTimezone(string $timezone): int
    {
        $now = CarbonImmutable::now($timezone);
        $next = $now->startOfDay()->addDay();

        return max(1, $now->diffInSeconds($next));
    }

    public function used(string $provider): int
    {
        $row = $this->row($provider);

        return (int) ($row->used ?? 0);
    }

    public function remaining(string $provider): int
    {
        $limit = $this->dailyLimit($provider);

        return max(0, $limit - $this->used($provider));
    }

    public function dailyLimit(string $provider): int
    {
        return max(1, (int) config("services.{$provider}.daily_limit", 100));
    }

    public function searchCeiling(string $provider): int
    {
        $limit = $this->dailyLimit($provider);
        $reserve = max(0, (int) config("services.{$provider}.reserve_for_enrichment", 20));

        return max(0, $limit - $reserve);
    }

    public function enrichmentCeiling(string $provider): int
    {
        return $this->dailyLimit($provider);
    }

    /**
     * Seconds until the next budget day starts in the provider's timezone.
     */
    public function secondsUntilReset(string $provider): int
    {
        $tz = $this->timezone($provider);
        $now = CarbonImmutable::now($tz);
        $next = $now->startOfDay()->addDay();

        return max(1, $now->diffInSeconds($next));
    }

    /**
     * Snapshot for API consumers.
     *
     * @return array{provider: string, used: int, daily_limit: int, remaining: int, search_remaining: int, resets_in_seconds: int, usage_date: string}
     */
    public function snapshot(string $provider): array
    {
        $used = $this->used($provider);
        $limit = $this->dailyLimit($provider);
        $searchCeiling = $this->searchCeiling($provider);

        return [
            'provider' => $provider,
            'used' => $used,
            'daily_limit' => $limit,
            'remaining' => max(0, $limit - $used),
            'search_remaining' => max(0, $searchCeiling - $used),
            'resets_in_seconds' => $this->secondsUntilReset($provider),
            'usage_date' => $this->today($provider),
        ];
    }

    public function today(string $provider): string
    {
        return CarbonImmutable::now($this->timezone($provider))->toDateString();
    }

    private function timezone(string $provider): string
    {
        return (string) config("services.{$provider}.budget_timezone", 'UTC');
    }

    private function ensureRow(string $provider, string $usageDate, int $dailyLimit): void
    {
        DB::table('upstream_request_budgets')->insertOrIgnore([
            'provider' => $provider,
            'usage_date' => $usageDate,
            'used' => 0,
            'daily_limit' => $dailyLimit,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Keep daily_limit in sync with current config.
        DB::table('upstream_request_budgets')
            ->where('provider', $provider)
            ->where('usage_date', $usageDate)
            ->where('daily_limit', '!=', $dailyLimit)
            ->update([
                'daily_limit' => $dailyLimit,
                'updated_at' => now(),
            ]);
    }

    private function row(string $provider): ?object
    {
        $usageDate = $this->today($provider);
        $this->ensureRow($provider, $usageDate, $this->dailyLimit($provider));

        return DB::table('upstream_request_budgets')
            ->where('provider', $provider)
            ->where('usage_date', $usageDate)
            ->first();
    }
}
