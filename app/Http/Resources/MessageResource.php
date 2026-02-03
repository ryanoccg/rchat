<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Optimized to only include necessary fields, excluding sensitive internal data.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'message_type' => $this->message_type,
            'content' => $this->content,
            'media_urls' => $this->media_urls,
            'is_from_customer' => $this->is_from_customer,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),

            // Sender info (only if loaded)
            'sender' => $this->whenLoaded('sender', fn() => [
                'id' => $this->sender?->id,
                'name' => $this->sender?->name,
                'type' => $this->sender_type,
            ]),

            // Quoted message (only if loaded)
            'quoted_message' => $this->whenLoaded('quotedMessage', fn() => $this->quotedMessage ? [
                'id' => $this->quotedMessage->id,
                'content' => $this->quotedMessage->content,
                'message_type' => $this->quotedMessage->message_type,
            ] : null),

            // Media analysis results (only if loaded)
            'media_analysis' => $this->whenLoaded('mediaProcessingResults', fn() =>
                $this->mediaProcessingResults?->map(fn($result) => [
                    'type' => $result->type,
                    'result' => $result->result,
                ])
            ),

            // AI confidence (without exposing full response data)
            'ai_confidence' => $this->ai_confidence,
        ];
    }
}
