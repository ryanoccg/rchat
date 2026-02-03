<?php

namespace App\Services\Products;

use App\Models\Company;
use App\Models\Product;
use App\Models\ProductEmbedding;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ProductRagService
{
    protected ProductEmbeddingService $embeddingService;

    public function __construct(?ProductEmbeddingService $embeddingService = null)
    {
        $this->embeddingService = $embeddingService ?? new ProductEmbeddingService();
    }

    /**
     * Search products using hybrid approach (structured + semantic)
     *
     * @param Company $company
     * @param string $query Customer's query
     * @param array $filters Optional structured filters (category_id, min_price, max_price, etc.)
     * @param int $limit Maximum number of results
     * @return array Array of product data with relevance scores
     */
    public function searchProducts(Company $company, string $query, array $filters = [], int $limit = 5): array
    {
        Log::info('ProductRagService: Searching products', [
            'company_id' => $company->id,
            'query' => $query,
            'filters' => $filters,
        ]);

        // Step 1: Apply structured filters first
        $baseQuery = Product::where('company_id', $company->id)
            ->where('is_active', true);

        // Apply optional filters
        if (!empty($filters['category_id'])) {
            $baseQuery->where('category_id', $filters['category_id']);
        }
        if (isset($filters['min_price'])) {
            $baseQuery->where('price', '>=', $filters['min_price']);
        }
        if (isset($filters['max_price'])) {
            $baseQuery->where('price', '<=', $filters['max_price']);
        }
        if (!empty($filters['stock_status'])) {
            $baseQuery->where('stock_status', $filters['stock_status']);
        }
        if (!empty($filters['brand'])) {
            $baseQuery->where('brand', 'like', "%{$filters['brand']}%");
        }

        // Step 2: Try semantic search first
        $semanticResults = $this->semanticSearch($company, $query, $limit * 2);

        // Step 3: If semantic search has results, filter and rank them
        if (!empty($semanticResults)) {
            $productIds = array_column($semanticResults, 'product_id');
            $products = $baseQuery->whereIn('id', $productIds)
                ->with('category')
                ->get()
                ->keyBy('id');

            // Combine with semantic scores
            $results = [];
            foreach ($semanticResults as $semantic) {
                $productId = $semantic['product_id'];
                if (isset($products[$productId])) {
                    $product = $products[$productId];
                    $results[] = $this->formatProductResult($product, $semantic['score'], 'semantic');
                }
            }

            // Sort by score and limit
            usort($results, fn($a, $b) => $b['relevance_score'] <=> $a['relevance_score']);
            $results = array_slice($results, 0, $limit);

            if (!empty($results)) {
                Log::info('ProductRagService: Semantic search results', [
                    'count' => count($results),
                ]);
                return $results;
            }
        }

        // Step 4: Fallback to keyword search
        $keywordResults = $this->keywordSearch($baseQuery, $query, $limit);

        Log::info('ProductRagService: Keyword search results', [
            'count' => count($keywordResults),
        ]);

        return $keywordResults;
    }

    /**
     * Semantic search using embeddings
     */
    protected function semanticSearch(Company $company, string $query, int $limit): array
    {
        // Generate embedding for the query
        $queryEmbedding = $this->embeddingService->createEmbedding($query);

        if (!$queryEmbedding) {
            Log::warning('ProductRagService: Failed to create query embedding');
            return [];
        }

        // Get all product embeddings for this company
        $embeddings = ProductEmbedding::whereHas('product', function ($q) use ($company) {
            $q->where('company_id', $company->id)
              ->where('is_active', true);
        })->get();

        if ($embeddings->isEmpty()) {
            return [];
        }

        // Calculate similarities and group by product_id (keep best score per product)
        $productScores = [];
        foreach ($embeddings as $embedding) {
            $similarity = $embedding->cosineSimilarity($queryEmbedding);
            $productId = $embedding->product_id;

            // Keep the highest score for each product
            if (!isset($productScores[$productId]) || $similarity > $productScores[$productId]['score']) {
                $productScores[$productId] = [
                    'product_id' => $productId,
                    'score' => $similarity,
                    'chunk_text' => $embedding->chunk_text,
                ];
            }
        }

        // Convert to array and sort by similarity score (descending)
        $results = array_values($productScores);
        usort($results, fn($a, $b) => $b['score'] <=> $a['score']);

        // Filter by minimum threshold and limit
        $minThreshold = 0.3;
        $results = array_filter($results, fn($r) => $r['score'] >= $minThreshold);
        $results = array_slice($results, 0, $limit);

        Log::info('ProductRagService: Semantic search found unique products', [
            'total_embeddings' => count($embeddings),
            'unique_products' => count($productScores),
            'above_threshold' => count($results),
        ]);

        return $results;
    }

    /**
     * Keyword-based search fallback
     */
    protected function keywordSearch($baseQuery, string $query, int $limit): array
    {
        $keywords = array_filter(explode(' ', strtolower($query)), fn($w) => strlen($w) > 2);

        if (empty($keywords)) {
            // Return featured products if no keywords, or all active products if none featured
            $featured = $baseQuery->clone()->where('is_featured', true)
                ->with('category')
                ->take($limit)
                ->get();

            if ($featured->isEmpty()) {
                // Fallback to all active products
                $featured = $baseQuery->with('category')
                    ->take($limit)
                    ->get();
            }

            return $featured->map(fn($p) => $this->formatProductResult($p, 0.5, 'featured'))
                ->toArray();
        }

        // Search across name, description, and brand for any matching keyword
        $products = $baseQuery->where(function ($q) use ($keywords) {
            foreach ($keywords as $keyword) {
                $q->orWhere('name', 'like', "%{$keyword}%")
                  ->orWhere('description', 'like', "%{$keyword}%")
                  ->orWhere('short_description', 'like', "%{$keyword}%")
                  ->orWhere('brand', 'like', "%{$keyword}%")
                  ->orWhereJsonContains('tags', $keyword);
            }
        })
        ->with('category')
        ->take($limit)
        ->get();

        // If keyword search doesn't find enough, also try matching the full query
        if ($products->count() < $limit) {
            $fullQueryProducts = $baseQuery->clone()
                ->where(function ($q) use ($query) {
                    $q->where('name', 'like', "%{$query}%")
                      ->orWhere('description', 'like', "%{$query}%")
                      ->orWhere('short_description', 'like', "%{$query}%");
                })
                ->whereNotIn('id', $products->pluck('id')->toArray())
                ->with('category')
                ->take($limit - $products->count())
                ->get();

            $products = $products->concat($fullQueryProducts);
        }

        Log::info('ProductRagService: Keyword search results', [
            'query' => $query,
            'keywords' => $keywords,
            'products_found' => $products->count(),
        ]);

        return $products->map(fn($p) => $this->formatProductResult($p, 0.6, 'keyword'))
            ->toArray();
    }

    /**
     * Search products based on image description
     */
    public function searchByImageDescription(Company $company, string $imageDescription, int $limit = 5): array
    {
        Log::info('ProductRagService: Image-based product search', [
            'company_id' => $company->id,
            'description' => $imageDescription,
        ]);

        return $this->searchProducts($company, $imageDescription, [], $limit);
    }

    /**
     * Extract structured filters from natural language query
     */
    public function extractFiltersFromQuery(string $query): array
    {
        $filters = [];

        // Extract price range (e.g., "under $50", "less than 100", "between 20 and 50")
        if (preg_match('/under\s*\$?(\d+)/i', $query, $matches)) {
            $filters['max_price'] = (float) $matches[1];
        }
        if (preg_match('/less than\s*\$?(\d+)/i', $query, $matches)) {
            $filters['max_price'] = (float) $matches[1];
        }
        if (preg_match('/over\s*\$?(\d+)/i', $query, $matches)) {
            $filters['min_price'] = (float) $matches[1];
        }
        if (preg_match('/more than\s*\$?(\d+)/i', $query, $matches)) {
            $filters['min_price'] = (float) $matches[1];
        }
        if (preg_match('/between\s*\$?(\d+)\s*and\s*\$?(\d+)/i', $query, $matches)) {
            $filters['min_price'] = (float) $matches[1];
            $filters['max_price'] = (float) $matches[2];
        }

        // Extract stock preference
        if (preg_match('/in stock|available/i', $query)) {
            $filters['stock_status'] = 'in_stock';
        }

        return $filters;
    }

    /**
     * Format product data for AI context
     */
    protected function formatProductResult(Product $product, float $score, string $matchType): array
    {
        // Ensure image URL is absolute
        $imageUrl = $product->primary_image;
        if ($imageUrl && str_starts_with($imageUrl, '/')) {
            $imageUrl = rtrim(url('/'), '/') . $imageUrl;
        }

        return [
            'product_id' => $product->id,
            'name' => $product->name,
            'description' => $product->short_description ?? substr($product->description ?? '', 0, 200),
            'price' => $product->price,
            'sale_price' => $product->sale_price,
            'formatted_price' => $product->formatted_price,
            'currency' => $product->currency,
            'brand' => $product->brand,
            'category' => $product->category?->name,
            'stock_status' => $product->stock_status,
            'is_on_sale' => $product->is_on_sale,
            'discount_percentage' => $product->discount_percentage,
            'image' => $imageUrl,
            'specifications' => $product->specifications,
            'relevance_score' => $score,
            'match_type' => $matchType,
        ];
    }

    /**
     * Format products for AI context string
     */
    public function formatProductsForContext(array $products): string
    {
        if (empty($products)) {
            return '';
        }

        $context = "# Available Products\n\n";

        foreach ($products as $index => $product) {
            $context .= "## " . ($index + 1) . ". {$product['name']}\n";
            $context .= "- Price: {$product['formatted_price']}";
            if ($product['is_on_sale']) {
                $context .= " (was {$product['currency']} {$product['price']}, {$product['discount_percentage']}% off)";
            }
            $context .= "\n";

            if ($product['brand']) {
                $context .= "- Brand: {$product['brand']}\n";
            }
            if ($product['category']) {
                $context .= "- Category: {$product['category']}\n";
            }
            $context .= "- Availability: " . str_replace('_', ' ', ucfirst($product['stock_status'])) . "\n";

            if ($product['description']) {
                $context .= "- Description: {$product['description']}\n";
            }

            if (!empty($product['specifications'])) {
                $specs = [];
                foreach ($product['specifications'] as $key => $value) {
                    $specs[] = "{$key}: {$value}";
                }
                $context .= "- Specs: " . implode(', ', $specs) . "\n";
            }

            // Include full image URL for the AI to reference in responses
            if (!empty($product['image'])) {
                $imageUrl = $product['image'];
                if (str_starts_with($imageUrl, '/')) {
                    $imageUrl = rtrim(url('/'), '/') . $imageUrl;
                }
                $context .= "- Image: {$imageUrl}\n";
            }

            $context .= "\n";
        }

        return $context;
    }

    /**
     * Get products with their images for AI response
     * This can be used to include product cards in responses
     */
    public function getProductsWithImages(array $products): array
    {
        return array_map(function ($product) {
            return [
                'id' => $product['product_id'],
                'name' => $product['name'],
                'price' => $product['formatted_price'],
                'image' => $product['image'],
                'description' => $product['description'],
                'stock_status' => $product['stock_status'],
                'category' => $product['category'],
            ];
        }, $products);
    }
}
