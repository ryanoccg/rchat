<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BroadcastRecipient extends Model
{
    use HasFactory;

    protected $fillable = [
        'broadcast_id',
        'customer_id',
        'conversation_id',
        'status',
        'platform_message_id',
        'error_message',
        'sent_at',
        'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function broadcast()
    {
        return $this->belongsTo(Broadcast::class);
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
     * Scope to filter by status
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope for pending recipients
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Mark as sent
     */
    public function markAsSent(string $platformMessageId = null): void
    {
        $this->update([
            'status' => 'sent',
            'platform_message_id' => $platformMessageId,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark as failed
     */
    public function markAsFailed(string $error = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark as delivered
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
    }
}
