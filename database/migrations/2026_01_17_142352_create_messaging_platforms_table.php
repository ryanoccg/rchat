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
        Schema::create('messaging_platforms', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // facebook, whatsapp, telegram, line
            $table->string('slug')->unique();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('config_fields')->nullable(); // Required API fields
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
        Schema::dropIfExists('messaging_platforms');
    }
};
