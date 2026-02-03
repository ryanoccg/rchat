<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AiProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'display_name',
        'description',
        'available_models',
        'capabilities',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'available_models' => 'array',
            'capabilities' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function primaryConfigurations()
    {
        return $this->hasMany(AiConfiguration::class, 'primary_provider_id');
    }

    public function fallbackConfigurations()
    {
        return $this->hasMany(AiConfiguration::class, 'fallback_provider_id');
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }
}
