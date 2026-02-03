<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppointmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'customer_id' => $this->customer_id,
            'conversation_id' => $this->conversation_id,
            'title' => $this->title,
            'customer_name' => $this->customer_name,
            'customer_email' => $this->customer_email,
            'customer_phone' => $this->customer_phone,
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'status' => $this->status,
            'notes' => $this->notes,
            'cancellation_reason' => $this->cancellation_reason,
            'google_event_id' => $this->google_event_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'customer' => $this->whenLoaded('customer', fn () => [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'email' => $this->customer->email,
                'phone' => $this->customer->phone,
                'profile_photo_url' => $this->customer->profile_photo_url,
            ]),
            'conversation' => $this->whenLoaded('conversation', fn () => [
                'id' => $this->conversation->id,
                'status' => $this->conversation->status,
            ]),
        ];
    }
}
