<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * fund_prices is a MONTHLY series: exactly one row per fund per month.
     * Re-capture within the month overwrites price + price_date in place,
     * so price_date tracks the latest capture DAY (1..end-of-month). When
     * the month rolls over a new row is added (chart grows horizontally).
     *
     * The month key is an explicit `period` (YYYY-MM) column — price_date
     * stays the real day, so it can't be the uniqueness key itself.
     */
    public function up(): void
    {
        Schema::table('fund_prices', function (Blueprint $table) {
            $table->string('period', 7)->nullable()->after('price_date'); // YYYY-MM
        });

        DB::statement("UPDATE fund_prices SET period = to_char(price_date, 'YYYY-MM')");

        // Collapse to one row per (code, month): keep the latest id.
        DB::statement(<<<'SQL'
            DELETE FROM fund_prices a
            USING fund_prices b
            WHERE a.code = b.code
              AND a.period = b.period
              AND a.id < b.id
        SQL);

        Schema::table('fund_prices', function (Blueprint $table) {
            $table->dropIndex(['code', 'price_date']); // plain idx from 000001
            $table->string('period', 7)->nullable(false)->change();
            $table->unique(['code', 'period']);        // monthly key
            $table->index(['code', 'price_date']);     // chart ordering
        });
    }

    public function down(): void
    {
        Schema::table('fund_prices', function (Blueprint $table) {
            $table->dropUnique(['code', 'period']);
            $table->dropIndex(['code', 'price_date']);
            $table->dropColumn('period');
            $table->index(['code', 'price_date']);
        });
    }
};
