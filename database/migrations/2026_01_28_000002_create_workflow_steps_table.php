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
        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->foreignId('workflow_id')->constrained()->onDelete('cascade');
            $table->enum('step_type', [
                'trigger',
                'action',
                'condition',
                'delay',
                'parallel',
                'loop',
                'ai_response',
                'merge'
            ]);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('position')->nullable(); // x, y coordinates for visual builder
            $table->json('config')->nullable(); // step-specific configuration
            $table->json('next_steps')->nullable(); // connections to other steps
            $table->timestamps();

            $table->index(['company_id', 'workflow_id']);
            $table->index('step_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
    }
};
