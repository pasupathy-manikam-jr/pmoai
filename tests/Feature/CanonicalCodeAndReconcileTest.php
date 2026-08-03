<?php

namespace Tests\Feature;

use App\Models\Fund;
use App\Models\PendingTransaction;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalCodeAndReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_canonical_code_normalises_case_from_catalog(): void
    {
        Fund::create(['name' => 'PUBLIC e-EMAS GOLD FUND', 'code' => 'PeEMAS']);
        Fund::create(['name' => 'PUBLIC INDONESIA SELECT FUND', 'code' => 'PINDOSF']);

        // any casing resolves to the catalog's canonical casing
        $this->assertSame('PeEMAS', Fund::canonicalCode('PEEMAS'));
        $this->assertSame('PeEMAS', Fund::canonicalCode('peemas'));
        $this->assertSame('PeEMAS', Fund::canonicalCode('PeEMAS'));
        // codes not in the catalog pass through unchanged
        $this->assertSame('UNKNOWN', Fund::canonicalCode('UNKNOWN'));
    }

    public function test_reconcile_clears_a_pending_that_has_settled(): void
    {
        PendingTransaction::create([
            'scheme' => 'ut', 'submitted_at' => '2026-07-29 11:24:00', 'trans_type' => 'SWR',
            'account_no' => '128960916', 'fund_name' => 'PUBLIC e-ISLAMIC INDIA GLOBAL EQUITY',
            'amount' => 0, 'units' => 100000,
        ]);
        // a settled transaction lands on the same account, on/after submission
        Transaction::create([
            'trans_date' => '2026-07-30', 'account_no' => '128960916', 'fund_code' => 'PEIIGEF',
            'trans_type' => 'SWR', 'net' => -25000, 'units' => -100000, 'trans_ref' => 'TR-TESTA',
        ]);

        $cleared = PendingTransaction::reconcile();

        $this->assertSame(1, $cleared);
        $this->assertSame(0, PendingTransaction::count());
    }

    public function test_reconcile_keeps_pending_with_no_matching_settlement(): void
    {
        PendingTransaction::create([
            'scheme' => 'prs', 'submitted_at' => '2026-07-29 11:31:00', 'trans_type' => 'AC',
            'account_no' => '06244382', 'fund_name' => 'PRS STRATEGIC EQUITY', 'amount' => 3000, 'units' => 0,
        ]);
        // a settled transaction on a DIFFERENT account must not clear it
        Transaction::create([
            'trans_date' => '2026-07-30', 'account_no' => '999999999', 'fund_code' => 'OTHER',
            'trans_type' => 'AI', 'net' => 500, 'units' => 100, 'trans_ref' => 'TR-TESTB',
        ]);

        $cleared = PendingTransaction::reconcile();

        $this->assertSame(0, $cleared);
        $this->assertSame(1, PendingTransaction::count());
    }

    public function test_reconcile_ignores_a_transaction_dated_before_submission(): void
    {
        PendingTransaction::create([
            'scheme' => 'ut', 'submitted_at' => '2026-07-29 11:24:00', 'trans_type' => 'SWR',
            'account_no' => '128960916', 'fund_name' => 'X', 'amount' => 0, 'units' => 1,
        ]);
        // an OLD transaction on the same account (a prior buy) must not count
        Transaction::create([
            'trans_date' => '2026-01-01', 'account_no' => '128960916', 'fund_code' => 'PEIIGEF',
            'trans_type' => 'AI', 'net' => 1000, 'units' => 4000, 'trans_ref' => 'TR-TESTC',
        ]);

        $this->assertSame(0, PendingTransaction::reconcile());
        $this->assertSame(1, PendingTransaction::count());
    }
}
