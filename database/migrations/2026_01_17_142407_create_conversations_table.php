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
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('platform_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // Human agent
            $table->string('status')->default('open'); // open, in_progress, closed, escalated
            $table->string('priority')->default('normal'); // low, normal, high, urgent
            $table->boolean('is_ai_handling')->default(true);
            $table->integer('ai_confidence_score')->nullable(); // 0-100
            $table->text('ai_context')->nullable(); // Context for AI
            $table->timestamp('last_message_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index('assigned_to');
            $table->index('last_message_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
