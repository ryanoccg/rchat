<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BroadcastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'user_id' => $this->user_id,
            'platform_connection_id' => $this->platform_connection_id,
            'name' => $this->name,
            'message' => $this->message,
            'message_type' => $this->message_type,
            'media_urls' => $this->media_urls,
            'status' => $this->status,
            'scheduled_at' => $this->scheduled_at,
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'filters' => $this->filters,
            'notes' => $this->notes,
            'total_recipients' => $this->total_recipients,
            'sent_count' => $this->sent_count,
            'failed_count' => $this->failed_count,
            'delivered_count' => $this->delivered_count,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
            ]),
            'platform_connection' => new PlatformConnectionResource($this->whenLoaded('platformConnection')),
            'statistics' => $this->when(isset($this->statistics), $this->statistics),
            // Counts from withCount
            'pending_count' => $this->when(isset($this->pending_count), $this->pending_count),
            'sending_count' => $this->when(isset($this->sending_count), $this->sending_count),
        ];
    }
}
