<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class TeamInvitation extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'email',
        'role',
        'token',
        'invited_by',
        'expires_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return is_null($this->accepted_at) && !$this->isExpired();
    }

    public function markAsAccepted(): void
    {
        $this->update(['accepted_at' => now()]);
    }

    public function scopePending($query)
    {
        return $query->whereNull('accepted_at')
            ->where('expires_at', '>', now());
    }

    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<=', now());
    }
}
