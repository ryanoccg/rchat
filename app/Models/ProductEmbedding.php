<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEmbedding extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'chunk_text',
        'embedding',
        'embedding_model',
        'chunk_index',
    ];

    protected function casts(): array
    {
        return [
            'embedding' => 'array',
            'chunk_index' => 'integer',
        ];
    }

    /**
     * Get the product this embedding belongs to
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Calculate cosine similarity with another embedding vector
     */
    public function cosineSimilarity(array $otherEmbedding): float
    {
        $embedding = $this->embedding;

        if (count($embedding) !== count($otherEmbedding)) {
            return 0.0;
        }

        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < count($embedding); $i++) {
            $dotProduct += $embedding[$i] * $otherEmbedding[$i];
            $normA += $embedding[$i] * $embedding[$i];
            $normB += $otherEmbedding[$i] * $otherEmbedding[$i];
        }

        $normA = sqrt($normA);
        $normB = sqrt($normB);

        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }

        return $dotProduct / ($normA * $normB);
    }
}
