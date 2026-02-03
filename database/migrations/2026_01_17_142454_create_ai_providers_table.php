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
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // openai, gemini, claude
            $table->string('slug')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->json('available_models')->nullable(); // gpt-4, gpt-3.5-turbo, etc.
            $table->json('capabilities')->nullable(); // text, image, audio
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('slug');
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_providers');
    }
};
