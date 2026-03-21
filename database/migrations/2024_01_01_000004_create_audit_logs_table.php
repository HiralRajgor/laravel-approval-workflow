<?php
// database/migrations/2024_01_01_000004_create_audit_logs_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            // Polymorphic — can log events for any model, not just documents.
            $table->morphs('auditable');

            $table->foreignId('user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // Short machine-readable verb: 'created', 'updated', 'status_changed', 'deleted'
            $table->string('event', 64)->index();

            // Arbitrary context: old_status, new_status, changed_fields, etc.
            $table->json('meta')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
