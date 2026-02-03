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
        Schema::create('ai_configurations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('primary_provider_id');
            $table->unsignedBigInteger('fallback_provider_id')->nullable();
            $table->string('primary_model')->nullable(); // gpt-4, gemini-pro
            $table->text('system_prompt')->nullable();
            $table->text('personality_tone')->nullable();
            $table->json('prohibited_topics')->nullable();
            $table->json('custom_instructions')->nullable();
            $table->integer('confidence_threshold')->default(50); // 0-100
            $table->boolean('auto_respond')->default(true);
            $table->integer('max_tokens')->default(1000);
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->timestamps();

            $table->index('company_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_configurations');
    }
};
