<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WorkflowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'trigger_type' => $this->trigger_type,
            'trigger_config' => $this->trigger_config,
            'execution_mode' => $this->execution_mode,
            'workflow_definition' => $this->workflow_definition,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
            'steps' => WorkflowStepResource::collection($this->whenLoaded('steps')),
            'stats' => $this->when(isset($this->stats), $this->stats),
            // Counts from withCount
            'total_executions' => $this->when(isset($this->total_executions), $this->total_executions),
            'successful_executions' => $this->when(isset($this->successful_executions), $this->successful_executions),
            'failed_executions' => $this->when(isset($this->failed_executions), $this->failed_executions),
            'step_count' => $this->when(isset($this->step_count), $this->step_count),
        ];
    }
}
