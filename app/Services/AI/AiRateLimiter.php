<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Services\AI\Contracts\AiResponseInterface;

class AiRateLimiter
{
    /**
     * Rate limits per provider (requests per day)
     * Free tier limits - adjust based on your plan
     */
    protected const PROVIDER_LIMITS = [
        'gemini' => [
            'gemini-2.5-flash-lite' => 500,      // Preview model
            'gemini-2.5-flash' => 500,           // Preview model  
            'gemini-2.0-flash' => 1500,
            'gemini-2.0-flash-exp' => 1500,
            'gemini-1.5-flash' => 1500,
            'gemini-1.5-pro' => 50,
            'gemini-pro' => 1500,
        ],
        'openai' => [
            'gpt-4o' => 10000,           // Paid tier
            'gpt-4o-mini' => 10000,      // Paid tier
            'gpt-4-turbo' => 10000,
            'gpt-3.5-turbo' => 10000,
        ],
        'claude' => [
            'claude-3-5-sonnet' => 1000,
            'claude-3-5-haiku' => 1000,
            'claude-3-opus' => 1000,
            'claude-3-sonnet' => 1000,
            'claude-3-haiku' => 1000,
        ],
    ];

    /**
     * Check if we can make a request (within rate limit)
     */
    public static function canMakeRequest(string $provider, string $model): bool
    {
        $key = self::getRateLimitKey($provider, $model);
        $limit = self::getLimit($provider, $model);
        $current = Cache::get($key, 0);

        return $current < $limit;
    }

    /**
     * Record a request
     */
    public static function recordRequest(string $provider, string $model): void
    {
        $key = self::getRateLimitKey($provider, $model);
        $current = Cache::get($key, 0);
        
        // Cache until end of day (UTC)
        $ttl = now()->endOfDay()->diffInSeconds(now());
        Cache::put($key, $current + 1, $ttl);

        Log::debug('AI request recorded', [
            'provider' => $provider,
            'model' => $model,
            'count' => $current + 1,
            'limit' => self::getLimit($provider, $model),
        ]);
    }

    /**
     * Get current usage for a provider/model
     */
    public static function getUsage(string $provider, string $model): array
    {
        $key = self::getRateLimitKey($provider, $model);
        $current = Cache::get($key, 0);
        $limit = self::getLimit($provider, $model);

        return [
            'current' => $current,
            'limit' => $limit,
            'remaining' => max(0, $limit - $current),
            'percentage' => $limit > 0 ? round(($current / $limit) * 100, 1) : 0,
            'resets_at' => now()->endOfDay()->toISOString(),
        ];
    }

    /**
     * Get all usage stats for a provider
     */
    public static function getAllUsage(string $provider): array
    {
        $models = self::PROVIDER_LIMITS[$provider] ?? [];
        $usage = [];

        foreach ($models as $model => $limit) {
            $usage[$model] = self::getUsage($provider, $model);
        }

        return $usage;
    }

    /**
     * Get the rate limit for a provider/model
     */
    protected static function getLimit(string $provider, string $model): int
    {
        return self::PROVIDER_LIMITS[$provider][$model] ?? 100;
    }

    /**
     * Get cache key for rate limiting
     */
    protected static function getRateLimitKey(string $provider, string $model): string
    {
        $date = now()->format('Y-m-d');
        return "ai_rate_limit:{$provider}:{$model}:{$date}";
    }

    /**
     * Create a rate-limited response when limit exceeded
     */
    public static function rateLimitedResponse(string $provider, string $model): AiResponseInterface
    {
        $usage = self::getUsage($provider, $model);
        
        Log::warning('AI rate limit exceeded', [
            'provider' => $provider,
            'model' => $model,
            'usage' => $usage,
        ]);

        return AiResponse::error(
            "Rate limit exceeded for {$provider}/{$model}. " .
            "Used {$usage['current']}/{$usage['limit']} requests today. " .
            "Resets at {$usage['resets_at']}",
            ['rate_limit_exceeded' => true, 'usage' => $usage]
        );
    }

    /**
     * Get recommended alternative when rate limited
     */
    public static function getAlternativeModel(string $provider, string $model): ?array
    {
        // Suggest alternatives with higher limits
        $alternatives = [
            'gemini' => [
                'gemini-2.5-flash-lite' => ['gemini-2.0-flash', 'gemini-1.5-flash'],
                'gemini-2.5-flash' => ['gemini-2.0-flash', 'gemini-1.5-flash'],
                'gemini-1.5-pro' => ['gemini-1.5-flash', 'gemini-2.0-flash'],
            ],
        ];

        $modelAlternatives = $alternatives[$provider][$model] ?? [];

        foreach ($modelAlternatives as $alt) {
            if (self::canMakeRequest($provider, $alt)) {
                return ['provider' => $provider, 'model' => $alt];
            }
        }

        return null;
    }
}
