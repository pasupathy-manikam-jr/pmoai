<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Public Mutual detail URLs (ASP.NET, query-string heavy) blow past
        // varchar(255). Widen to text — it is not indexed.
        Schema::table('fund_details', function (Blueprint $table) {
            $table->text('source_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fund_details', function (Blueprint $table) {
            $table->string('source_url')->nullable()->change();
        });
    }
};
