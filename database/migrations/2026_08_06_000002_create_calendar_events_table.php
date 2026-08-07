<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dates that move YOUR funds — you supply the real published schedule (BNM MPC,
 * Fed FOMC, PMO distribution/ex-dates). Nothing invented: the app only shows
 * what you've entered, filtered to what's relevant to your actual exposure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('calendar_events', function (Blueprint $table) {
            $table->id();
            $table->date('event_date');
            $table->string('kind', 12);          // bnm | fed | pmo | other
            $table->string('label');
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['event_date', 'kind', 'label']);   // idempotent re-ingest
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }
};
