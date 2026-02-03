<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('chunk_text'); // The text that was embedded (name + description + specs)
            $table->json('embedding'); // Vector embedding (stored as JSON array)
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->integer('chunk_index')->default(0);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_embeddings');
    }
};
