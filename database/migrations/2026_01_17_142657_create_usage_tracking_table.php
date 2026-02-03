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
        Schema::create('usage_tracking', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->date('period_date'); // Daily tracking
            $table->integer('message_count')->default(0);
            $table->integer('ai_requests')->default(0);
            $table->integer('storage_used')->default(0); // bytes
            $table->decimal('api_cost', 10, 4)->default(0);
            $table->json('breakdown')->nullable(); // Per provider/platform
            $table->timestamps();

            $table->index(['company_id', 'period_date']);
            $table->unique(['company_id', 'period_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_tracking');
    }
};
