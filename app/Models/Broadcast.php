<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\BelongsToCompany;

class Broadcast extends Model
{
    use HasFactory, SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'platform_connection_id',
        'name',
        'message',
        'message_type',
        'media_urls',
        'status',
        'scheduled_at',
        'started_at',
        'completed_at',
        'total_recipients',
        'sent_count',
        'failed_count',
        'delivered_count',
        'filters',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
            'filters' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function platformConnection()
    {
        return $this->belongsTo(PlatformConnection::class);
    }

    public function recipients()
    {
        return $this->hasMany(BroadcastRecipient::class);
    }

    /**
     * Scope to filter by status
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for scheduled broadcasts that need to be sent
     */
    public function scopeReadyToSend($query)
    {
        return $query->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now());
    }

    /**
     * Check if broadcast can be edited
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'scheduled']);
    }

    /**
     * Check if broadcast can be sent now
     */
    public function canBeSent(): bool
    {
        return $this->status === 'draft' && $this->platform_connection_id !== null;
    }

    /**
     * Get completion percentage
     */
    public function getCompletionPercentage(): int
    {
        if (empty($this->total_recipients)) {
            return 0;
        }

        return (int) (($this->sent_count + $this->failed_count) / $this->total_recipients * 100);
    }

    /**
     * Get success rate percentage
     */
    public function getSuccessRate(): int
    {
        $total = (int) $this->sent_count + (int) $this->failed_count;
        if ($total === 0) {
            return 0;
        }

        return (int) ($this->sent_count / $total * 100);
    }
}
