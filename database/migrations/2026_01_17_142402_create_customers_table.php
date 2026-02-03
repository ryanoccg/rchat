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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('platform_user_id'); // Platform-specific user ID
            $table->foreignId('messaging_platform_id')->constrained();
            $table->json('profile_data')->nullable(); // Additional platform data
            $table->string('language')->default('en');
            $table->json('metadata')->nullable(); // Custom fields
            $table->timestamps();

            $table->index(['company_id', 'platform_user_id']);
            $table->index('email');
            $table->index('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
