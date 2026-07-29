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
        'submitted_at', 'trans_type', 'account_no', 'fund_code', 'fund_name',
        'contribution_type', 'amount', 'units',
        'switch_to_account', 'switch_to_fund', 'source_pdf', 'captured_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'captured_at'  => 'datetime',
        'amount'       => 'decimal:2',
        'units'        => 'decimal:4',
    ];
}
