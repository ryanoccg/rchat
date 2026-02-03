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
        Schema::create('platform_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('messaging_platform_id')->constrained()->cascadeOnDelete();
            $table->string('platform_account_id')->nullable(); // Platform-specific ID
            $table->string('platform_account_name')->nullable();
            $table->json('credentials')->nullable(); // Encrypted API keys/tokens
            $table->json('webhook_config')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('connected_at');
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamps();

            $table->index(['company_id', 'messaging_platform_id']);
            $table->index('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_connections');
    }
};
