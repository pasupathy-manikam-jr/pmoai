<?php

namespace Tests\Feature;

use App\Models\FundDetail;
use App\Services\PortfolioExposure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function held(string $name, float $value, string $geoBlock): void
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

    public function test_currency_exposure_built_from_captured_geography(): void
    {
        // A US fund → USD, a Malaysia fund → MYR, a gold fund → USD (gold is USD-priced).
        $this->held('PUBLIC US EQUITY', 1000, "Geographical Breakdown\nUnited States 100.0%\n");
        $this->held('PUBLIC BOND', 500, "Geographical Breakdown\nMalaysia 100.0%\n");
        $this->held('PUBLIC e-EMAS GOLD FUND', 300, ''); // gold flag by name, geo ignored

        $c = app(PortfolioExposure::class)->currencies();

        $by = collect($c['rows'])->keyBy('ccy');
        $this->assertEqualsWithDelta(1300, $by['USD']['rm'], 0.01); // 1000 US + 300 gold
        $this->assertEqualsWithDelta(500, $by['MYR']['rm'], 0.01);
        $this->assertEqualsWithDelta(1800, $c['total'], 0.01);
        // foreign = everything not MYR → 1300/1800
        $this->assertEqualsWithDelta(72.2, $c['foreign_pct'], 0.2);
    }

    public function test_unlisted_geography_remainder_counts_as_myr(): void
    {
        // Only 60% is placed in a country → the other 40% falls back to MYR.
        $this->held('PUBLIC ASIA', 1000, "Geographical Breakdown\nUnited States 60.0%\n");

        $c = app(PortfolioExposure::class)->currencies();
        $by = collect($c['rows'])->keyBy('ccy');

        $this->assertEqualsWithDelta(600, $by['USD']['rm'], 0.01);
        $this->assertEqualsWithDelta(400, $by['MYR']['rm'], 0.01);
    }
}
