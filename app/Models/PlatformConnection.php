<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PlatformConnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id',
        'messaging_platform_id',
        'platform_account_id',
        'platform_account_name',
        'credentials',
        'webhook_config',
        'is_active',
        'connected_at',
        'last_sync_at',
    ];

    protected function casts(): array
    {
        return [
            'credentials' => 'array',
            'webhook_config' => 'array',
            'is_active' => 'boolean',
            'connected_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function messagingPlatform()
    {
        return $this->belongsTo(MessagingPlatform::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function conversations()
    {
        return $this->hasMany(Conversation::class);
    }
}
