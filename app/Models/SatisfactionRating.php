<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SatisfactionRating extends Model
{
    protected $fillable = [
        'company_id',
        'conversation_id',
        'customer_id',
        'rating',
        'feedback',
        'rated_at',
    ];

    protected function casts(): array
    {
        return [
            'rated_at' => 'datetime',
        ];
    }

    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
