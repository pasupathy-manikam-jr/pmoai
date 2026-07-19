<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_captures', function (Blueprint $table) {
            $table->id();
            $table->string('url', 1000);
            $table->string('title')->nullable();
            $table->string('hash', 40)->unique();   // sha1(url + text) — dedupe
            $table->text('text');                   // body innerText
            $table->jsonb('tables')->nullable();    // extracted tables as TSV strings
            $table->timestamp('captured_at');
            $table->timestamps();
            $table->index('url');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_captures');
    }
};
