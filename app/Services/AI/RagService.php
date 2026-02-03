<?php

namespace App\Services\AI;

use App\Models\Company;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseEmbedding;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RagService
{
    protected string $openAiApiKey;
    protected string $embeddingModel = 'text-embedding-3-small';
    
    public function __construct()
    {
        $this->openAiApiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
    }
    
    /**
     * Get relevant context for a user query using RAG
     *
     * @param Company $company
     * @param string $query
     * @param int $topK
     * @param array|null $knowledgeBaseIds Phase 2.1: Optional filter to search only specific KBs (personality scoping)
     */
    public function getRelevantContext(Company $company, string $query, int $topK = 5, ?array $knowledgeBaseIds = null): array
    {
        // Step 1: Embed the user query
        $queryEmbedding = $this->embedText($query);

        if (empty($queryEmbedding)) {
            Log::warning('RAG: Failed to embed query, falling back to keyword search');
            return $this->keywordSearch($company, $query, $topK, $knowledgeBaseIds);
        }

        // Step 2: Search knowledge base using vector similarity
        $relevantChunks = $this->vectorSearch($company, $queryEmbedding, $topK, $knowledgeBaseIds);

        // If no embeddings found, fall back to full content
        if (empty($relevantChunks)) {
            Log::info('RAG: No embeddings found, using full KB content');
            return $this->getFullKnowledgeBase($company, $knowledgeBaseIds);
        }

        Log::info('RAG: Found relevant chunks', [
            'query' => $query,
            'chunk_count' => count($relevantChunks),
            'kb_scoped' => $knowledgeBaseIds !== null,
        ]);

        return $relevantChunks;
    }
    
    /**
     * Embed text using OpenAI text-embedding-3-small
     */
    public function embedText(string $text): array
    {
        if (empty($this->openAiApiKey)) {
            Log::warning('RAG: OpenAI API key not configured');
            return [];
        }
        
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->openAiApiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/embeddings', [
                'model' => $this->embeddingModel,
                'input' => $text,
            ]);
            
            if (!$response->successful()) {
                Log::error('RAG: Embedding API error', [
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);
                return [];
            }
            
            return $response->json('data.0.embedding', []);
        } catch (\Exception $e) {
            Log::error('RAG: Embedding failed', ['error' => $e->getMessage()]);
            return [];
        }
    }
    
    /**
     * Vector similarity search using cosine similarity
     *
     * @param Company $company
     * @param array $queryEmbedding
     * @param int $topK
     * @param array|null $knowledgeBaseIds Phase 2.1: Optional filter for KB scoping
     */
    protected function vectorSearch(Company $company, array $queryEmbedding, int $topK, ?array $knowledgeBaseIds = null): array
    {
        // Get all embeddings for this company's knowledge base
        $embeddings = KnowledgeBaseEmbedding::whereHas('knowledgeBase', function ($q) use ($company, $knowledgeBaseIds) {
            $q->where('company_id', $company->id)->where('is_active', true);
            // Phase 2.1: Apply KB scoping if specified
            if ($knowledgeBaseIds !== null) {
                $q->whereIn('id', $knowledgeBaseIds);
            }
        })->get();
        
        if ($embeddings->isEmpty()) {
            return [];
        }
        
        // Calculate similarity scores
        $scored = $embeddings->map(function ($embedding) use ($queryEmbedding) {
            $embeddingData = $embedding->embedding_data;
            
            if (empty($embeddingData)) {
                return null;
            }
            
            // Ensure embedding data is an array
            if (is_string($embeddingData)) {
                $embeddingData = json_decode($embeddingData, true);
            }
            
            $similarity = $this->cosineSimilarity($queryEmbedding, $embeddingData);
            
            return [
                'chunk_text' => $embedding->chunk_text,
                'knowledge_base_id' => $embedding->knowledge_base_id,
                'title' => $embedding->knowledgeBase->title ?? 'Unknown',
                'category' => $embedding->knowledgeBase->category ?? null,
                'similarity' => $similarity,
            ];
        })->filter()->sortByDesc('similarity')->take($topK)->values();
        
        return $scored->toArray();
    }
    
    /**
     * Calculate cosine similarity between two vectors
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }
        
        $dotProduct = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        
        for ($i = 0; $i < count($a); $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }
        
        $normA = sqrt($normA);
        $normB = sqrt($normB);
        
        if ($normA == 0 || $normB == 0) {
            return 0.0;
        }
        
        return $dotProduct / ($normA * $normB);
    }
    
    /**
     * Fallback: Simple keyword search
     *
     * @param Company $company
     * @param string $query
     * @param int $limit
     * @param array|null $knowledgeBaseIds Phase 2.1: Optional filter for KB scoping
     */
    protected function keywordSearch(Company $company, string $query, int $limit, ?array $knowledgeBaseIds = null): array
    {
        $keywords = array_filter(explode(' ', strtolower($query)));

        $queryBuilder = KnowledgeBase::where('company_id', $company->id)
            ->where('is_active', true);

        // Phase 2.1: Apply KB scoping if specified
        if ($knowledgeBaseIds !== null) {
            $queryBuilder->whereIn('id', $knowledgeBaseIds);
        }

        $results = $queryBuilder->where(function ($q) use ($keywords) {
                foreach ($keywords as $keyword) {
                    if (strlen($keyword) > 2) {
                        $q->orWhere('content', 'like', "%{$keyword}%")
                          ->orWhere('title', 'like', "%{$keyword}%");
                    }
                }
            })
            ->orderBy('priority', 'desc')
            ->limit($limit)
            ->get();

        return $results->map(function ($kb) {
            return [
                'chunk_text' => $kb->content,
                'title' => $kb->title,
                'category' => $kb->category,
                'similarity' => null,
            ];
        })->toArray();
    }
    
    /**
     * Get all knowledge base content (fallback when no embeddings)
     *
     * @param Company $company
     * @param array|null $knowledgeBaseIds Phase 2.1: Optional filter for KB scoping
     */
    protected function getFullKnowledgeBase(Company $company, ?array $knowledgeBaseIds = null): array
    {
        $queryBuilder = KnowledgeBase::where('company_id', $company->id)
            ->where('is_active', true);

        // Phase 2.1: Apply KB scoping if specified
        if ($knowledgeBaseIds !== null) {
            $queryBuilder->whereIn('id', $knowledgeBaseIds);
        }

        return $queryBuilder->orderBy('priority', 'desc')
            ->get()
            ->map(function ($kb) {
                return [
                    'chunk_text' => $kb->content,
                    'title' => $kb->title,
                    'category' => $kb->category,
                    'similarity' => null,
                ];
            })
            ->toArray();
    }
    
    /**
     * Create embeddings for a knowledge base entry
     * Call this when new KB is added/updated
     */
    public function embedKnowledgeBase(KnowledgeBase $knowledgeBase): bool
    {
        if (empty($knowledgeBase->content)) {
            return false;
        }
        
        // Delete existing embeddings
        $knowledgeBase->embeddings()->delete();
        
        // Split content into chunks (roughly 500 tokens each)
        $chunks = $this->chunkText($knowledgeBase->content, 1500);
        
        foreach ($chunks as $index => $chunk) {
            $embedding = $this->embedText($chunk);
            
            if (!empty($embedding)) {
                KnowledgeBaseEmbedding::create([
                    'knowledge_base_id' => $knowledgeBase->id,
                    'chunk_text' => $chunk,
                    'chunk_index' => $index,
                    'embedding_data' => $embedding,
                ]);
            }
        }
        
        Log::info('RAG: Knowledge base embedded', [
            'kb_id' => $knowledgeBase->id,
            'title' => $knowledgeBase->title,
            'chunks' => count($chunks),
        ]);
        
        return true;
    }
    
    /**
     * Split text into chunks
     */
    protected function chunkText(string $text, int $maxChars = 1500): array
    {
        // Split by paragraphs first
        $paragraphs = preg_split('/\n\s*\n/', $text);
        $chunks = [];
        $currentChunk = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) continue;
            
            // If adding this paragraph exceeds max, save current chunk
            if (strlen($currentChunk) + strlen($paragraph) > $maxChars && !empty($currentChunk)) {
                $chunks[] = trim($currentChunk);
                $currentChunk = '';
            }
            
            // If single paragraph is too long, split by sentences
            if (strlen($paragraph) > $maxChars) {
                $sentences = preg_split('/(?<=[.!?])\s+/', $paragraph);
                foreach ($sentences as $sentence) {
                    if (strlen($currentChunk) + strlen($sentence) > $maxChars && !empty($currentChunk)) {
                        $chunks[] = trim($currentChunk);
                        $currentChunk = '';
                    }
                    $currentChunk .= $sentence . ' ';
                }
            } else {
                $currentChunk .= $paragraph . "\n\n";
            }
        }
        
        if (!empty(trim($currentChunk))) {
            $chunks[] = trim($currentChunk);
        }
        
        return $chunks;
    }
}
