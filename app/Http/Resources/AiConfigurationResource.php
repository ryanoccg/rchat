<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AiConfigurationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'primary_provider_id' => $this->primary_provider_id,
            'fallback_provider_id' => $this->fallback_provider_id,
            'primary_model' => $this->primary_model,
            'system_prompt' => $this->system_prompt,
            'personality_tone' => $this->personality_tone,
            'prohibited_topics' => $this->prohibited_topics,
            'custom_instructions' => $this->custom_instructions,
            'confidence_threshold' => $this->confidence_threshold ? $this->confidence_threshold / 100 : null,
            'auto_respond' => $this->auto_respond,
            'response_delay_seconds' => $this->response_delay_seconds,
            'max_tokens' => $this->max_tokens,
            'temperature' => $this->temperature,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'primary_provider' => $this->whenLoaded('primaryProvider'),
            'fallback_provider' => $this->whenLoaded('fallbackProvider'),
        ];
    }
}
