<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'step_type' => $this->step_type,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'config' => $this->config,
            'next_steps' => $this->next_steps,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
