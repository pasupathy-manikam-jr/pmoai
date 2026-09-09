<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Advisor memory: the last board of verdicts, so the next visit can say what CHANGED. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advisor_states', function (Blueprint $table) {
            $table->id();
            $table->jsonb('board');      // code => {action, switch_to, weight}
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('advisor_states');
    }
};
