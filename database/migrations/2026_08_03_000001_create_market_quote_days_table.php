<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Daily close history per market symbol — one row per (symbol, date),
     * accrued by pmoai:fetch-quotes and backfillable from Yahoo. This is our
     * OWN index history, independent of whether TradingView will chart a given
     * symbol. Powers the dashboard sparklines and any trend view.
     */
    public function up(): void
    {
        Schema::create('market_quote_days', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->date('quote_date');
            $table->decimal('price', 16, 4);
            $table->decimal('change_pct', 8, 2)->nullable();
            $table->timestamps();
            $table->unique(['symbol', 'quote_date']);
            $table->index(['symbol', 'quote_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_quote_days');
    }
};
