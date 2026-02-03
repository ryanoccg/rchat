<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledWorkflowRun extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'workflow_id',
        'customer_id',
        'conversation_id',
        'scheduled_at',
        'execution_context',
        'status',
        'executed_at',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'execution_context' => 'array',
            'scheduled_at' => 'datetime',
            'executed_at' => 'datetime',
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

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to get pending scheduled runs that are due.
     */
    public function scopeDue($query)
    {
        return $query->where('status', 'pending')
            ->where('scheduled_at', '<=', now());
    }

    /**
     * Scope to filter by status.
     */
    public function scopeStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Mark as executed.
     */
    public function markAsExecuted(): void
    {
        $this->update([
            'status' => 'completed',
            'executed_at' => now(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'executed_at' => now(),
        ]);
    }

    /**
     * Cancel the scheduled run.
     */
    public function cancel(): void
    {
        $this->update(['status' => 'cancelled']);
    }

    /**
     * Check if the scheduled run is due.
     */
    public function isDue(): bool
    {
        return $this->status === 'pending' && $this->scheduled_at->isPast();
    }
}
