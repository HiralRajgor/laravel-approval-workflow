<?php
// database/migrations/2024_01_01_000003_create_document_transitions_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This table is append-only — never updated, never (soft-)deleted.
        // It is the immutable ledger of every state change.
        Schema::create('document_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')
                  ->constrained('documents')
                  ->cascadeOnDelete();

            // Actor can be null for system-triggered transitions.
            $table->foreignId('actor_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->string('from_status');
            $table->string('to_status');
            $table->text('comment')->nullable();
            $table->string('ip_address', 45)->nullable(); // 45 = max IPv6 length
            $table->unsignedTinyInteger('step_index')->default(0);

            // No updated_at — this row must never be mutated.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_transitions');
    }
};
