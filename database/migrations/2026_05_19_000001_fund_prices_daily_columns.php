<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * fund_prices stays one row per (code, period=YYYY-MM), but the month's
     * price is now spread across 31 daily columns d1..d31. Each "send to
     * pmoai" writes that fund's unit price into the column for TODAY's
     * day-of-month, so a month row records up to 31 daily points instead of
     * a single overwritten value.
     *
     * `price` / `price_date` are kept and still set to the latest capture,
     * so the existing detail chart + RecommendJob keep working unchanged.
     */
    public function up(): void
    {
        Schema::table('fund_prices', function (Blueprint $table) {
            for ($d = 1; $d <= 31; $d++) {
                $table->decimal("d{$d}", 12, 4)->nullable()->after('period');
            }
        });

        // Backfill: existing rows have one known day in price_date.
        DB::statement(<<<'SQL'
            UPDATE fund_prices
            SET d1  = CASE WHEN extract(day FROM price_date) = 1  THEN price END,
                d2  = CASE WHEN extract(day FROM price_date) = 2  THEN price END,
                d3  = CASE WHEN extract(day FROM price_date) = 3  THEN price END,
                d4  = CASE WHEN extract(day FROM price_date) = 4  THEN price END,
                d5  = CASE WHEN extract(day FROM price_date) = 5  THEN price END,
                d6  = CASE WHEN extract(day FROM price_date) = 6  THEN price END,
                d7  = CASE WHEN extract(day FROM price_date) = 7  THEN price END,
                d8  = CASE WHEN extract(day FROM price_date) = 8  THEN price END,
                d9  = CASE WHEN extract(day FROM price_date) = 9  THEN price END,
                d10 = CASE WHEN extract(day FROM price_date) = 10 THEN price END,
                d11 = CASE WHEN extract(day FROM price_date) = 11 THEN price END,
                d12 = CASE WHEN extract(day FROM price_date) = 12 THEN price END,
                d13 = CASE WHEN extract(day FROM price_date) = 13 THEN price END,
                d14 = CASE WHEN extract(day FROM price_date) = 14 THEN price END,
                d15 = CASE WHEN extract(day FROM price_date) = 15 THEN price END,
                d16 = CASE WHEN extract(day FROM price_date) = 16 THEN price END,
                d17 = CASE WHEN extract(day FROM price_date) = 17 THEN price END,
                d18 = CASE WHEN extract(day FROM price_date) = 18 THEN price END,
                d19 = CASE WHEN extract(day FROM price_date) = 19 THEN price END,
                d20 = CASE WHEN extract(day FROM price_date) = 20 THEN price END,
                d21 = CASE WHEN extract(day FROM price_date) = 21 THEN price END,
                d22 = CASE WHEN extract(day FROM price_date) = 22 THEN price END,
                d23 = CASE WHEN extract(day FROM price_date) = 23 THEN price END,
                d24 = CASE WHEN extract(day FROM price_date) = 24 THEN price END,
                d25 = CASE WHEN extract(day FROM price_date) = 25 THEN price END,
                d26 = CASE WHEN extract(day FROM price_date) = 26 THEN price END,
                d27 = CASE WHEN extract(day FROM price_date) = 27 THEN price END,
                d28 = CASE WHEN extract(day FROM price_date) = 28 THEN price END,
                d29 = CASE WHEN extract(day FROM price_date) = 29 THEN price END,
                d30 = CASE WHEN extract(day FROM price_date) = 30 THEN price END,
                d31 = CASE WHEN extract(day FROM price_date) = 31 THEN price END
        SQL);
    }

    public function down(): void
    {
        Schema::table('fund_prices', function (Blueprint $table) {
            for ($d = 1; $d <= 31; $d++) {
                $table->dropColumn("d{$d}");
            }
        });
    }
};
