<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store the currency mix at each daily capture so exposure-over-time can chart
 * how the book's currency split drifts as funds are switched. jsonb: {ccy: pct}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portfolio_snapshots', function (Blueprint $table) {
            $table->jsonb('exposure')->nullable();   // {USD: 40.6, MYR: 28.5, ...}
        });
    }

    public function down(): void
    {
        Schema::table('portfolio_snapshots', function (Blueprint $table) {
            $table->dropColumn('exposure');
        });
    }
};
