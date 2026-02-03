<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2.1: KB Scoping Per Personality
 *
 * Adds ability for AI personalities to have:
 * - Scoped knowledge bases (only search specific KBs)
 * - Product search toggle
 * - Custom RAG chunk count
 */
return new class extends Migration
{
    public function up(): void
    {
        // Pivot table for AI Agent <-> Knowledge Base relationship
        Schema::create('ai_agent_knowledge_base', function (Blueprint $table) {
            $table->foreignId('ai_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('knowledge_base_id')->constrained('knowledge_base')->cascadeOnDelete();
            $table->primary(['ai_agent_id', 'knowledge_base_id']);
        });

        // Add personality-level settings to ai_agents table
        Schema::table('ai_agents', function (Blueprint $table) {
            $table->boolean('enable_product_search')->default(true)->after('is_personality_only');
            $table->unsignedTinyInteger('rag_top_k')->default(3)->after('enable_product_search');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_knowledge_base');

        Schema::table('ai_agents', function (Blueprint $table) {
            $table->dropColumn(['enable_product_search', 'rag_top_k']);
        });
    }
};
