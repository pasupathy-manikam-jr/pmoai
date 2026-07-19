<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turn `funds` from a per-snapshot dump into a catalog: ONE row per
     * Public Mutual code. Every snapshot now upserts the same 181 rows
     * (latest metadata + price/returns win); the permanent unit-price
     * time-series already lives in `fund_prices`, so nothing is lost.
     *
     * Irreversible: collapses duplicate rows (keeps newest id per code).
     * Old per-snapshot fund rows were already discarded by pmoai:prune.
     */
    public function up(): void
    {
        // Collapse dups: keep the newest row per code (all codes are non-null).
        DB::statement('DELETE FROM funds WHERE id NOT IN (SELECT MAX(id) FROM funds GROUP BY code)');

        // Drop the now-meaningless snapshot link (FK + column).
        Schema::table('funds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('snapshot_id');
        });

        // Code is the catalog identity.
        Schema::table('funds', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropUnique(['code']);
            // Best-effort: re-add the column nullable. Original per-snapshot
            // associations are not recoverable.
            $table->foreignId('snapshot_id')->nullable()->after('id')
                ->constrained()->cascadeOnDelete();
        });
    }
};
