<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class KnowledgeBase extends Model
{
    use HasFactory;

    protected $table = 'knowledge_base';

    protected $fillable = [
        'company_id',
        'title',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'content',
        'category',
        'priority',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function embeddings()
    {
        return $this->hasMany(KnowledgeBaseEmbedding::class);
    }
}
