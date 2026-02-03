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
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name'); // e.g., "New Customer Agent", "Returning Customer Agent", "Follow-up Agent"
            $table->string('slug')->unique(); // URL-friendly identifier
            $table->string('agent_type'); // new_customer, returning_customer, follow_up, general, custom
            $table->text('description')->nullable();
            $table->unsignedBigInteger('ai_provider_id');
            $table->string('model')->default('gpt-5-mini');
            $table->text('system_prompt')->nullable();
            $table->text('personality_tone')->nullable();
            $table->json('prohibited_topics')->nullable();
            $table->json('custom_instructions')->nullable();
            $table->integer('max_tokens')->default(500);
            $table->decimal('temperature', 3, 2)->default(0.70);
            $table->integer('confidence_threshold')->default(50); // 0-100
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0); // Higher priority agents are checked first

            // Trigger conditions for when this agent should be used
            $table->json('trigger_conditions')->nullable();

            // Condition fields (stored as JSON for flexibility)
            // {
            //   "customer_type": "new|returning|vip|inactive",
            //   "conversation_age_hours": 24,
            //   "last_interaction_days": 7,
            //   "message_count": 0,
            //   "tags": ["follow-up", "urgent"],
            //   "time_since_last_message_hours": 48,
            //   "requires_follow_up": true
            // }

            $table->timestamps();
            $table->softDeletes();

            $table->index('company_id');
            $table->index('agent_type');
            $table->index('is_active');
            $table->index('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_agents');
    }
};
