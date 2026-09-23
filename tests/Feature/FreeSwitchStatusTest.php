<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\PortfolioAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeSwitchStatusTest extends TestCase
{
    use RefreshDatabase;

    private function buy(string $code, string $date, float $units = 100): void
    {
        Transaction::create([
            'trans_ref' => $code.'-'.$date, 'fund_code' => $code, 'trans_type' => $units > 0 ? 'AI' : 'SWR', 'account_no' => 'T1',
            'trans_date' => $date, 'units' => $units, 'price' => 1, 'gross' => abs($units), 'net' => abs($units),
        ]);
    }

    public function test_units_older_than_ninety_days_switch_free(): void
    {
        $this->buy('PIRESGF', now()->subDays(120)->toDateString());

        $s = PortfolioAdvisor::freeSwitchStatus('PUBLIC ISLAMIC REGIONAL ESG FUND', 'PIRESGF', 'EQ');

        $this->assertSame('free', $s['state']);
    }

    /** PMO switches oldest units first — a top-up only locks its own units. */
    public function test_a_top_up_only_locks_its_own_units(): void
    {
        $this->buy('PIRESGF', now()->subDays(200)->toDateString(), 300);
        $this->buy('PIRESGF', now()->subDays(30)->toDateString(), 100);

        $s = PortfolioAdvisor::freeSwitchStatus('PUBLIC ISLAMIC REGIONAL ESG FUND', 'PIRESGF', 'EQ');

        $this->assertSame('partly', $s['state']);
        $this->assertEquals(75.0, $s['free_pct']);
        $this->assertSame(now()->subDays(30)->addDays(90)->toDateString(), $s['free_date']);
    }

    /** A switch-out eats the OLDEST lot first, leaving only young units. */
    public function test_switching_out_consumes_oldest_units_first(): void
    {
        $this->buy('PIRESGF', now()->subDays(200)->toDateString(), 100);
        $this->buy('PIRESGF', now()->subDays(20)->toDateString(), 100);
        $this->buy('PIRESGF', now()->subDays(10)->toDateString(), -100);

        $s = PortfolioAdvisor::freeSwitchStatus('PUBLIC ISLAMIC REGIONAL ESG FUND', 'PIRESGF', 'EQ');

        $this->assertSame('waiting', $s['state']);
        $this->assertEquals(0.0, $s['free_pct']);
    }

    /** Gold has no switch facility at all, however long it is held. */
    public function test_gold_never_becomes_switchable(): void
    {
        $this->buy('PEEMAS', now()->subDays(900)->toDateString());

        $this->assertSame('no_switch', PortfolioAdvisor::freeSwitchStatus('PUBLIC e-EMAS GOLD FUND', 'PEEMAS', 'OA')['state']);
    }

    public function test_prs_is_locked_and_cash_has_no_clock(): void
    {
        $this->assertSame('locked', PortfolioAdvisor::freeSwitchStatus('PRS EQUITY', 'PRS-EQF', 'PRS')['state']);
        $this->assertSame('cash', PortfolioAdvisor::freeSwitchStatus('PUBLIC e-CASH DEPOSIT - CLASS A', 'PeCDF-A', 'MM')['state']);
    }

    public function test_no_ingested_buy_is_reported_as_unknown_not_free(): void
    {
        $this->assertSame('unknown', PortfolioAdvisor::freeSwitchStatus('PUBLIC GLOBAL SELECT FUND', 'PGSF', 'EQ')['state']);
    }
}
