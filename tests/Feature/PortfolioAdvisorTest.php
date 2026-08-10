<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\FundDetail;
use App\Services\PortfolioAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortfolioAdvisorTest extends TestCase
{
    use RefreshDatabase;

    private function fund(string $name, string $code, string $cat, string $risk, float $r3): void
    {
        Fund::create([
            'name' => $name, 'code' => $code, 'category' => $cat,
            'risk' => $risk, 'return_1y' => $r3, 'return_3y' => $r3,
        ]);
    }

    private function hold(string $name, string $code, float $value): void
    {
        FundDetail::create([
            'name' => $name, 'code' => $code, 'raw_text' => '',
            'payload' => ['position' => ['invested' => $value, 'current_value' => $value]],
        ]);
    }

    public function test_flags_concentration_switch_and_missing_bond(): void
    {
        // Catalogue: a weak held e-equity, a stronger same-series peer, a bond.
        $this->fund('PUBLIC e-WEAK EQUITY', 'PeWEAK', 'EQ', 'High', 5.0);
        $this->fund('PUBLIC e-STRONG EQUITY', 'PeSTRONG', 'EQ', 'High', 40.0);   // same risk, far better
        $this->fund('PUBLIC ENHANCED BOND', 'PENHB', 'BO', 'Low', 12.0);

        // You hold only the weak one → 100% of the book (over-concentrated, no bond).
        $this->hold('PUBLIC e-WEAK EQUITY', 'PeWEAK', 100000);

        $plan = app(PortfolioAdvisor::class)->analyze();

        // TRIM: single fund is 100% > 30%.
        $this->assertNotEmpty($plan['trim']);
        // SWITCH: weak → strong (same category/series, higher 3Y, equal risk).
        $this->assertNotEmpty($plan['switch']);
        $this->assertStringContainsString('STRONG', $plan['switch'][0]['to']);
        // BUY: no bond held → suggest the bond fund.
        $bondSug = collect($plan['buy'])->firstWhere('category', 'Bond');
        $this->assertNotNull($bondSug);
        $this->assertStringContainsString('ENHANCED BOND', $bondSug['options'][0]['name']);
    }

    public function test_prs_funds_are_never_proposed(): void
    {
        $this->fund('PRS EQUITY', 'PRS-EQF', 'PRS', 'High', 8.0);
        $this->hold('PRS EQUITY', 'PRS-EQF', 50000);

        $plan = app(PortfolioAdvisor::class)->analyze();

        // A single PRS holding is 100% but PRS is retirement-locked → no switch
        // proposal that references it, and it isn't offered as a buy target.
        $this->assertEmpty($plan['switch']);
        foreach ($plan['buy'] as $b) {
            foreach ($b['options'] as $o) {
                $this->assertStringNotContainsString('PRS', $o['name']);
            }
        }
    }
}
