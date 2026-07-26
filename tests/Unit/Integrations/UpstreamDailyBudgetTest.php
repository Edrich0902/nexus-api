<?php

namespace Tests\Unit\Integrations;

use App\Integrations\Support\UpstreamDailyBudget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpstreamDailyBudgetTest extends TestCase
{
    use RefreshDatabase;

    private UpstreamDailyBudget $budget;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.wineapi.daily_limit' => 10,
            'services.wineapi.reserve_for_enrichment' => 3,
            'services.wineapi.budget_timezone' => 'UTC',
        ]);

        $this->budget = app(UpstreamDailyBudget::class);
    }

    public function test_reserve_increments_used_until_ceiling(): void
    {
        $this->assertTrue($this->budget->reserve('wineapi', 7));
        $this->assertSame(1, $this->budget->used('wineapi'));

        for ($i = 0; $i < 6; $i++) {
            $this->assertTrue($this->budget->reserve('wineapi', 7));
        }

        $this->assertSame(7, $this->budget->used('wineapi'));
        $this->assertFalse($this->budget->reserve('wineapi', 7));
        $this->assertSame(7, $this->budget->used('wineapi'));
    }

    public function test_search_ceiling_leaves_headroom_for_enrichment(): void
    {
        $this->assertSame(7, $this->budget->searchCeiling('wineapi'));
        $this->assertSame(10, $this->budget->enrichmentCeiling('wineapi'));

        for ($i = 0; $i < 7; $i++) {
            $this->assertTrue($this->budget->reserve('wineapi', $this->budget->searchCeiling('wineapi')));
        }

        $this->assertFalse($this->budget->reserve('wineapi', $this->budget->searchCeiling('wineapi')));
        $this->assertTrue($this->budget->reserve('wineapi', $this->budget->enrichmentCeiling('wineapi')));
        $this->assertSame(8, $this->budget->used('wineapi'));
    }

    public function test_snapshot_reports_remaining_and_search_remaining(): void
    {
        $this->budget->reserve('wineapi', 7);
        $this->budget->reserve('wineapi', 7);

        $snapshot = $this->budget->snapshot('wineapi');

        $this->assertSame('wineapi', $snapshot['provider']);
        $this->assertSame(2, $snapshot['used']);
        $this->assertSame(10, $snapshot['daily_limit']);
        $this->assertSame(8, $snapshot['remaining']);
        $this->assertSame(5, $snapshot['search_remaining']);
        $this->assertGreaterThan(0, $snapshot['resets_in_seconds']);
        $this->assertSame($this->budget->today('wineapi'), $snapshot['usage_date']);
    }

    public function test_zero_ceiling_never_grants(): void
    {
        $this->assertFalse($this->budget->reserve('wineapi', 0));
        $this->assertSame(0, $this->budget->used('wineapi'));
    }

    public function test_exhaustion_at_full_daily_limit(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->assertTrue($this->budget->reserve('wineapi', 10));
        }

        $this->assertFalse($this->budget->reserve('wineapi', 10));
        $this->assertSame(0, $this->budget->remaining('wineapi'));
    }

    public function test_date_rollover_starts_a_fresh_counter(): void
    {
        $this->budget->reserve('wineapi', 10);

        $yesterday = now('UTC')->subDay()->toDateString();
        DB::table('upstream_request_budgets')->insert([
            'provider' => 'wineapi',
            'usage_date' => $yesterday,
            'used' => 10,
            'daily_limit' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->budget->used('wineapi'));
        $this->assertSame(9, $this->budget->remaining('wineapi'));
    }
}
