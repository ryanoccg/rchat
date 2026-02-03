<?php

namespace App\Services\AI;

use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Models\Company;
use App\Services\AI\Contracts\AiProviderInterface;
use App\Services\AI\Providers\ClaudeProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\OpenAiProvider;
use InvalidArgumentException;

class AiServiceFactory
{
    /**
     * Available provider classes
     */
    protected static array $providers = [
        'openai' => OpenAiProvider::class,
        'gemini' => GeminiProvider::class,
        'claude' => ClaudeProvider::class,
    ];

    /**
     * Create an AI provider instance from a configuration
     */
    public static function fromConfiguration(AiConfiguration $config): AiProviderInterface
    {
        $provider = $config->primaryProvider;

        if (!$provider) {
            throw new InvalidArgumentException('No primary provider configured');
        }

        return self::make($provider->slug, $config);
    }

    /**
     * Create an AI provider instance from provider ID and company
     * Used for AI Agents
     */
    public static function fromProviderId(int $providerId, Company $company): AiProviderInterface
    {
        $provider = AiProvider::findOrFail($providerId);

        // Create a minimal config-like object
        $config = new class ($company, $provider) {
            public function __construct(
                public Company $company,
                public AiProvider $provider
            ) {}

            public function __get($name)
            {
                return match($name) {
                    'primaryProvider' => $this->provider,
                    default => null,
                };
            }
        };

        return self::make($provider->slug, $config);
    }

    /**
     * Create an AI provider instance by slug/name
     */
    /**
     * @param string $providerSlug
     * @param mixed $config AiConfiguration|object|null
     */
    public static function make(string $providerSlug, $config = null): AiProviderInterface
    {
        if (!isset(self::$providers[$providerSlug])) {
            throw new InvalidArgumentException("Unknown AI provider: {$providerSlug}");
        }

        $providerClass = self::$providers[$providerSlug];

        return new $providerClass($config);
    }

    /**
     * Create the fallback provider from a configuration
     */
    public static function makeFallback(AiConfiguration $config): ?AiProviderInterface
    {
        $provider = $config->fallbackProvider;

        if (!$provider) {
            return null;
        }

        return self::make($provider->slug, $config);
    }

    /**
     * Get a list of all available providers
     */
    public static function getAvailableProviders(): array
    {
        return array_keys(self::$providers);
    }

    /**
     * Register a custom provider
     */
    public static function registerProvider(string $slug, string $class): void
    {
        if (!is_subclass_of($class, AiProviderInterface::class)) {
            throw new InvalidArgumentException("Provider class must implement AiProviderInterface");
        }

        self::$providers[$slug] = $class;
    }

    /**
     * Check if a provider is available
     */
    public static function hasProvider(string $slug): bool
    {
        return isset(self::$providers[$slug]);
    }
}
