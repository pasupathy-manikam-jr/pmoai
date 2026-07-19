<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every snapshot capture now appends a fresh price row (history grows
     * per ingest), so (code, price_date) can no longer be unique. The
     * chart dedupes to the latest row per date for a clean line.
     */
    public function up(): void
    {
        Schema::table('fund_prices', function (Blueprint $table) {
            $table->dropUnique(['code', 'price_date']);
            $table->index(['code', 'price_date']);
        });
    }

    public function down(): void
    {
        Schema::table('fund_prices', function (Blueprint $table) {
            $table->dropIndex(['code', 'price_date']);
            $table->unique(['code', 'price_date']);
        });
    }
};
