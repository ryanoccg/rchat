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
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');

            // Basic file info
            $table->string('file_name');
            $table->string('file_type');
            $table->string('mime_type');
            $table->string('extension', 10);
            $table->unsignedBigInteger('file_size'); // in bytes

            // Storage info
            $table->string('disk', 20)->default('public');
            $table->string('path');
            $table->string('url');
            $table->text('thumbnail_url')->nullable();

            // Media categorization
            $table->string('media_type')->default('file'); // image, video, audio, document, file
            $table->string('collection')->nullable(); // products, messages, attachments, etc.
            $table->string('folder_path')->nullable(); // for organizing in subfolders

            // Metadata
            $table->json('metadata')->nullable(); // width, height, duration, etc.
            $table->json('custom_properties')->nullable(); // custom fields
            $table->json('conversions')->nullable(); // generated thumbnails, optimized versions

            // Relationships
            $table->nullableMorphs('mediable'); // Polymorphic: can belong to any model
            $table->unsignedInteger('mediable_order')->default(0);

            // Alt text and description (like WordPress)
            $table->string('alt')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->text('caption')->nullable();

            // AI processing results
            $table->text('ai_analysis')->nullable(); // Image description, transcription, etc.
            $table->json('ai_tags')->nullable(); // Auto-generated tags
            $table->json('ai_embeddings')->nullable(); // For semantic search

            // Usage tracking
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('last_used_at')->nullable();

            // Upload info
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('source')->default('direct'); // direct, platform, import, ai_generated
            $table->string('source_url')->nullable();

            // Soft deletes
            $table->softDeletes();

            $table->index(['company_id', 'media_type']);
            $table->index(['company_id', 'collection']);
            $table->index(['company_id', 'mediable_type', 'mediable_id']);
            $table->index(['company_id', 'folder_path']);
            $table->index('file_name');
            $table->index('mime_type');

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
