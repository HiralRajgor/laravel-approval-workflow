<?php

// database/migrations/2024_01_01_000002_create_documents_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->longText('body')->nullable();

            // Stored as VARCHAR matching the DocumentStatus enum values.
            // Using a string column (not ENUM in DB) so adding new states
            // is a code-only change — no ALTER TABLE needed in production.
            $table->string('status')->default('draft')->index();

            $table->foreignId('author_id')
                ->constrained('users')
                ->cascadeOnDelete();

            // Tracks which step of a configurable workflow the document is on.
            $table->unsignedTinyInteger('current_step')->default(0);

            // Arbitrary JSON payload — department, category, priority, etc.
            // Keeps the core table lean; consumers add their own fields here.
            $table->json('workflow_config')->nullable();
            $table->json('metadata')->nullable();

            $table->softDeletes();
            $table->timestamps();

            $table->index(['status', 'author_id']); // frequent compound query
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
