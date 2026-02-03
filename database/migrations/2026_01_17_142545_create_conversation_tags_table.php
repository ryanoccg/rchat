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
        Schema::create('conversation_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->string('tag'); // product_inquiry, complaint, technical_support, billing
            $table->string('category')->nullable();
            $table->decimal('confidence', 5, 2)->nullable(); // AI confidence in tag
            $table->boolean('is_ai_generated')->default(true);
            $table->timestamps();

            $table->index(['conversation_id', 'tag']);
            $table->index('tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('conversation_tags');
    }
};
