<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A submitted-but-unprocessed PMO transaction (float). See the
 * create_pending_transactions migration for why it is kept apart from the
 * settled `transactions` ledger.
 */
class PendingTransaction extends Model
{
    protected $fillable = [
        'scheme', 'submitted_at', 'trans_type', 'account_no', 'fund_code', 'fund_name',
        'contribution_type', 'amount', 'units',
        'switch_to_account', 'switch_to_fund', 'source_pdf', 'captured_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'captured_at'  => 'datetime',
        'amount'       => 'decimal:2',
        'units'        => 'decimal:4',
    ];

    /**
     * Clear pending rows that have since settled. Each PMO account number is
     * fund-specific, so a settled transaction on the same account on/after the
     * request was submitted means the float has been processed. Call after any
     * statement ingest. Returns the number cleared.
     */
    public static function reconcile(): int
    {
        $cleared = 0;
        foreach (static::all() as $p) {
            $settled = Transaction::where('account_no', $p->account_no)
                ->whereDate('trans_date', '>=', $p->submitted_at->toDateString())
                ->exists();
            if ($settled) {
                $p->delete();
                $cleared++;
            }
        }

        return $cleared;
    }
}
