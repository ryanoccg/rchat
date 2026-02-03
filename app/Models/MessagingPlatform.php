<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class MessagingPlatform extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'display_name',
        'description',
        'icon',
        'config_fields',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'config_fields' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function platformConnections()
    {
        return $this->hasMany(PlatformConnection::class);
    }
}
