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
        Schema::create('sentiment_analysis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('message_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('sentiment'); // positive, negative, neutral, frustrated
            $table->decimal('score', 5, 2); // -1.0 to 1.0 or 0-100
            $table->json('emotions')->nullable(); // anger, joy, sadness, etc.
            $table->text('reasoning')->nullable();
            $table->timestamps();

            $table->index('conversation_id');
            $table->index('message_id');
            $table->index('sentiment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sentiment_analysis');
    }
};
