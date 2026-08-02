<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Latest quote per market symbol (index / FX / commodity), refreshed by
     * pmoai:fetch-quotes. One row per symbol (upsert) — the dashboard reads
     * these for a live number instead of a delayed embedded widget.
     */
    public function up(): void
    {
        Schema::create('market_quotes', function (Blueprint $table) {
            $table->id();
            $table->string('symbol')->unique();     // Yahoo symbol, e.g. ^JKSE
            $table->string('label')->nullable();
            $table->decimal('price', 16, 4)->nullable();
            $table->decimal('prev_close', 16, 4)->nullable();
            $table->decimal('change_pct', 8, 2)->nullable();
            $table->string('currency', 8)->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_quotes');
    }
};
