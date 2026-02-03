<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration supports the AI Agent + Workflow merge:
     * - AI Agents become "AI Personalities" (just config: prompt, model, temp)
     * - Workflows become the sole trigger system
     * - is_personality_only: true = new simplified agent, false = legacy agent with triggers
     */
    public function up(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            // Add is_personality_only flag
            // true = new simplified personality (no trigger logic)
            // false = legacy agent with trigger_conditions (backward compat)
            $table->boolean('is_personality_only')->default(true)->after('is_active');
        });

        // Mark existing agents as legacy (not personality-only) so their triggers still work
        DB::table('ai_agents')->update(['is_personality_only' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ai_agents', function (Blueprint $table) {
            $table->dropColumn('is_personality_only');
        });
    }
};
