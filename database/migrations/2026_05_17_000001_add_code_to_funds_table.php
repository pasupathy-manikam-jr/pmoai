<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Promote the Public Mutual fund code to a first-class column so a
        // fund's identity (and its fund_prices history) no longer depends on
        // digging through the `extra` JSON blob.
        Schema::table('funds', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name')->index();
        });

        // Backfill from the JSON we already captured.
        DB::statement("UPDATE funds SET code = extra->>'code' WHERE extra->>'code' IS NOT NULL");
    }

    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
