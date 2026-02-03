<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiConfiguration extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'primary_provider_id',
        'fallback_provider_id',
        'primary_model',
        'system_prompt',
        'personality_tone',
        'prohibited_topics',
        'custom_instructions',
        'confidence_threshold',
        'auto_respond',
        'response_delay_seconds',
        'max_tokens',
        'temperature',
    ];

    protected function casts(): array
    {
        return [
            'prohibited_topics' => 'array',
            'custom_instructions' => 'array',
            'auto_respond' => 'boolean',
            'temperature' => 'decimal:2',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function primaryProvider()
    {
        return $this->belongsTo(AiProvider::class, 'primary_provider_id');
    }

    public function fallbackProvider()
    {
        return $this->belongsTo(AiProvider::class, 'fallback_provider_id');
    }
}
