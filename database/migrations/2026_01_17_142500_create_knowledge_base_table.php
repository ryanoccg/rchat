<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('knowledge_base', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('file_name')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_type'); // pdf, txt, docx, csv, image
            $table->integer('file_size')->nullable(); // bytes
            $table->text('content')->nullable(); // Extracted text
            $table->string('category')->nullable();
            $table->integer('priority')->default(0); // Higher = more important
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'is_active']);
            $table->index('category');
            $table->index('priority');
        });

        // Create embeddings table for knowledge base chunks (vector search disabled for now)
        Schema::create('knowledge_base_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_base')->cascadeOnDelete();
            $table->text('chunk_text'); // Text chunk for embedding
            $table->integer('chunk_index'); // Order in document
            $table->text('embedding_data')->nullable(); // JSON storage for embeddings (temporary)
            $table->timestamps();

            $table->index('knowledge_base_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('knowledge_base_embeddings');
        Schema::dropIfExists('knowledge_base');
    }
};
