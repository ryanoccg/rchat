<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkflowExecutionLog extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'workflow_execution_id',
        'workflow_step_id',
        'step_type',
        'status',
        'input_data',
        'output_data',
        'error_message',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'input_data' => 'array',
            'output_data' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(WorkflowExecution::class, 'workflow_execution_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(WorkflowStep::class, 'workflow_step_id');
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to get failed logs.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to get completed logs.
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    /**
     * Mark log as started.
     */
    public static function start(WorkflowExecution $execution, WorkflowStep $step, array $inputData = []): self
    {
        return static::create([
            'company_id' => $execution->company_id,
            'workflow_execution_id' => $execution->id,
            'workflow_step_id' => $step->id,
            'step_type' => $step->step_type,
            'status' => 'started',
            'input_data' => $inputData,
            'executed_at' => now(),
        ]);
    }

    /**
     * Mark log as completed.
     */
    public function complete(array $outputData = []): void
    {
        $this->update([
            'status' => 'completed',
            'output_data' => $outputData,
        ]);
    }

    /**
     * Mark log as failed.
     */
    public function fail(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Mark log as skipped.
     */
    public function skip(): void
    {
        $this->update(['status' => 'skipped']);
    }
}
