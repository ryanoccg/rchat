<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Appointment extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'customer_id',
        'conversation_id',
        'google_event_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'start_time' => 'datetime',
            'end_time' => 'datetime',
            'metadata' => 'array',
        ];
    }

    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_COMPLETED = 'completed';
    const STATUS_NO_SHOW = 'no_show';

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Check if appointment is upcoming
     */
    public function isUpcoming(): bool
    {
        return $this->start_time->isFuture() && $this->status === self::STATUS_CONFIRMED;
    }

    /**
     * Check if appointment is past
     */
    public function isPast(): bool
    {
        return $this->end_time->isPast();
    }

    /**
     * Get formatted duration
     */
    public function getDurationMinutes(): int
    {
        return $this->start_time->diffInMinutes($this->end_time);
    }

    /**
     * Scope for upcoming appointments
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_time', '>', now())
            ->where('status', self::STATUS_CONFIRMED)
            ->orderBy('start_time');
    }

    /**
     * Scope for appointments in date range
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('start_time', [$startDate, $endDate]);
    }
}
