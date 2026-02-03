<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Optimized to only include necessary fields for listing
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'priority' => $this->priority,
            'is_ai_handling' => $this->is_ai_handling,
            'ai_confidence_score' => $this->ai_confidence_score,
            'last_message_at' => $this->last_message_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),

            // Relationships - only include if loaded
            'customer' => $this->whenLoaded('customer', fn() => new CustomerResource($this->customer)),
            'platform_connection' => $this->whenLoaded('platformConnection', fn() => [
                'id' => $this->platformConnection->id,
                'platform' => $this->platformConnection->relationLoaded('messagingPlatform') && $this->platformConnection->messagingPlatform
                    ? [
                        'id' => $this->platformConnection->messagingPlatform->id,
                        'name' => $this->platformConnection->messagingPlatform->name,
                        'slug' => $this->platformConnection->messagingPlatform->slug,
                        'icon' => $this->platformConnection->messagingPlatform->icon,
                    ]
                    : null,
            ]),
            'assigned_agent' => $this->whenLoaded('assignedAgent', fn() => [
                'id' => $this->assignedAgent->id,
                'name' => $this->assignedAgent->name,
            ]),

            // Latest message - optimized
            'last_message' => $this->whenLoaded('latestMessage', fn() =>
                $this->latestMessage?->content
            ),

            // Unread count if available
            'unread_count' => $this->unread_count ?? 0,
        ];
    }
}
