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
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Creator
            $table->foreignId('platform_connection_id')->nullable()->constrained()->nullOnDelete(); // Which platform to send through
            $table->string('name'); // Campaign name
            $table->text('message'); // Message content
            $table->string('message_type')->default('text'); // text, image
            $table->json('media_urls')->nullable(); // Array of image URLs for image broadcasts
            $table->enum('status', ['draft', 'scheduled', 'sending', 'completed', 'failed', 'cancelled'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('total_recipients')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->json('filters')->nullable(); // Customer segmentation filters
            $table->text('notes')->nullable(); // Internal notes
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index('scheduled_at');
            $table->index('created_at');
        });

        Schema::create('broadcast_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('broadcast_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'sending', 'sent', 'failed', 'delivered'])->default('pending');
            $table->string('platform_message_id')->nullable(); // Platform-specific message ID for tracking
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['broadcast_id', 'status']);
            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('broadcast_recipients');
        Schema::dropIfExists('broadcasts');
    }
};
