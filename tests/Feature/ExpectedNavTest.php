<?php

namespace Tests\Feature;

use App\Models\FundDetail;
use App\Models\MarketQuote;
use App\Services\ExpectedNav;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpectedNavTest extends TestCase
{
    use RefreshDatabase;

    private function hold(string $name, float $value, string $geo): void
    {
        FundDetail::create(['name' => $name, 'raw_text' => '', 'payload' => [
            'position' => ['invested' => $value, 'current_value' => $value], 'allocation' => $geo,
        ]]);
    }

    private function quote(string $sym, float $chg, int $hoursAgo = 0): void
    {
        MarketQuote::create(['symbol' => $sym, 'price' => 100, 'change_pct' => $chg, 'currency' => 'USD',
            'fetched_at' => now()->subHours($hoursAgo)]);
    }

    public function test_expected_move_is_geo_weight_times_index_move(): void
    {
        // 100% USA fund, NASDAQ +2% today, ringgit flat → expect +2% ≈ +RM200 on RM10k.
        $this->hold('PUBLIC US EQUITY', 10000, "Geographical Breakdown\nUSA 100.0%\n");
        $this->quote('^IXIC', 2.0);
        $this->quote('MYR=X', 0.0);

        $r = collect(app(ExpectedNav::class)->forHeld()['rows'])->firstWhere('name', 'US EQUITY');

        $this->assertEqualsWithDelta(2.0, $r['expected_pct'], 0.01);
        $this->assertEqualsWithDelta(200, $r['expected_rm'], 1);
        $this->assertTrue($r['usable']);
        $this->assertSame('NASDAQ (US)', $r['drivers'][0]['label']);
    }

    public function test_ringgit_move_adds_to_foreign_share(): void
    {
        // 50% USA / 50% Malaysia. NASDAQ flat, KLCI flat, USD/MYR +1% → only the
        // foreign half feels the ringgit: +0.5%.
        $this->hold('PUBLIC MIXED', 10000, "Geographical Breakdown\nUSA 50.0%\nMalaysia 50.0%\n");
        $this->quote('^IXIC', 0.0);
        $this->quote('^KLSE', 0.0);
        $this->quote('MYR=X', 1.0);

        $r = collect(app(ExpectedNav::class)->forHeld()['rows'])->firstWhere('name', 'MIXED');

        $this->assertEqualsWithDelta(0.5, $r['expected_pct'], 0.01);
    }

    public function test_stale_quotes_are_excluded_and_flagged(): void
    {
        $this->hold('PUBLIC US EQUITY', 10000, "Geographical Breakdown\nUSA 100.0%\n");
        $this->quote('^IXIC', 5.0, 72);   // 3 days old → stale

        $r = collect(app(ExpectedNav::class)->forHeld()['rows'])->firstWhere('name', 'US EQUITY');

        $this->assertContains('^IXIC', $r['stale']);
        $this->assertFalse($r['usable']);              // nothing fresh to map → not usable
        $this->assertEqualsWithDelta(0.0, $r['expected_pct'], 0.01);
    }

    public function test_gold_fund_follows_gold(): void
    {
        $this->hold('PUBLIC e-EMAS GOLD FUND', 10000, '');
        $this->quote('GC=F', -1.5);

        $r = collect(app(ExpectedNav::class)->forHeld()['rows'])->firstWhere('name', 'e-EMAS GOLD FUND');

        $this->assertEqualsWithDelta(-1.5, $r['expected_pct'], 0.01);
        $this->assertSame('Gold', $r['drivers'][0]['label']);
    }
}
