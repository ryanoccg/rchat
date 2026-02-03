<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MediaProcessingResult extends Model
{
    use HasFactory;

    protected $fillable = [
        'message_id',
        'media_type',
        'processor',
        'text_content',
        'analysis_data',
        'status',
        'error_message',
        'processing_time_ms',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'analysis_data' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    /**
     * Status constants
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    /**
     * Media type constants
     */
    public const MEDIA_TYPE_IMAGE = 'image';
    public const MEDIA_TYPE_AUDIO = 'audio';

    /**
     * Get the message this result belongs to
     */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /**
     * Scope to get pending results
     */
    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to get completed results
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to get failed results
     */
    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Check if processing is complete
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if processing failed
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Mark as processing
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark as completed with results
     */
    public function markAsCompleted(string $textContent, array $analysisData = [], int $processingTimeMs = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLETED,
            'text_content' => $textContent,
            'analysis_data' => $analysisData,
            'processing_time_ms' => $processingTimeMs,
            'processed_at' => now(),
        ]);
    }

    /**
     * Mark as failed with error message
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => $errorMessage,
            'processed_at' => now(),
        ]);
    }
}
