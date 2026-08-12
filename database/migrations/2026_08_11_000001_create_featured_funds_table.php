<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Top performer" cards captured from the Public Mutual dashboard (the funds
 * PMO promotes after login, with their 3-year annualised return). Replace-all
 * on each capture — it's a snapshot of what PMO is highlighting right now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('featured_funds', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 20)->nullable();
            $table->string('metric')->default('3-Year Annualised Return');
            $table->decimal('value', 8, 2)->nullable();   // the % figure
            $table->string('as_at')->nullable();           // "11 Aug 2026" as shown
            $table->unsignedInteger('rank')->default(0);   // order on the PMO page
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('featured_funds');
    }
};
