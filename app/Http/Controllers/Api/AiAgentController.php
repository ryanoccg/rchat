<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiAgentResource;
use App\Models\AiAgent;
use App\Models\AiProvider;
use App\Services\AI\AgentSelectorService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\ActivityLogService;

class AiAgentController extends Controller
{
    protected AgentSelectorService $agentSelector;

    public function __construct(AgentSelectorService $agentSelector)
    {
        $this->agentSelector = $agentSelector;
    }

    /**
     * Get all AI agents for the company
     */
    public function index(Request $request)
    {
        $companyId = $request->company_id;

        $agents = AiAgent::where('company_id', $companyId)
            ->with('aiProvider')
            ->orderBy('priority', 'desc')
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => AiAgentResource::collection($agents),
        ]);
    }

    /**
     * Get a specific AI agent
     */
    public function show(Request $request, int $id)
    {
        $companyId = $request->company_id;

        $agent = AiAgent::where('company_id', $companyId)
            ->with('aiProvider')
            ->findOrFail($id);

        return response()->json([
            'data' => new AiAgentResource($agent),
        ]);
    }

    /**
     * Create a new AI agent
     */
    public function store(Request $request)
    {
        $companyId = $request->company_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'agent_type' => 'required|in:new_customer,returning_customer,follow_up,vip,general,custom',
            'description' => 'nullable|string|max:1000',
            'ai_provider_id' => 'nullable|exists:ai_providers,id',
            'model' => 'nullable|string|max:100',
            'system_prompt' => 'nullable|string|max:5000',
            'personality_tone' => 'nullable|string|max:255',
            'prohibited_topics' => 'nullable|array',
            'prohibited_topics.*' => 'string',
            'custom_instructions' => 'nullable|array',
            'custom_instructions.*' => 'string',
            'max_tokens' => 'nullable|integer|min:100|max:4096',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'confidence_threshold' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'boolean',
            'priority' => 'nullable|integer|min:0|max:999',
            'trigger_conditions' => 'nullable|array',
            'trigger_conditions.customer_type' => 'nullable|in:new,returning,vip,general',
            'trigger_conditions.min_message_count' => 'nullable|integer|min:0',
            'trigger_conditions.max_message_count' => 'nullable|integer|min:0',
            'trigger_conditions.requires_follow_up' => 'nullable|boolean',
            'trigger_conditions.conversation_age_hours' => 'nullable|integer|min:0',
            'trigger_conditions.last_interaction_days' => 'nullable|integer|min:0',
            'trigger_conditions.time_since_last_message_hours' => 'nullable|integer|min:0',
            'trigger_conditions.tags' => 'nullable|array',
            'trigger_conditions.tags.*' => 'string',
        ]);

        // Generate slug from name
        $baseSlug = Str::slug($validated['name']);
        $slug = $baseSlug;
        $counter = 1;

        while (AiAgent::where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . $counter++;
        }

        // Default ai_provider_id to OpenAI if not provided
        $aiProviderId = $validated['ai_provider_id']
            ?? \App\Models\AiProvider::where('slug', 'openai')->first()?->id
            ?? \App\Models\AiProvider::where('is_active', true)->first()?->id;

        $agent = AiAgent::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'slug' => $slug,
            'agent_type' => $validated['agent_type'],
            'description' => $validated['description'] ?? null,
            'ai_provider_id' => $aiProviderId,
            'model' => $validated['model'] ?? 'gpt-5-mini',
            'system_prompt' => $validated['system_prompt'] ?? null,
            'personality_tone' => $validated['personality_tone'] ?? null,
            'prohibited_topics' => $validated['prohibited_topics'] ?? [],
            'custom_instructions' => $validated['custom_instructions'] ?? [],
            'max_tokens' => $validated['max_tokens'] ?? 500,
            'temperature' => $validated['temperature'] ?? 0.70,
            'confidence_threshold' => $validated['confidence_threshold'] ?? 50,
            'is_active' => $validated['is_active'] ?? true,
            'priority' => $validated['priority'] ?? 0,
            'trigger_conditions' => $validated['trigger_conditions'] ?? null,
        ]);

        // Clear cache
        $this->agentSelector->clearAgentCache($companyId);

        ActivityLogService::log('ai_agent_created', "Created AI agent: {$agent->name}", $agent, [
            'agent_name' => $agent->name,
            'agent_id' => $agent->id,
            'agent_type' => $agent->agent_type,
        ]);

        $agent->load('aiProvider');

        return response()->json([
            'message' => 'AI agent created successfully',
            'data' => new AiAgentResource($agent),
        ], 201);
    }

    /**
     * Update an AI agent
     */
    public function update(Request $request, int $id)
    {
        $companyId = $request->company_id;

        $agent = AiAgent::where('company_id', $companyId)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'agent_type' => 'sometimes|in:new_customer,returning_customer,follow_up,vip,general,custom',
            'description' => 'nullable|string|max:1000',
            'ai_provider_id' => 'sometimes|exists:ai_providers,id',
            'model' => 'sometimes|string|max:100',
            'system_prompt' => 'nullable|string|max:5000',
            'personality_tone' => 'nullable|string|max:255',
            'prohibited_topics' => 'nullable|array',
            'prohibited_topics.*' => 'string',
            'custom_instructions' => 'nullable|array',
            'custom_instructions.*' => 'string',
            'max_tokens' => 'nullable|integer|min:100|max:4096',
            'temperature' => 'nullable|numeric|min:0|max:2',
            'confidence_threshold' => 'nullable|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'priority' => 'sometimes|integer|min:0|max:999',
            'trigger_conditions' => 'nullable|array',
            'trigger_conditions.customer_type' => 'nullable|in:new,returning,vip,general',
            'trigger_conditions.min_message_count' => 'nullable|integer|min:0',
            'trigger_conditions.max_message_count' => 'nullable|integer|min:0',
            'trigger_conditions.requires_follow_up' => 'nullable|boolean',
            'trigger_conditions.conversation_age_hours' => 'nullable|integer|min:0',
            'trigger_conditions.last_interaction_days' => 'nullable|integer|min:0',
            'trigger_conditions.time_since_last_message_hours' => 'nullable|integer|min:0',
            'trigger_conditions.tags' => 'nullable|array',
            'trigger_conditions.tags.*' => 'string',
        ]);

        // Update slug if name changed
        if (isset($validated['name']) && $validated['name'] !== $agent->name) {
            $baseSlug = Str::slug($validated['name']);
            $slug = $baseSlug;
            $counter = 1;

            while (AiAgent::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $baseSlug . '-' . $counter++;
            }

            $validated['slug'] = $slug;
        }

        $agent->update($validated);

        // Clear cache
        $this->agentSelector->clearAgentCache($companyId);

        ActivityLogService::log('ai_agent_updated', "Updated AI agent: {$agent->name}", $agent, [
            'agent_name' => $agent->name,
            'agent_id' => $agent->id,
        ]);

        $agent->load('aiProvider');

        return response()->json([
            'message' => 'AI agent updated successfully',
            'data' => new AiAgentResource($agent),
        ]);
    }

    /**
     * Delete an AI agent
     */
    public function destroy(Request $request, int $id)
    {
        $companyId = $request->company_id;

        $agent = AiAgent::where('company_id', $companyId)->findOrFail($id);

        $agentName = $agent->name;
        $agentId = $agent->id;
        $agent->delete();

        // Clear cache
        $this->agentSelector->clearAgentCache($companyId);

        ActivityLogService::log('ai_agent_deleted', "Deleted AI agent: {$agentName}", null, [
            'agent_name' => $agentName,
            'agent_id' => $agentId,
        ]);

        return response()->json([
            'message' => 'AI agent deleted successfully',
        ]);
    }

    /**
     * Reorder agents (update priorities)
     */
    public function reorder(Request $request)
    {
        $companyId = $request->company_id;

        $validated = $request->validate([
            'agents' => 'required|array',
            'agents.*.id' => 'required|integer',
            'agents.*.priority' => 'required|integer|min:0|max:999',
        ]);

        foreach ($validated['agents'] as $agentData) {
            $agent = AiAgent::where('company_id', $companyId)
                ->where('id', $agentData['id'])
                ->first();

            if ($agent) {
                $agent->update(['priority' => $agentData['priority']]);
            }
        }

        // Clear cache
        $this->agentSelector->clearAgentCache($companyId);

        return response()->json([
            'message' => 'Agents reordered successfully',
        ]);
    }

    /**
     * Duplicate an agent
     */
    public function duplicate(Request $request, int $id)
    {
        $companyId = $request->company_id;

        $originalAgent = AiAgent::where('company_id', $companyId)
            ->with('aiProvider')
            ->findOrFail($id);

        $newAgent = $originalAgent->replicate();
        $newAgent->name = $originalAgent->name . ' (Copy)';
        $newAgent->slug = $originalAgent->slug . '-copy';
        $newAgent->is_active = false; // Start inactive
        $newAgent->save();

        // Clear cache
        $this->agentSelector->clearAgentCache($companyId);

        ActivityLogService::log('ai_agent_duplicated', "Duplicated AI agent: {$originalAgent->name}", $newAgent, [
            'original_agent_id' => $originalAgent->id,
            'new_agent_id' => $newAgent->id,
        ]);

        return response()->json([
            'message' => 'AI agent duplicated successfully',
            'data' => new AiAgentResource($newAgent->fresh('aiProvider')),
        ], 201);
    }

    /**
     * Get agent types info for UI
     */
    public function types()
    {
        return response()->json([
            'data' => [
                [
                    'value' => 'new_customer',
                    'label' => 'New Customer',
                    'description' => 'For first-time customers with warm welcome and introduction',
                    'icon' => 'pi pi-user-plus',
                    'color' => 'green',
                ],
                [
                    'value' => 'returning_customer',
                    'label' => 'Returning Customer',
                    'description' => 'For customers who have interacted before',
                    'icon' => 'pi pi-refresh',
                    'color' => 'blue',
                ],
                [
                    'value' => 'follow_up',
                    'label' => 'Follow-up',
                    'description' => 'For re-engaging customers after a period of inactivity',
                    'icon' => 'pi pi-clock',
                    'color' => 'orange',
                ],
                [
                    'value' => 'vip',
                    'label' => 'VIP Customer',
                    'description' => 'For high-value customers with premium service',
                    'icon' => 'pi pi-star',
                    'color' => 'yellow',
                ],
                [
                    'value' => 'general',
                    'label' => 'General',
                    'description' => 'Default agent for all situations',
                    'icon' => 'pi pi-users',
                    'color' => 'gray',
                ],
                [
                    'value' => 'custom',
                    'label' => 'Custom',
                    'description' => 'Create your own custom agent with specific triggers',
                    'icon' => 'pi pi-cog',
                    'color' => 'purple',
                ],
            ],
        ]);
    }

    /**
     * Initialize default agents for a company
     */
    public function initializeDefaults(Request $request)
    {
        $companyId = $request->company_id;

        // Check if agents already exist
        $existingCount = AiAgent::where('company_id', $companyId)->count();
        if ($existingCount > 0) {
            return response()->json([
                'message' => 'Agents already exist for this company',
                'count' => $existingCount,
            ], 400);
        }

        // Get OpenAI provider
        $aiProvider = AiProvider::where('slug', 'openai')->firstOrFail();

        $defaultAgents = AgentSelectorService::getDefaultAgentsForCompany($companyId, $aiProvider->id);

        DB::beginTransaction();
        try {
            foreach ($defaultAgents as $agentData) {
                AiAgent::create($agentData);
            }
            DB::commit();

            // Clear cache
            $this->agentSelector->clearAgentCache($companyId);

            $agents = AiAgent::where('company_id', $companyId)
                ->with('aiProvider')
                ->get();

            return response()->json([
                'message' => 'Default AI agents initialized successfully',
                'data' => AiAgentResource::collection($agents),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

}
