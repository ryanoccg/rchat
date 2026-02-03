<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Subscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'plan_name',
        'plan_type',
        'status',
        'message_limit',
        'storage_limit',
        'team_member_limit',
        'platform_limit',
        'price',
        'stripe_subscription_id',
        'stripe_customer_id',
        'trial_ends_at',
        'current_period_start',
        'current_period_end',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'trial_ends_at' => 'datetime',
            'current_period_start' => 'datetime',
            'current_period_end' => 'datetime',
            'cancelled_at' => 'datetime',
            'price' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' || $this->isOnTrial();
    }

    public function isOnTrial(): bool
    {
        return $this->status === 'trial' && $this->trial_ends_at && $this->trial_ends_at->isFuture();
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function hasReachedMessageLimit(int $currentUsage): bool
    {
        if (is_null($this->message_limit)) {
            return false; // Unlimited
        }
        return $currentUsage >= $this->message_limit;
    }

    public function hasReachedStorageLimit(int $currentUsageMB): bool
    {
        if (is_null($this->storage_limit)) {
            return false; // Unlimited
        }
        return $currentUsageMB >= $this->storage_limit;
    }

    public function hasReachedTeamLimit(int $currentCount): bool
    {
        if (is_null($this->team_member_limit)) {
            return false; // Unlimited
        }
        return $currentCount >= $this->team_member_limit;
    }

    public function hasReachedPlatformLimit(int $currentCount): bool
    {
        if (is_null($this->platform_limit)) {
            return false; // Unlimited
        }
        return $currentCount >= $this->platform_limit;
    }
}
