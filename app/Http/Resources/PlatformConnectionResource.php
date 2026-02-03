<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlatformConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'messaging_platform_id' => $this->messaging_platform_id,
            'platform_account_name' => $this->platform_account_name,
            'is_active' => $this->is_active,
            'connected_at' => $this->connected_at,
            'webhook_config' => $this->webhook_config,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'messaging_platform' => $this->whenLoaded('messagingPlatform'),
            // Embed code for webchat connections
            'embed_code' => $this->when(
                $this->relationLoaded('messagingPlatform') && $this->messagingPlatform?->slug === 'webchat',
                fn () => $this->getWebChatEmbedCode()
            ),
        ];
        // NOTE: credentials are intentionally excluded to prevent API key/token exposure
    }

    protected function getWebChatEmbedCode(): string
    {
        $scriptUrl = url("/api/webchat/widget/{$this->id}.js");
        return "<!-- RChat Web Chat Widget -->\n<script src=\"{$scriptUrl}\" async></script>";
    }
}
