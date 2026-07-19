<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly Public Mutual Fund Report (MFR) snapshot per fund.
     * One row per (code, period=YYYY-MM). Re-ingest of same month
     * overwrites. Holds the deep fields the price/perf paste lacks:
     * fund size (exact), benchmark, volatility, asset/geo/sector/holdings
     * mix, distributions, fx exposure, calendar returns.
     */
    public function up(): void
    {
        Schema::create('fund_factsheets', function (Blueprint $table) {
            $table->id();
            $table->string('code')->index();
            $table->string('period', 7); // YYYY-MM
            $table->string('name')->nullable();

            // AUM
            $table->decimal('fund_size_nav_myr', 18, 2)->nullable();   // RM millions stored as raw RM
            $table->decimal('fund_size_units',   18, 2)->nullable();   // units in millions raw

            // Benchmark
            $table->string('benchmark_name')->nullable();
            $table->jsonb('benchmark_returns')->nullable(); // {ytd,1y,3y,5y,10y,since}

            // Risk (real, not label)
            $table->decimal('volatility_factor', 8, 2)->nullable();
            $table->string('volatility_class')->nullable(); // low|mod|high|v.high

            // Mix
            $table->jsonb('asset_allocation')->nullable();  // {equity_dom,equity_fgn,money_mkt,bond,...}
            $table->jsonb('geo_foreign')->nullable();        // {USA: 13.7, China: 3.4, ...}
            $table->jsonb('fx_exposure')->nullable();        // {USD: 13.7, CNY: 3.4, ...}
            $table->decimal('fx_foreign_total_pct', 6, 2)->nullable();

            $table->jsonb('top_sectors')->nullable();        // [{sector, pct}]
            $table->jsonb('top_holdings')->nullable();       // [security_name, ...]

            // Distributions
            $table->jsonb('distributions')->nullable();      // [{year, sen, date, yield_pct}]

            // Calendar / annual returns
            $table->jsonb('calendar_returns')->nullable();   // {fund: {2016: ..., 2025: ...}, benchmark: {...}}

            // Fixed-income only
            $table->decimal('duration_yrs', 6, 2)->nullable();

            $table->string('source_pdf')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique(['code', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_factsheets');
    }
};
