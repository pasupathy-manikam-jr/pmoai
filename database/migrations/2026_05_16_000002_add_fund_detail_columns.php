<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            // Performance tab
            $table->decimal('return_ytd', 8, 2)->nullable()->after('return_5y');
            $table->decimal('return_10y', 8, 2)->nullable()->after('return_5y');
            $table->decimal('perf_factor', 10, 2)->nullable();
            $table->string('perf_class')->nullable();        // e.g. "V. HIGH"
            $table->date('perf_date')->nullable();

            // Info tab
            $table->string('category')->nullable();
            $table->string('risk')->nullable();
            $table->string('since_inception')->nullable();   // e.g. "14 Yrs"
            $table->string('fund_size')->nullable();          // e.g. "3,748 mil"
        });
    }

    public function down(): void
    {
        Schema::table('funds', function (Blueprint $table) {
            $table->dropColumn([
                'return_ytd', 'return_10y', 'perf_factor', 'perf_class',
                'perf_date', 'category', 'risk', 'since_inception', 'fund_size',
            ]);
        });
    }
};
