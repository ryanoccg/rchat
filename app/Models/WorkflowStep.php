<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowStep extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'workflow_id',
        'step_type',
        'name',
        'description',
        'position',
        'config',
        'next_steps',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'array',
            'config' => 'array',
            'next_steps' => 'array',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecutionLog::class);
    }

    /**
     * Validate step configuration based on step type.
     */
    public function validateConfig(): bool
    {
        return match ($this->step_type) {
            'action' => $this->validateActionConfig(),
            'condition' => $this->validateConditionConfig(),
            'delay' => $this->validateDelayConfig(),
            default => true,
        };
    }

    /**
     * Validate action step configuration.
     */
    protected function validateActionConfig(): bool
    {
        $actionType = $this->config['action_type'] ?? null;

        return match ($actionType) {
            'send_message', 'send_ai_response' => !empty($this->config['message']),
            'add_tag', 'remove_tag' => !empty($this->config['tags']),
            'assign_agent' => !empty($this->config['agent_id']),
            'assign_team' => !empty($this->config['team_id']),
            'human_handoff' => true,
            'set_status' => !empty($this->config['status']),
            'set_priority' => !empty($this->config['priority']),
            default => false,
        };
    }

    /**
     * Validate condition step configuration.
     */
    protected function validateConditionConfig(): bool
    {
        $conditionType = $this->config['condition_type'] ?? null;

        return match ($conditionType) {
            'customer_attribute', 'conversation_attribute' => !empty($this->config['field']) && isset($this->config['operator']) && isset($this->config['value']),
            'message_content' => !empty($this->config['pattern']) || !empty($this->config['keywords']),
            'time_of_day' => isset($this->config['start_time']) && isset($this->config['end_time']),
            'day_of_week' => !empty($this->config['days']),
            'ai_condition' => !empty($this->config['ai_prompt']),
            default => false,
        };
    }

    /**
     * Validate delay step configuration.
     */
    protected function validateDelayConfig(): bool
    {
        return isset($this->config['delay_minutes']) && $this->config['delay_minutes'] > 0;
    }

    /**
     * Get next step IDs based on condition result.
     */
    public function getNextSteps(?bool $conditionResult = null): array
    {
        if (empty($this->next_steps)) {
            return [];
        }

        // If no condition, return all next steps
        if ($conditionResult === null) {
            return array_column($this->next_steps, 'step_id');
        }

        // Find matching branch
        foreach ($this->next_steps as $next) {
            if (($next['condition'] ?? 'true') === ($conditionResult ? 'true' : 'false')) {
                return [$next['step_id']];
            }
        }

        return [];
    }
}
