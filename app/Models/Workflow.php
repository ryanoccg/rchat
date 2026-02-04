<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Workflow extends Model
{
    use HasFactory, BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'company_id',
        'name',
        'description',
        'status',
        'trigger_type',
        'trigger_config',
        'workflow_definition',
        'execution_mode',
    ];

    protected function casts(): array
    {
        return [
            'trigger_config' => 'array',
            'workflow_definition' => 'array',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowStep::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(WorkflowExecution::class);
    }

    public function scheduledRuns(): HasMany
    {
        return $this->hasMany(ScheduledWorkflowRun::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to filter only active workflows.
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope to filter by trigger type.
     */
    public function scopeForTrigger($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }

    /**
     * Check if workflow can start for the given event.
     */
    public function canStartFor(array $eventData): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        // Check trigger-specific conditions
        return match ($this->trigger_type) {
            'customer_created' => isset($eventData['customer']),
            'customer_returning' => isset($eventData['customer']) && $eventData['customer']->created_at->lt(now()->subDays($this->trigger_config['return_days'] ?? 30)),
            'first_message' => isset($eventData['conversation']) && $eventData['conversation']->messages()->count() === 1,
            'conversation_created' => isset($eventData['conversation']),
            'conversation_closed' => isset($eventData['conversation']) && $eventData['conversation']->status === 'closed',
            'message_received' => isset($eventData['message']) && $eventData['message']['sender_type'] === 'customer',
            'no_response' => isset($eventData['conversation']) && $eventData['conversation']->last_message_at?->lt(now()->subMinutes($this->trigger_config['no_response_minutes'] ?? 60)),
            'scheduled' => $this->isScheduleDue(),
            'auto_follow_up' => isset($eventData['conversation']) && ($eventData['trigger_source'] ?? null) === 'auto_follow_up',
            default => false,
        };
    }

    /**
     * Check if scheduled workflow is due to run.
     */
    protected function isScheduleDue(): bool
    {
        if (!isset($this->trigger_config['schedule'])) {
            return false;
        }

        $schedule = $this->trigger_config['schedule'];
        $now = now();

        return match ($schedule['type'] ?? 'once') {
            'daily' => true, // Will be filtered by scheduled runs table
            'weekly' => $now->dayOfWeek === ($schedule['day_of_week'] ?? 0),
            'monthly' => $now->day === ($schedule['day_of_month'] ?? 1),
            'cron' => $this->checkCronSchedule($schedule['cron'] ?? ''),
            default => true,
        };
    }

    /**
     * Check if cron schedule matches current time.
     * Uses dragonmantank/cron-expression library for accurate parsing.
     */
    protected function checkCronSchedule(string $cronExpression): bool
    {
        if (empty($cronExpression)) {
            return false;
        }

        try {
            $cron = \Cron\CronExpression::factory($cronExpression);
            return $cron->isDue();
        } catch (\Exception $e) {
            \Log::warning('Invalid cron expression', [
                'expression' => $cronExpression,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get AI instructions from workflow definition.
     */
    public function getAiInstructions(): ?string
    {
        return $this->trigger_config['ai_instructions'] ?? null;
    }

    /**
     * Activate the workflow.
     */
    public function activate(): void
    {
        $this->update(['status' => 'active']);
    }

    /**
     * Deactivate the workflow.
     */
    public function deactivate(): void
    {
        $this->update(['status' => 'inactive']);
    }
}
