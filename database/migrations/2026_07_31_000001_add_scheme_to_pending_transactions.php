<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Float statements come in two separate PDFs — unit trust ("ut") and PRS
     * ("prs"). Each ingest is a full snapshot of its own scheme, so ingesting
     * one must only replace pending rows of the same scheme, never the other's.
     */
    public function up(): void
    {
        Schema::table('pending_transactions', function (Blueprint $table) {
            $table->string('scheme', 8)->nullable()->after('id'); // ut | prs
        });
    }

    public function down(): void
    {
        Schema::table('pending_transactions', function (Blueprint $table) {
            $table->dropColumn('scheme');
        });
    }
};
