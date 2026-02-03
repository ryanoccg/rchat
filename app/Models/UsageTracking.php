<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsageTracking extends Model
{
    protected $table = 'usage_tracking';

    protected $fillable = [
        'company_id',
        'period_date',
        'message_count',
        'ai_requests',
        'storage_used',
        'api_cost',
        'breakdown',
    ];

    protected function casts(): array
    {
        return [
            'period_date' => 'date',
            'breakdown' => 'array',
            'api_cost' => 'decimal:4',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
