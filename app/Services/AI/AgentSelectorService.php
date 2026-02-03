<?php

namespace App\Services\AI;

use App\Models\AiAgent;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\AiConfiguration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * @deprecated Phase 1.4: This service is deprecated and will be removed in a future version.
 *
 * Agent/personality selection is now handled through workflows:
 * - Workflows with `message_received` trigger use `send_ai_response` action with explicit `ai_agent_id`
 * - Companies without workflows use AiConfiguration defaults directly
 *
 * This class is kept for backward compatibility during the transition period.
 * New implementations should use workflows for personality routing instead.
 *
 * @see App\Services\Workflow\WorkflowTriggerService::onMessageReceived()
 * @see App\Services\AI\AiService::setAgentById()
 */
class AgentSelectorService
{
    /**
     * Select the appropriate AI agent for a given conversation
     *
     * @param int $companyId
     * @param Conversation $conversation
     * @return AiAgent|array|null Returns AiAgent model or legacy config array
     */
    public function selectAgent(int $companyId, Conversation $conversation): AiAgent|array|null
    {
        // Build context from conversation and customer
        $context = $this->buildContext($conversation);

        Log::info('AgentSelectorService: Selecting agent', [
            'company_id' => $companyId,
            'conversation_id' => $conversation->id,
            'context' => $context,
        ]);

        // Get all active agents for the company, ordered by priority
        $agents = Cache::remember("ai_agents_{$companyId}", 300, function () use ($companyId) {
            return AiAgent::where('company_id', $companyId)
                ->active()
                ->orderByPriority()
                ->get();
        });

        // Find the first matching agent
        foreach ($agents as $agent) {
            if ($agent->matchesContext($context)) {
                Log::info('AgentSelectorService: Agent selected', [
                    'agent_id' => $agent->id,
                    'agent_name' => $agent->name,
                    'agent_type' => $agent->agent_type,
                ]);
                return $agent;
            }
        }

        // Fallback to legacy AI configuration if no agent matches
        Log::info('AgentSelectorService: No matching agent, using legacy configuration');
        return $this->getLegacyConfiguration($companyId);
    }

    /**
     * Build context array for agent matching
     */
    protected function buildContext(Conversation $conversation): array
    {
        $customer = $conversation->customer;
        $context = [
            'customer_type' => 'general',
            'message_count' => 0,
            'requires_follow_up' => false,
            'conversation_age_hours' => 0,
            'last_interaction_days' => 0,
            'time_since_last_message_hours' => 0,
            'tags' => [],
        ];

        // Get message count for this conversation
        $context['message_count'] = $conversation->messages()->count();

        // Get customer's total message count across all conversations
        // Use direct query aggregation to avoid N+1 queries
        $totalMessages = \App\Models\Message::whereIn(
            'conversation_id',
            $customer->conversations()->pluck('id')
        )->where('is_from_customer', true)->count();

        // Determine customer type based on interaction history
        if ($totalMessages <= 2) {
            $context['customer_type'] = 'new';
        } elseif ($totalMessages <= 10) {
            $context['customer_type'] = 'returning';
        } else {
            $context['customer_type'] = 'vip';
        }

        // Check conversation age
        if ($conversation->created_at) {
            $context['conversation_age_hours'] = $conversation->created_at->diffInHours(now());
        }

        // Check time since last message
        $lastMessage = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastMessage && $lastMessage->created_at) {
            $context['time_since_last_message_hours'] = $lastMessage->created_at->diffInHours(now());
            $context['last_interaction_days'] = $lastMessage->created_at->diffInDays(now());
        }

        // Check for follow-up requirement in metadata
        if ($conversation->metadata) {
            $metadata = is_array($conversation->metadata) ? $conversation->metadata : json_decode($conversation->metadata, true);
            $context['requires_follow_up'] = $metadata['requires_follow_up'] ?? false;
        }

        // Get conversation tags
        $context['tags'] = $conversation->tags()
            ->pluck('tag')
            ->toArray();

        // Check customer metadata for additional info
        if ($customer->metadata) {
            $customerMetadata = is_array($customer->metadata) ? $customer->metadata : json_decode($customer->metadata, true);
            $context['customer_type'] = $customerMetadata['customer_type'] ?? $context['customer_type'];
            $context['requires_follow_up'] = $customerMetadata['requires_follow_up'] ?? $context['requires_follow_up'];
        }

        // Auto-detect follow-up scenario: customer who hasn't replied in 48+ hours
        if ($context['time_since_last_message_hours'] >= 48 && $context['message_count'] > 2) {
            $context['requires_follow_up'] = true;
        }

