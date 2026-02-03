<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkflowExecution extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'workflow_id',
        'customer_id',
        'conversation_id',
        'status',
        'current_step_id',
        'execution_context',
        'started_at',
        'completed_at',
        'failed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'execution_context' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'current_step_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(WorkflowExecutionLog::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to filter running executions.
     */
    public function scopeRunning($query)
    {
        return $query->where('status', 'running');
    }

    /**
     * Scope to filter pending executions.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to filter failed executions.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Start the execution.
     */
    public function start(): void
    {
        $this->update([
            'status' => 'running',
            'started_at' => now(),
        ]);
    }

    /**
     * Complete the execution.
     */
    public function complete(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'current_step_id' => null,
        ]);

        // Update conversation if attached
        if ($this->conversation) {
            $this->conversation->update([
                'active_workflow_execution_id' => null,
            ]);
        }
    }

    /**
     * Mark execution as failed.
     */
    public function fail(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'failed_at' => now(),
            'error_message' => $errorMessage,
        ]);

        // Clear active workflow execution on conversation to allow future workflows
        if ($this->conversation) {
            $this->conversation->update([
                'active_workflow_execution_id' => null,
            ]);
        }
    }

    /**
     * Pause the execution.
     */
    public function pause(): void
    {
        $this->update(['status' => 'paused']);
    }

    /**
     * Resume the execution.
     */
    public function resume(): void
    {
        $this->update(['status' => 'running']);
    }

    /**
     * Cancel the execution.
     */
    public function cancel(): void
    {
        $this->update([
            'status' => 'cancelled',
            'current_step_id' => null,
        ]);

        // Update conversation if attached
        if ($this->conversation) {
            $this->conversation->update([
                'active_workflow_execution_id' => null,
            ]);
        }
    }

    /**
     * Check if execution is complete.
     */
    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'failed', 'cancelled']);
    }

    /**
     * Check if execution is running.
     */
    public function isRunning(): bool
    {
        return $this->status === 'running';
    }

    /**
     * Get execution duration in seconds.
     */
    public function getDuration(): ?int
    {
        if (!$this->started_at) {
            return null;
        }

        $endTime = $this->completed_at ?? $this->failed_at ?? now();

        return $endTime->diffInSeconds($this->started_at);
    }

    /**
     * Set context value.
     */
    public function setContext(string $key, mixed $value): void
    {
        $context = $this->execution_context ?? [];
        $context[$key] = $value;
        $this->update(['execution_context' => $context]);
    }

    /**
     * Get context value.
     */
    public function getContext(string $key, mixed $default = null): mixed
    {
        return $this->execution_context[$key] ?? $default;
    }
}
