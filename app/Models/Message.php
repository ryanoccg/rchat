<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'sender_type',
        'message_type',
        'content',
        'media_urls',
        'metadata',
        'platform_message_id',
        'is_from_customer',
        'ai_provider_id',
        'ai_confidence',
        'ai_response_data',
        'read_at',
        'ai_processed_at',
        'quoted_message_id',
    ];

    protected function casts(): array
    {
        return [
            'media_urls' => 'array',
            'metadata' => 'array',
            'is_from_customer' => 'boolean',
            'ai_response_data' => 'array',
            'read_at' => 'datetime',
            'ai_processed_at' => 'datetime',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function aiProvider()
    {
        return $this->belongsTo(AiProvider::class);
    }

    public function sentimentAnalysis()
    {
        return $this->hasMany(SentimentAnalysis::class);
    }

    public function mediaProcessingResults()
    {
        return $this->hasMany(MediaProcessingResult::class);
    }

    public function quotedMessage()
    {
        return $this->belongsTo(Message::class, 'quoted_message_id');
    }

    public function quotedBy()
    {
        return $this->hasMany(Message::class, 'quoted_message_id');
    }

    /**
     * Check if this message has media that can be processed
     */
    public function hasProcessableMedia(): bool
    {
        return in_array($this->message_type, ['image', 'audio', 'voice', 'video']);
    }

    /**
     * Get completed media processing text content
     */
    public function getMediaTextContent(): ?string
    {
        return $this->metadata['media_text'] ?? null;
    }

    /**
     * Check if media has been processed
     */
    public function isMediaProcessed(): bool
    {
        return !empty($this->metadata['media_processed']);
    }
}
