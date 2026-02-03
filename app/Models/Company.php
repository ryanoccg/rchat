<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'address',
        'logo',
        'timezone',
        'business_hours',
        'trial_ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'business_hours' => 'array',
            'trial_ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function teamInvitations()
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function platformConnections()
    {
        return $this->hasMany(PlatformConnection::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function aiConfiguration()
    {
        return $this->hasOne(AiConfiguration::class);
    }

    public function knowledgeBase()
    {
        return $this->hasMany(KnowledgeBase::class);
    }

    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }

    public function usageTracking()
    {
        return $this->hasMany(UsageTracking::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function activityLogs()
    {
        return $this->hasMany(ActivityLog::class);
    }
}
