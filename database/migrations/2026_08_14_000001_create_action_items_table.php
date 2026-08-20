<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Your pending-action checklist on the Today card. Tick to mark done (it hides).
 * Seeded with the current advisor to-dos; you can tick/untick as you act.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_items', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('done')->default(false);
            $table->timestamp('done_at')->nullable();
            $table->timestamps();
        });

        $items = [
            'Switch Indonesia Select → Asia Ittikal',
            'Switch e-Islamic India Global → e-Islamic Asia Thematic',
            'Deploy a slice of idle e-Cash → e-Sukuk',
            'Trim e-AI Tech to 25%',
            'Start a bond fund (you hold none)',
        ];
        foreach ($items as $i => $label) {
            \App\Models\ActionItem::create(['label' => $label, 'sort' => $i]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('action_items');
    }
};
