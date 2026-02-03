<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'customer_id',
        'platform_connection_id',
        'assigned_to',
        'status',
        'priority',
        'is_ai_handling',
        'ai_confidence_score',
        'ai_context',
        'last_message_at',
        'closed_at',
        'closed_reason',
        'active_workflow_execution_id',
        'workflow_metadata',
    ];

    // Removed 'last_message' from appends for performance
    // Use ConversationResource for API responses with optimized field selection
    protected $appends = [];

    protected function casts(): array
    {
        return [
            'is_ai_handling' => 'boolean',
            'last_message_at' => 'datetime',
            'closed_at' => 'datetime',
            'workflow_metadata' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function platformConnection()
    {
        return $this->belongsTo(PlatformConnection::class);
    }

    public function assignedAgent()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    public function tags()
    {
        return $this->hasMany(ConversationTag::class);
    }

    public function summary()
    {
        return $this->hasOne(ConversationSummary::class);
    }

    public function sentimentAnalysis()
    {
        return $this->hasMany(SentimentAnalysis::class);
    }

    public function satisfactionRating()
    {
        return $this->hasOne(SatisfactionRating::class);
    }

    public function latestMessage()
    {
        return $this->hasOne(Message::class)
            ->select('messages.*')
            ->latestOfMany();
    }

    // Accessor for last message content
    public function getLastMessageAttribute()
    {
        if ($this->relationLoaded('latestMessage') && $this->latestMessage) {
            return $this->latestMessage->content;
        }
        return null;
    }

    public function activeWorkflowExecution()
    {
        return $this->belongsTo(WorkflowExecution::class, 'active_workflow_execution_id');
    }

    public function workflowExecutions()
    {
        return $this->hasMany(WorkflowExecution::class);
    }

    public function scheduledWorkflowRuns()
    {
        return $this->hasMany(ScheduledWorkflowRun::class);
    }

    /**
     * Check if conversation has been idle for X minutes.
     */
    public function isIdle(int $minutes = 60): bool
    {
        return $this->last_message_at?->lt(now()->subMinutes($minutes)) ?? false;
    }

    /**
     * Get message count for this conversation.
     */
    public function getMessageCount(): int
    {
        return $this->messages()->count();
    }

    /**
     * Accessor for message_count attribute (used by workflow conditions).
     */
    public function getMessageCountAttribute(): int
    {
        // Use messages_count if it was loaded via withCount, otherwise query
        if (array_key_exists('messages_count', $this->attributes)) {
            return $this->attributes['messages_count'];
        }
        return $this->getMessageCount();
    }

    /**
     * Check if this is the first message in conversation.
     */
    public function isFirstMessage(): bool
    {
        return $this->getMessageCount() === 1;
    }

    /**
     * Set workflow metadata value.
     */
    public function setWorkflowMetadata(string $key, mixed $value): void
    {
        $metadata = $this->workflow_metadata ?? [];
        $metadata[$key] = $value;
        $this->update(['workflow_metadata' => $metadata]);
    }

    /**
     * Get workflow metadata value.
     */
    public function getWorkflowMetadata(string $key, mixed $default = null): mixed
    {
        return $this->workflow_metadata[$key] ?? $default;
    }
}
