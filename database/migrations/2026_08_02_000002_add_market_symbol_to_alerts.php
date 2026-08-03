<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An alert can target a market index/FX/commodity (market_quotes.symbol)
     * instead of a fund. When market_symbol is set, AlertCheck evaluates the
     * condition against the latest quote for that symbol; otherwise it uses the
     * fund's NAV as before. fund_code becomes nullable for index alerts.
     */
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('market_symbol')->nullable()->after('fund_code');
            $table->string('fund_code')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropColumn('market_symbol');
        });
    }
};
