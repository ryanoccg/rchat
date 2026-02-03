<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Notification extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'company_id',
        'type',
        'title',
        'message',
        'data',
        'action_url',
        'link',
        'conversation_id',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the link for the notification (alias for action_url)
     */
    protected function link(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->action_url,
        );
    }

    /**
     * Get the conversation_id from data if exists
     */
    protected function conversationId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->data['conversation_id'] ?? null,
        );
    }
}
