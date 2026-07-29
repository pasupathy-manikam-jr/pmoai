<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Float ("Urus Niaga Apungan") transactions: requests SUBMITTED to PMO
     * but not yet processed/priced. Distinct from the settled `transactions`
     * ledger — these have no trans_ref, no price, and amount/units may be 0
     * until allocation. Kept separate so they never pollute XIRR or get
     * double-counted when they settle. The PMO float statement is a FULL
     * snapshot of what is currently pending, so ingest replaces the whole set
     * (truncate + insert) — a settled item simply drops off the next float.
     */
    public function up(): void
    {
        Schema::create('pending_transactions', function (Blueprint $table) {
            $table->id();
            $table->timestamp('submitted_at');        // Tran Date + time
            $table->string('trans_type', 8);          // SWR, AC, RP, …
            $table->string('account_no', 20);
            $table->string('fund_code', 20)->nullable();
            $table->string('fund_name');
            $table->string('contribution_type', 8)->nullable();  // PRS: IND/EPF
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('units', 16, 4)->nullable();
            $table->string('switch_to_account', 20)->nullable();
            $table->string('switch_to_fund')->nullable();
            $table->string('source_pdf')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();
            $table->index('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pending_transactions');
    }
};
