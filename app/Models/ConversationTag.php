<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConversationTag extends Model
{
    protected $fillable = [
        'conversation_id',
        'tag',
        'category',
        'confidence',
        'is_ai_generated',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'decimal:2',
            'is_ai_generated' => 'boolean',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }
}