        return $context;
    }

    /**
     * Get legacy AI configuration as fallback
     */
    protected function getLegacyConfiguration(int $companyId): ?array
    {
        $config = AiConfiguration::where('company_id', $companyId)->first();

        if (!$config) {
            return null;
        }

        return [
            'id' => 'legacy',
            'name' => 'Default AI',
            'agent_type' => 'general',
            'primary_provider_id' => $config->primary_provider_id,
            'primary_model' => $config->primary_model,
            'system_prompt' => $config->system_prompt,
            'personality_tone' => $config->personality_tone,
            'prohibited_topics' => $config->prohibited_topics ?? [],
            'custom_instructions' => $config->custom_instructions ?? [],
            'max_tokens' => $config->max_tokens,
            'temperature' => (float) $config->temperature,
            'confidence_threshold' => $config->confidence_threshold / 100,
        ];
    }

    /**
     * Get default agents for a new company
     */
    public static function getDefaultAgentsForCompany(int $companyId, int $aiProviderId): array
    {
        return [
            [
                'company_id' => $companyId,
                'name' => 'New Customer Agent',
                'slug' => 'new-customer-agent',
                'agent_type' => 'new_customer',
                'description' => 'Handles first-time customers with warm welcome and introduction to company offerings',
                'ai_provider_id' => $aiProviderId,
                'model' => 'gpt-5-mini',
                'system_prompt' => 'You are welcoming a new customer for the first time. Be extra warm and friendly. Introduce your company briefly and ask how you can help them today. Keep it concise - just 2-3 sentences max.',
                'personality_tone' => 'friendly',
                'max_tokens' => 300,
                'temperature' => 0.70,
                'confidence_threshold' => 50,
                'is_active' => true,
                'priority' => 100,
                'trigger_conditions' => [
                    'customer_type' => 'new',
                    'max_message_count' => 2,
                ],
            ],
            [
                'company_id' => $companyId,
                'name' => 'Returning Customer Agent',
                'slug' => 'returning-customer-agent',
                'agent_type' => 'returning_customer',
                'description' => 'Handles returning customers with personalized service based on interaction history',
                'ai_provider_id' => $aiProviderId,
                'model' => 'gpt-5-mini',
                'system_prompt' => 'You are helping a returning customer. Acknowledge them warmly and reference previous interactions if appropriate. Provide personalized assistance based on their history.',
                'personality_tone' => 'professional',
                'max_tokens' => 500,
                'temperature' => 0.50,
                'confidence_threshold' => 50,
                'is_active' => true,
                'priority' => 90,
                'trigger_conditions' => [
                    'customer_type' => 'returning',
                    'min_message_count' => 3,
                ],
            ],
            [
                'company_id' => $companyId,
                'name' => 'Follow-up Agent',
                'slug' => 'follow-up-agent',
                'agent_type' => 'follow_up',
                'description' => 'Re-engages customers who haven\'t interacted in a while with friendly check-ins',
                'ai_provider_id' => $aiProviderId,
                'model' => 'gpt-5-mini',
                'system_prompt' => 'You are following up with a customer who hasn\'t been in touch for a while. Reach out warmly and check if they need any help. Ask if there\'s anything new you can assist with. Keep it friendly and low-pressure.',
                'personality_tone' => 'friendly',
                'max_tokens' => 400,
                'temperature' => 0.60,
                'confidence_threshold' => 50,
                'is_active' => true,
                'priority' => 95,
                'trigger_conditions' => [
                    'requires_follow_up' => true,
                    'time_since_last_message_hours' => 48,
                ],
            ],
            [
                'company_id' => $companyId,
                'name' => 'VIP Customer Agent',
                'slug' => 'vip-customer-agent',
                'agent_type' => 'vip',
                'description' => 'Provides premium service for high-value customers with more personalized attention',
                'ai_provider_id' => $aiProviderId,
                'model' => 'gpt-5-mini',
                'system_prompt' => 'You are assisting a VIP customer. Provide exceptional, personalized service. Be proactive in anticipating their needs. Show appreciation for their continued business.',
                'personality_tone' => 'professional',
                'max_tokens' => 600,
                'temperature' => 0.50,
                'confidence_threshold' => 50,
                'is_active' => true,
                'priority' => 98,
                'trigger_conditions' => [
                    'customer_type' => 'vip',
                    'min_message_count' => 10,
                ],
            ],
            [
                'company_id' => $companyId,
                'name' => 'General Agent',
                'slug' => 'general-agent',
                'agent_type' => 'general',
                'description' => 'Default agent for all situations (fallback)',
                'ai_provider_id' => $aiProviderId,
                'model' => 'gpt-5-mini',
                'system_prompt' => '',
                'personality_tone' => 'professional',
                'max_tokens' => 500,
                'temperature' => 0.50,
                'confidence_threshold' => 50,
                'is_active' => true,
                'priority' => 1,
                'trigger_conditions' => null,
            ],
        ];
    }

    /**
     * Clear agent cache for a company
     */
    public function clearAgentCache(int $companyId): void
    {
        Cache::forget("ai_agents_{$companyId}");
    }
}
