<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CalendarConfiguration extends Model
{
    use HasFactory, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'provider',
        'credentials',
        'calendar_id',
        'calendar_name',
        'is_connected',
        'is_enabled',
        'slot_duration',
        'buffer_time',
        'advance_booking_days',
        'min_notice_hours',
        'working_hours',
        'blocked_dates',
        'timezone',
        'booking_instructions',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'array',
            'working_hours' => 'array',
            'blocked_dates' => 'array',
            'is_connected' => 'boolean',
            'is_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * Default working hours (9 AM to 6 PM, Monday to Friday)
     */
    public static function getDefaultWorkingHours(): array
    {
        return [
            'monday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
            'tuesday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
            'wednesday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
            'thursday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
            'friday' => ['start' => '09:00', 'end' => '18:00', 'enabled' => true],
            'saturday' => ['start' => '09:00', 'end' => '13:00', 'enabled' => false],
            'sunday' => ['start' => '09:00', 'end' => '13:00', 'enabled' => false],
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'company_id', 'company_id');
    }

    /**
     * Check if credentials are valid (not expired)
     */
    public function hasValidCredentials(): bool
    {
        if (!$this->is_connected || !$this->credentials) {
            return false;
        }

        $expiresAt = $this->credentials['expires_at'] ?? null;
        if (!$expiresAt) {
            return false;
        }

        // Consider invalid if expiring within 5 minutes
        return now()->addMinutes(5)->lt($expiresAt);
    }

    /**
     * Get the access token, refreshing if needed
     */
    public function getAccessToken(): ?string
    {
        return $this->credentials['access_token'] ?? null;
    }

    /**
     * Get the refresh token
     */
    public function getRefreshToken(): ?string
    {
        return $this->credentials['refresh_token'] ?? null;
    }
}
