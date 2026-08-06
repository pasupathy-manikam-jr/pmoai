<?php

namespace Tests\Feature;

use App\Models\FundDetail;
use App\Models\PortfolioSnapshot;
use App\Models\Transaction;
use App\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    /** A held fund captured today (fresh), worth $value. */
    private function held(string $name, float $value): void
    {
        $d = FundDetail::create([
            'name'     => $name,
            'raw_text' => '',
            'payload'  => ['position' => ['invested' => $value, 'current_value' => $value]],
        ]);
        $d->forceFill(['captured_at' => now()])->save(); // fresh, so freshness doesn't skew drift assertions
    }

    private function priorTotal(float $value): void
    {
        PortfolioSnapshot::create([
            'snap_date' => now()->subDay()->toDateString(),
            'invested'  => $value,
            'value'     => $value,
        ]);
    }

    public function test_unexplained_drop_is_flagged(): void
    {
        $this->priorTotal(100000);       // yesterday the book was 100k
        $this->held('PUBLIC A', 90000);  // today it's 90k, no sell explains it

        $r = app(ReconciliationService::class)->check();

        $this->assertTrue($r['drift_flag']);
        $this->assertEqualsWithDelta(-10000, $r['delta'], 0.01);
        $this->assertSame('off', $r['tone']);   // most serious
    }

    public function test_drop_explained_by_redemption_is_not_flagged(): void
    {
        $this->priorTotal(100000);
        $this->held('PUBLIC A', 90000);

        // You actually took RM10k out — that legitimately lowers the total.
        Transaction::create([
            'trans_date' => now()->toDateString(), 'account_no' => '1', 'fund_code' => 'A',
            'trans_type' => 'SWR', 'net' => -10000, 'units' => -5000, 'trans_ref' => 'TR-OUT',
        ]);

        $r = app(ReconciliationService::class)->check();

        $this->assertFalse($r['drift_flag']);
        $this->assertEqualsWithDelta(10000, $r['redeemed'], 0.01);
    }

    public function test_healthy_gain_is_not_flagged(): void
    {
        $this->priorTotal(100000);
        $this->held('PUBLIC A', 101000);  // up 1%

        $r = app(ReconciliationService::class)->check();

        $this->assertFalse($r['drift_flag']);
        $this->assertEqualsWithDelta(1000, $r['delta'], 0.01);
    }
}
