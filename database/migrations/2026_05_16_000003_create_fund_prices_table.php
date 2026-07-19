<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Slim, permanent price history. One row per fund per price date.
        // This is the data that turns daily captures into a real 52-week
        // high / peak-distance signal. Bulk (raw_text, old recs, CSVs) is
        // pruned separately; this stays.
        Schema::create('fund_prices', function (Blueprint $table) {
            $table->id();
            $table->string('code')->index();
            $table->string('name');
            $table->decimal('price', 12, 4);
            $table->date('price_date');
            $table->timestamp('created_at')->nullable();

            $table->unique(['code', 'price_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_prices');
    }
};
