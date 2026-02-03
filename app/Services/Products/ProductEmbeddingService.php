<?php

namespace App\Services\Products;

use App\Models\Product;
use App\Models\ProductEmbedding;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProductEmbeddingService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.openai.com/v1';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        $this->model = config('products.embedding_model', 'text-embedding-3-small');
    }

    /**
     * Generate embeddings for a product
     */
    public function generateEmbeddings(Product $product): bool
    {
        if (!$this->apiKey) {
            Log::warning('ProductEmbeddingService: OpenAI API key not configured');
            return false;
        }

        try {
            // Delete existing embeddings
            $product->embeddings()->delete();

            // Get the text to embed
            $text = $product->embedding_text;

            if (empty(trim($text))) {
                Log::warning('ProductEmbeddingService: No text to embed', [
                    'product_id' => $product->id,
                ]);
                return false;
            }

            // Generate embedding
            $embedding = $this->createEmbedding($text);

            if (!$embedding) {
                return false;
            }

            // Store the embedding
            ProductEmbedding::create([
                'product_id' => $product->id,
                'chunk_text' => $text,
                'embedding' => $embedding,
                'embedding_model' => $this->model,
                'chunk_index' => 0,
            ]);

            Log::info('ProductEmbeddingService: Embedding generated', [
                'product_id' => $product->id,
                'text_length' => strlen($text),
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('ProductEmbeddingService: Failed to generate embedding', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Create embedding vector using OpenAI API
     */
    public function createEmbedding(string $text): ?array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
            ])->timeout(30)->post("{$this->baseUrl}/embeddings", [
                'model' => $this->model,
                'input' => $text,
            ]);

            if (!$response->successful()) {
                Log::error('ProductEmbeddingService: OpenAI API error', [
                    'status' => $response->status(),
                    'error' => $response->json('error'),
                ]);
                return null;
            }

            $data = $response->json();
            return $data['data'][0]['embedding'] ?? null;
        } catch (\Exception $e) {
            Log::error('ProductEmbeddingService: API call failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Regenerate embeddings for all active products of a company
     */
    public function regenerateAllForCompany(int $companyId): int
    {
        $products = Product::where('company_id', $companyId)
            ->where('is_active', true)
            ->get();

        $count = 0;
        foreach ($products as $product) {
            if ($this->generateEmbeddings($product)) {
                $count++;
            }
            // Rate limiting - 1 request per 100ms
            usleep(100000);
        }

        return $count;
    }
}
