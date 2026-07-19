<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');

        $dim = (int) config('ai.embed_dim', 1024);

        // One pasted Public Mutual price page = one snapshot.
        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('raw_text');               // pasted webpage blob
            $table->string('status')->default('pending'); // pending|extracted|recommended|failed
            $table->timestamps();
        });

        // Structured fund rows extracted from a snapshot by the LLM.
        Schema::create('funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('fund_type')->nullable();    // equity/balanced/sukuk/bond
            $table->boolean('shariah')->default(false);  // Public Mutual Islamic range
            $table->decimal('unit_price', 12, 4)->nullable();
            $table->decimal('selling_price', 12, 4)->nullable();
            $table->decimal('return_1y', 8, 2)->nullable();
            $table->decimal('return_3y', 8, 2)->nullable();
            $table->decimal('return_5y', 8, 2)->nullable();
            $table->string('currency', 3)->default('MYR');
            $table->json('extra')->nullable();
            $table->timestamps();
        });

        // User feedback / preferences. Embedded for cross-snapshot recall.
        Schema::create('user_feedback', function (Blueprint $table) use ($dim) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->text('text');
            $table->vector('embedding', $dim)->nullable();
            $table->timestamps();
        });

        // Optional cached market events (path A). Path B = Claude web search, no rows.
        Schema::create('market_events', function (Blueprint $table) use ($dim) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('headline');
            $table->text('body')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->vector('embedding', $dim)->nullable();
            $table->timestamps();
        });

        // LLM output: structured, auditable recommendation per fund.
        Schema::create('recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('fund_name');
            $table->string('action');                    // buy|hold|sell
            $table->decimal('target_weight', 5, 2)->nullable();
            $table->text('rationale');
            $table->string('model');
            $table->timestamps();
        });

        // Cosine similarity ANN indexes.
        DB::statement('CREATE INDEX user_feedback_embedding_hnsw ON user_feedback USING hnsw (embedding vector_cosine_ops)');
        DB::statement('CREATE INDEX market_events_embedding_hnsw ON market_events USING hnsw (embedding vector_cosine_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('recommendations');
        Schema::dropIfExists('market_events');
        Schema::dropIfExists('user_feedback');
        Schema::dropIfExists('funds');
        Schema::dropIfExists('snapshots');
    }
};
