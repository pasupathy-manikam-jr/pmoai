<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per day: total invested/value across all held funds,
        // written on every holdings capture. Feeds the equity curve.
        Schema::create('portfolio_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snap_date')->unique();
            $table->decimal('invested', 14, 2);
            $table->decimal('value', 14, 2);
            $table->timestamps();
        });

        // Price triggers. condition below|above vs the fund's latest price.
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('fund_code', 20);
            $table->string('condition', 8);          // below | above
            $table->decimal('level', 12, 4);
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->timestamp('fired_at')->nullable();
            $table->decimal('fired_price', 12, 4)->nullable();
            $table->timestamps();
            $table->index(['fund_code', 'active']);
        });

        // Whole-portfolio AI reviews (latest shown on the catalog page).
        Schema::create('portfolio_reviews', function (Blueprint $table) {
            $table->id();
            $table->string('status', 12)->default('running'); // running|done|failed
            $table->text('text')->nullable();
            $table->string('error', 500)->nullable();
            $table->string('provider', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_reviews');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('portfolio_snapshots');
    }
};
