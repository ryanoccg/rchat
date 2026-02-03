<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AiAgent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'slug',
        'agent_type',
        'description',
        'ai_provider_id',
        'model',
        'system_prompt',
        'personality_tone',
        'prohibited_topics',
        'custom_instructions',
        'max_tokens',
        'temperature',
        'confidence_threshold',
        'is_active',
        'is_personality_only',
        'enable_product_search',
        'rag_top_k',
        'priority',
        'trigger_conditions',
    ];

    protected function casts(): array
    {
        return [
            'prohibited_topics' => 'array',
            'custom_instructions' => 'array',
            'trigger_conditions' => 'array',
            'is_active' => 'boolean',
            'is_personality_only' => 'boolean',
            'enable_product_search' => 'boolean',
            'rag_top_k' => 'integer',
            'temperature' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function aiProvider()
    {
        return $this->belongsTo(AiProvider::class, 'ai_provider_id');
    }

    /**
     * Phase 2.1: Knowledge bases scoped to this personality
     * If no KB links exist, all company KBs are searched (backward compatible)
     */
    public function knowledgeBases()
    {
        return $this->belongsToMany(KnowledgeBase::class, 'ai_agent_knowledge_base');
    }

    /**
     * Get knowledge base IDs for RAG scoping
     * Returns null if no scoping (search all company KBs)
     */
    public function getKnowledgeBaseIdsForRag(): ?array
    {
        $ids = $this->knowledgeBases()->pluck('knowledge_base.id')->toArray();
        return empty($ids) ? null : $ids;
    }

    /**
     * Scope to get only active agents
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to get agents by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('agent_type', $type);
    }

    /**
     * Scope to order by priority (highest first)
     */
    public function scopeOrderByPriority($query)
    {
        return $query->orderBy('priority', 'desc');
    }

    /**
     * Check if this agent matches the given context
     */
    public function matchesContext(array $context): bool
    {
        if (!$this->is_active) {
            return false;
        }

        $conditions = $this->trigger_conditions ?? [];

        // If no conditions, this is a default/general agent
        if (empty($conditions)) {
            return true;
        }

        // Check customer type
        if (isset($conditions['customer_type'])) {
            $customerType = $context['customer_type'] ?? 'general';
            if ($conditions['customer_type'] !== $customerType) {
                return false;
            }
        }

        // Check minimum message count (for distinguishing new vs returning customers)
        if (isset($conditions['min_message_count'])) {
            $messageCount = $context['message_count'] ?? 0;
            if ($messageCount < $conditions['min_message_count']) {
                return false;
            }
        }

        // Check maximum message count (for new customers)
        if (isset($conditions['max_message_count'])) {
            $messageCount = $context['message_count'] ?? 0;
            if ($messageCount > $conditions['max_message_count']) {
                return false;
            }
        }

        // Check if follow-up is needed
        if (isset($conditions['requires_follow_up'])) {
            $requiresFollowUp = $context['requires_follow_up'] ?? false;
            if ($conditions['requires_follow_up'] !== $requiresFollowUp) {
                return false;
            }
        }

        // Check conversation age in hours
        if (isset($conditions['conversation_age_hours'])) {
            $conversationAge = $context['conversation_age_hours'] ?? 0;
            if ($conversationAge < $conditions['conversation_age_hours']) {
                return false;
            }
        }

        // Check time since last interaction (in days)
        if (isset($conditions['last_interaction_days'])) {
            $lastInteraction = $context['last_interaction_days'] ?? 0;
            if ($lastInteraction < $conditions['last_interaction_days']) {
                return false;
            }
        }

        // Check conversation tags
        if (isset($conditions['tags']) && !empty($conditions['tags'])) {
            $contextTags = $context['tags'] ?? [];
            $hasRequiredTag = false;
            foreach ($conditions['tags'] as $tag) {
                if (in_array($tag, $contextTags)) {
                    $hasRequiredTag = true;
                    break;
                }
            }
            if (!$hasRequiredTag) {
                return false;
            }
        }

        // Check time since last message (for follow-up scenarios)
        if (isset($conditions['time_since_last_message_hours'])) {
            $timeSinceLastMessage = $context['time_since_last_message_hours'] ?? 0;
            if ($timeSinceLastMessage < $conditions['time_since_last_message_hours']) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get agent configuration as array (compatible with AiService)
     */
    public function toConfigurationArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'agent_type' => $this->agent_type,
            'primary_provider_id' => $this->ai_provider_id,
            'primary_model' => $this->model,
            'system_prompt' => $this->system_prompt,
            'personality_tone' => $this->personality_tone,
            'prohibited_topics' => $this->prohibited_topics ?? [],
            'custom_instructions' => $this->custom_instructions ?? [],
            'max_tokens' => $this->max_tokens,
            'temperature' => (float) $this->temperature,
            'confidence_threshold' => ($this->confidence_threshold ?? 50) / 100, // Convert to 0-1 range
            // Phase 2.1: KB scoping per personality
            'enable_product_search' => $this->enable_product_search ?? true,
            'rag_top_k' => $this->rag_top_k ?? 3,
            'knowledge_base_ids' => $this->getKnowledgeBaseIdsForRag(),
        ];
    }
}
