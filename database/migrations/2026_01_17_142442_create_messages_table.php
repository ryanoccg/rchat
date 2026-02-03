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
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete(); // NULL for customer
            $table->string('sender_type'); // customer, agent, ai
            $table->string('message_type')->default('text'); // text, audio, image, video, file
            $table->text('content')->nullable();
            $table->json('media_urls')->nullable(); // For images, audio, video
            $table->json('metadata')->nullable(); // Platform-specific data
            $table->string('platform_message_id')->nullable(); // Original platform ID
            $table->boolean('is_from_customer')->default(false);
            $table->unsignedBigInteger('ai_provider_id')->nullable();
            $table->integer('ai_confidence')->nullable();
            $table->json('ai_response_data')->nullable(); // AI processing details
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index('sender_type');
            $table->index('message_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
