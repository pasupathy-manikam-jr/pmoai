<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->date('trans_date');
            $table->string('account_no', 20);
            $table->string('fund_code', 20);
            $table->string('trans_type', 8);        // II|AI|RII|SWS|SWR|RP|DR|DP|...
            $table->string('reference')->nullable();
            $table->decimal('gross', 14, 2)->nullable();
            $table->decimal('charge_pct', 7, 2)->nullable();
            $table->decimal('charge_amt', 12, 2)->nullable();
            $table->decimal('sst', 12, 2)->nullable();
            $table->decimal('net', 14, 2)->nullable();
            $table->decimal('price', 12, 4)->nullable();
            $table->decimal('units', 16, 4)->nullable();
            $table->string('trans_ref', 40)->unique();  // TR… — natural dedupe
            $table->string('source_pdf')->nullable();
            $table->timestamps();
            $table->index(['fund_code', 'trans_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
