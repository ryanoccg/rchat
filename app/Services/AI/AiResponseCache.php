<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use App\Services\AI\Contracts\AiResponseInterface;

class AiResponseCache
{
    protected const CACHE_TTL = 3600; // 1 hour
    protected const SIMILARITY_THRESHOLD = 0.85;

    /**
     * Generate cache key for a message
     * Include conversation ID to prevent cross-conversation cache pollution
     */
    public static function getCacheKey(int $companyId, string $message, array $knowledgeIds = [], ?int $conversationId = null): string
    {
        // Normalize the message for better cache hits
        $normalized = self::normalizeMessage($message);
        $knowledgeHash = md5(implode(',', $knowledgeIds));

        // Include conversation ID to ensure cache is per-conversation
        // This prevents workflow default prompts from being cached globally
        $conversationSuffix = $conversationId ? ":{$conversationId}" : '';

        return "ai_response:{$companyId}:" . md5($normalized . $knowledgeHash) . $conversationSuffix;
    }

    /**
     * Normalize message for caching (remove extra spaces, lowercase, etc.)
     * Support multi-language including Chinese, Malay, Tamil, etc.
     */
    protected static function normalizeMessage(string $message): string
    {
        $message = mb_strtolower(trim($message), 'UTF-8');
        $message = preg_replace('/\s+/u', ' ', $message); // Unicode-aware whitespace normalization
        // Keep all unicode word characters and spaces - do NOT remove Chinese/non-English chars
        $message = preg_replace('/[^\p{L}\p{N}\s]/u', '', $message); // Keep letters, numbers, spaces (Unicode-safe)
        
        return $message;
    }

    /**
     * Try to get cached response
     */
    public static function get(int $companyId, string $message, array $knowledgeIds = [], ?int $conversationId = null): ?AiResponseInterface
    {
        $key = self::getCacheKey($companyId, $message, $knowledgeIds, $conversationId);
        $cached = Cache::get($key);

        if ($cached) {
            \Log::channel('ai')->info('AI Response Cache HIT', ['key' => $key]);
            return AiResponse::success(
                content: $cached['content'],
                model: $cached['model'] . ' (cached)',
                usage: ['cached' => true, 'original_tokens' => $cached['tokens'] ?? 0],
            );
        }

        return null;
    }

    /**
     * Store response in cache
     */
    public static function put(
        int $companyId,
        string $message,
        AiResponseInterface $response,
        array $knowledgeIds = [],
        int $ttl = null,
        ?int $conversationId = null
    ): void {
        if (!$response->isSuccessful()) {
            return;
        }

        $key = self::getCacheKey($companyId, $message, $knowledgeIds, $conversationId);

        Cache::put($key, [
            'content' => $response->getContent(),
            'model' => $response->getModel(),
            'tokens' => $response->getUsage()['total_tokens'] ?? 0,
            'cached_at' => now()->toISOString(),
        ], $ttl ?? self::CACHE_TTL);

        \Log::channel('ai')->info('AI Response cached', ['key' => $key]);
    }

    /**
     * Get common FAQ responses (pre-cached)
     */
    public static function getCommonResponse(int $companyId, string $message): ?string
    {
        $normalized = strtolower(trim($message));
        
        // Check against common patterns that don't need AI
        $patterns = Cache::get("company:{$companyId}:faq_patterns", []);
        
        foreach ($patterns as $pattern => $response) {
            if (str_contains($normalized, $pattern)) {
                \Log::channel('ai')->info('FAQ pattern matched', ['pattern' => $pattern]);
                return $response;
            }
        }
        
        return null;
    }

    /**
     * Clear cache for a company (when knowledge base updates)
     */
    public static function clearCompanyCache(int $companyId): void
    {
        // In production, use tagged caching or pattern deletion
        Cache::forget("company:{$companyId}:faq_patterns");
        \Log::channel('ai')->info('Company AI cache cleared', ['company_id' => $companyId]);
    }
}
