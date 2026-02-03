<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * Optimized to only include necessary fields
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'display_name' => $this->display_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'language' => $this->language,
            'profile_photo_url' => $this->profile_photo_url,
            'platform_user_id' => $this->platform_user_id,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
