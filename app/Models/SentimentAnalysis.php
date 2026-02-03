<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SentimentAnalysis extends Model
{
    protected $table = 'sentiment_analysis';

    protected $fillable = [
        'conversation_id',
        'message_id',
        'sentiment',
        'score',
        'emotions',
        'language',
    ];

    protected function casts(): array
    {
        return [
            'emotions' => 'array',
            'score' => 'decimal:2',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }
}
