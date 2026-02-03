<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'platform_user_id',
        'messaging_platform_id',
        'name',
        'email',
        'phone',
        'profile_photo_url',
        'profile_data',
        'language',
        'metadata',
        'workflow_state',
        'last_workflow_execution_at',
    ];

    protected $appends = ['customer_type', 'total_message_count', 'display_name'];

    protected function casts(): array
    {
        return [
            'profile_data' => 'array',
            'metadata' => 'array',
            'workflow_state' => 'array',
            'last_workflow_execution_at' => 'datetime',
        ];
    }

    protected function profilePhotoUrl(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value ?? $this->profile_data['profile_pic'] ?? $this->profile_data['avatar'] ?? null,
        );
    }

    /**
     * Get display name from multiple sources.
     * Falls back to platform_user_id if no name found.
     * Uses a custom check that treats empty strings as null.
     */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->name) {
                    return $this->name;
                }

                if ($this->profile_data['name'] ?? null) {
                    return $this->profile_data['name'];
                }

                $firstName = $this->profile_data['first_name'] ?? null;
                $lastName = $this->profile_data['last_name'] ?? null;
                if ($firstName || $lastName) {
                    return trim($firstName . ' ' . $lastName);
                }

                if ($this->platform_user_id) {
                    return $this->platform_user_id;
                }

                return 'Unknown';
            }
        );
    }

    /**
     * Get customer type based on total message count.
     * new: ≤2 messages, returning: 3-10 messages, vip: >10 messages
     */
    protected function customerType(): Attribute
    {
        $totalMessages = 0;
        if ($this->relationLoaded('conversations')) {
            foreach ($this->conversations as $conversation) {
                if ($conversation->relationLoaded('messages')) {
                    $totalMessages += $conversation->messages->where('is_from_customer', true)->count();
                } else {
                    $totalMessages += $conversation->messages()->where('is_from_customer', true)->count();
                }
            }
        } else {
            $totalMessages = Message::whereIn(
                'conversation_id',
                $this->conversations()->pluck('id')
            )->where('is_from_customer', true)->count();
        }

        $type = match (true) {
            $totalMessages <= 2 => 'new',
            $totalMessages <= 10 => 'returning',
            default => 'vip',
        };

        return Attribute::make(
            get: fn () => $type,
        );
    }

    /**
     * Get total message count across all conversations.
     */
    protected function totalMessageCount(): Attribute
    {
        $count = 0;
        if ($this->relationLoaded('conversations')) {
            foreach ($this->conversations as $conversation) {
                if ($conversation->relationLoaded('messages')) {
                    $count += $conversation->messages->where('is_from_customer', true)->count();
                } else {
                    $count += $conversation->messages()->where('is_from_customer', true)->count();
                }
            }
        } else {
            $count = Message::whereIn(
                'conversation_id',
                $this->conversations()->pluck('id')
            )->where('is_from_customer', true)->count();
        }

        return Attribute::make(
            get: fn () => $count,
        );
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function messagingPlatform()
    {
        return $this->belongsTo(MessagingPlatform::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function satisfactionRatings()
    {
        return $this->hasMany(SatisfactionRating::class);
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
     * Check if customer is a returning customer (created more than X days ago).
     */
    public function isReturning(int $days = 30): bool
    {
        return $this->created_at->lt(now()->subDays($days));
    }

    /**
     * Set workflow state value.
     */
    public function setWorkflowState(string $key, mixed $value): void
    {
        $state = $this->workflow_state ?? [];
        $state[$key] = $value;
        $this->update(['workflow_state' => $state]);
    }

    /**
     * Get workflow state value.
     */
    public function getWorkflowState(string $key, mixed $default = null): mixed
    {
        return $this->workflow_state[$key] ?? $default;
    }
}
