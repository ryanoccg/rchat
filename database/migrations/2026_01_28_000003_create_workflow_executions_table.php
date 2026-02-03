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
        Schema::create('workflow_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('workflow_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('conversation_id')->nullable()->constrained()->onDelete('set null');
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'paused', 'cancelled'])->default('pending');
            $table->foreignId('current_step_id')->nullable()->constrained('workflow_steps')->onDelete('set null');
            $table->json('execution_context')->nullable(); // runtime variables
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status']);
            $table->index(['workflow_id', 'status']);
            $table->index('customer_id');
            $table->index('conversation_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_executions');
    }
};
