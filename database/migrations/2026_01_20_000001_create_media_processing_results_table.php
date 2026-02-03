<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media_processing_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('media_type'); // image, audio
            $table->string('processor'); // openai_vision, whisper, claude_vision, gemini_vision
            $table->text('text_content')->nullable(); // transcription or description
            $table->json('analysis_data')->nullable(); // additional analysis metadata
            $table->string('status')->default('pending'); // pending, processing, completed, failed
            $table->text('error_message')->nullable();
            $table->integer('processing_time_ms')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['message_id', 'media_type']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media_processing_results');
    }
};
