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
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->onDelete('cascade');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'inactive', 'draft'])->default('draft');
            $table->enum('trigger_type', [
                'customer_created',
                'customer_returning',
                'first_message',
                'conversation_created',
                'conversation_closed',
                'message_received',
                'no_response',
                'scheduled'
            ]);
            $table->json('trigger_config')->nullable();
            $table->json('workflow_definition')->nullable();
            $table->enum('execution_mode', ['sequential', 'parallel', 'mixed'])->default('sequential');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index('trigger_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflows');
    }
};
