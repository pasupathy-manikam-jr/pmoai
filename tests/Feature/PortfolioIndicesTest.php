<?php

namespace Tests\Feature;

use App\Models\FundDetail;
use App\Services\PortfolioIndices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioIndicesTest extends TestCase
{
    use RefreshDatabase;

    private function held(string $name, float $value, string $geoBlock = ''): void
    {
        FundDetail::create([
            'name'     => $name,
            'raw_text' => '',
            'payload'  => [
                'position'   => ['invested' => $value, 'current_value' => $value],
                'allocation' => $geoBlock,
            ],
        ]);
    }

    public function test_country_exposure_maps_to_its_index_and_lists_the_fund(): void
    {
        $this->held('PUBLIC US EQUITY', 1000, "Geographical Breakdown\nUSA 100.0%\n");

        $out = app(PortfolioIndices::class)->derive();
        $bySym = collect($out)->keyBy('symbol');

        // USA exposure → NASDAQ index present, and the fund is attributed to it.
        $this->assertTrue($bySym->has('^IXIC'));
        $this->assertContains('US EQUITY', array_column($bySym['^IXIC']['funds'], 'name'));
    }

    public function test_macro_symbols_always_present(): void
    {
        $this->held('PUBLIC US EQUITY', 1000, "Geographical Breakdown\nUSA 100.0%\n");

        $syms = array_column(app(PortfolioIndices::class)->derive(), 'symbol');

        // USD/MYR, Brent, and home KLCI are always relevant to a MY portfolio.
        $this->assertContains('MYR=X', $syms);
        $this->assertContains('BZ=F', $syms);
        $this->assertContains('^KLSE', $syms);
    }

    public function test_gold_fund_adds_gold_and_is_not_treated_as_a_country(): void
    {
        $this->held('PUBLIC e-EMAS GOLD FUND', 500);

        $syms = array_column(app(PortfolioIndices::class)->derive(), 'symbol');

        $this->assertContains('GC=F', $syms);
    }

    public function test_symbols_helper_matches_derive(): void
    {
        $this->held('PUBLIC US EQUITY', 1000, "Geographical Breakdown\nUSA 100.0%\n");

        $svc = app(PortfolioIndices::class);
        $this->assertSame(array_column($svc->derive(), 'symbol'), $svc->symbols());
    }
}
