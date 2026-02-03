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
        Schema::create('workflow_execution_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('workflow_execution_id')->constrained()->onDelete('cascade');
            $table->foreignId('workflow_step_id')->nullable()->constrained('workflow_steps')->onDelete('set null');
            $table->string('step_type', 100)->nullable();
            $table->enum('status', ['started', 'completed', 'failed', 'skipped'])->default('started');
            $table->json('input_data')->nullable();
            $table->json('output_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'workflow_execution_id']);
            $table->index('workflow_execution_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_execution_logs');
    }
};
