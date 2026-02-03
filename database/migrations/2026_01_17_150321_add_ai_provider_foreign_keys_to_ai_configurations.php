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
        Schema::table('ai_configurations', function (Blueprint $table) {
            $table->foreign('primary_provider_id')
                ->references('id')
                ->on('ai_providers');
            $table->foreign('fallback_provider_id')
                ->references('id')
                ->on('ai_providers')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_configurations', function (Blueprint $table) {
            $table->dropForeign(['primary_provider_id']);
            $table->dropForeign(['fallback_provider_id']);
        });
    }
};
