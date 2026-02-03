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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // viewed, clicked, downloaded, etc.
            $table->string('description');
            $table->string('subject_type')->nullable(); // Related model
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('properties')->nullable(); // Additional data
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['company_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
