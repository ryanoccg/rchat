<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KnowledgeBaseEmbedding extends Model
{
    protected $fillable = [
        'knowledge_base_id',
        'chunk_text',
        'chunk_index',
        'embedding_data',
    ];

    protected function casts(): array
    {
        return [
            'embedding_data' => 'array',
        ];
    }

    public function knowledgeBase()
    {
        return $this->belongsTo(KnowledgeBase::class);
    }
}
