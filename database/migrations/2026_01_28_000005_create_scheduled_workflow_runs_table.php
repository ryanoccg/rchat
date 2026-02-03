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
        Schema::create('scheduled_workflow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('workflow_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('conversation_id')->nullable()->constrained()->onDelete('set null');
            $table->timestamp('scheduled_at');
            $table->json('execution_context')->nullable();
            $table->enum('status', ['pending', 'running', 'completed', 'failed', 'cancelled'])->default('pending');
            $table->timestamp('executed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'status', 'scheduled_at']);
            $table->index('workflow_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_workflow_runs');
    }
};
