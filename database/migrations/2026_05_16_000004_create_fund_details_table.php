<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-fund deep detail captured from the Public Mutual fund detail
        // page. Reference only — NOT fed to the recommendation LLM pipeline
        // (that path is snapshots -> ExtractFundsJob). One row per fund,
        // overwritten on re-capture (latest wins). `payload` = best-effort
        // parsed fields; `raw_text` = full page text fallback.
        Schema::create('fund_details', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->index();
            $table->string('name');
            $table->json('payload')->nullable();
            $table->longText('raw_text');
            $table->string('source_url')->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->unique('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_details');
    }
};
