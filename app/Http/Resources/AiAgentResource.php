<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiAgentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'agent_type' => $this->agent_type,
            'description' => $this->description,
            'ai_provider_id' => $this->ai_provider_id,
            'model' => $this->model,
            'system_prompt' => $this->system_prompt,
            'personality_tone' => $this->personality_tone,
            'prohibited_topics' => $this->prohibited_topics,
            'custom_instructions' => $this->custom_instructions,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
            'confidence_threshold' => $this->confidence_threshold ? $this->confidence_threshold / 100 : null,
            'is_active' => $this->is_active,
            'is_personality_only' => $this->is_personality_only,
            'priority' => $this->priority,
            'trigger_conditions' => $this->trigger_conditions,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'provider' => $this->when($this->relationLoaded('aiProvider') && $this->aiProvider, [
                'id' => $this->aiProvider?->id,
                'name' => $this->aiProvider?->name,
                'slug' => $this->aiProvider?->slug,
            ]),
        ];
    }
}
