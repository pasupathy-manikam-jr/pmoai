<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('page_captures', function (Blueprint $table) {
            $table->jsonb('links')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('page_captures', function (Blueprint $table) {
            $table->dropColumn('links');
        });
    }
};
