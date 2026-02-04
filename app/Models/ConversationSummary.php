<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationSummary extends Model
{
    protected $fillable = [
        'conversation_id',
        'summary',
        'key_points',
        'action_items',
        'keywords',
        'last_request',
        'resolution',
        'generated_by',
        'is_ai_generated',
    ];

    protected function casts(): array
    {
        return [
            'key_points' => 'array',
            'action_items' => 'array',
            'keywords' => 'array',
            'is_ai_generated' => 'boolean',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
