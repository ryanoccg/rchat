<?php

namespace App\Services\AI;

use App\Services\AI\Contracts\AiProviderInterface;
use App\Services\AI\Contracts\AiResponseInterface;
use Illuminate\Support\Facades\Log;

abstract class AbstractAiProvider implements AiProviderInterface
{
    protected string $name;
    protected array $capabilities = [];
    protected array $availableModels = [];
    protected ?string $apiKey = null;
    protected string $defaultModel;

    public function __construct(
        protected mixed $config = null
    ) {
        $this->loadCredentials();
    }

    /**
     * Load API credentials from environment or configuration
     */
    abstract protected function loadCredentials(): void;

    /**
     * Make the actual API call to the provider
     */
    abstract protected function makeApiCall(array $messages, array $options): AiResponseInterface;

    public function sendMessage(string $message, array $context = [], array $options = []): AiResponseInterface
    {
        $mergedOptions = $this->mergeOptions($options);
        $model = $mergedOptions['model'] ?? $this->defaultModel;

        // Check rate limit before making request
        if (!AiRateLimiter::canMakeRequest($this->name, $model)) {
            // Try alternative model if available
            $alternative = AiRateLimiter::getAlternativeModel($this->name, $model);
            if ($alternative) {
                Log::info("Rate limit reached, switching to alternative model", [
                    'from' => $model,
                    'to' => $alternative['model'],
                ]);
                $mergedOptions['model'] = $alternative['model'];
                $model = $alternative['model'];
            } else {
                return AiRateLimiter::rateLimitedResponse($this->name, $model);
            }
        }

        $messages = $this->buildMessages($message, $context);
        
        $response = $this->executeWithErrorHandling(function () use ($messages, $mergedOptions) {
            return $this->makeApiCall($messages, $mergedOptions);
        });

        // Record successful request
        if ($response->isSuccessful()) {
            AiRateLimiter::recordRequest($this->name, $model);
        }

        return $response;
    }

    public function generateResponse(string $systemPrompt, string $userMessage, array $options = []): AiResponseInterface
    {
        $mergedOptions = $this->mergeOptions($options);
        $model = $mergedOptions['model'] ?? $this->defaultModel;

        // Check rate limit before making request
        if (!AiRateLimiter::canMakeRequest($this->name, $model)) {
            $alternative = AiRateLimiter::getAlternativeModel($this->name, $model);
            if ($alternative) {
                $mergedOptions['model'] = $alternative['model'];
                $model = $alternative['model'];
            } else {
                return AiRateLimiter::rateLimitedResponse($this->name, $model);
            }
        }

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userMessage],
        ];

        $response = $this->executeWithErrorHandling(function () use ($messages, $mergedOptions) {
            return $this->makeApiCall($messages, $mergedOptions);
        });

        if ($response->isSuccessful()) {
            AiRateLimiter::recordRequest($this->name, $model);
        }

        return $response;
    }

    public function supportsCapability(string $capability): bool
    {
        return in_array($capability, $this->capabilities);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getAvailableModels(): array
    {
        return $this->availableModels;
    }

    public function validateCredentials(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Build the messages array with context
     */
    protected function buildMessages(string $message, array $context): array
    {
        $messages = [];

        // Add system prompt from context (takes priority) or configuration
        if (!empty($context['system'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $context['system'],
            ];
        } elseif ($this->config && $this->config->system_prompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->buildSystemPrompt(),
            ];
        }

        // Add conversation history if provided
        if (!empty($context['history'])) {
            foreach ($context['history'] as $historyMessage) {
                $messages[] = [
                    'role' => $historyMessage['is_from_customer'] ? 'user' : 'assistant',
                    'content' => $historyMessage['content'],
                ];
            }
        }

        // Add knowledge base context if provided
        if (!empty($context['knowledge'])) {
            $knowledgeContext = "Relevant information from the knowledge base:\n\n";
            foreach ($context['knowledge'] as $knowledge) {
                $knowledgeContext .= "- {$knowledge['content']}\n";
            }
            $messages[] = [
                'role' => 'system',
                'content' => $knowledgeContext,
            ];
        }

        // Add the current user message
        $messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        return $messages;
    }

    /**
     * Build the system prompt from configuration
     */
    protected function buildSystemPrompt(): string
    {
        $prompt = $this->config->system_prompt ?? 'You are a helpful customer service assistant.';

        if ($this->config->personality_tone) {
            $prompt .= "\n\nTone: {$this->config->personality_tone}";
        }

        if (!empty($this->config->prohibited_topics)) {
            $topics = implode(', ', $this->config->prohibited_topics);
            $prompt .= "\n\nDo not discuss the following topics: {$topics}";
        }

        if (!empty($this->config->custom_instructions)) {
            foreach ($this->config->custom_instructions as $instruction) {
                $prompt .= "\n\n{$instruction}";
            }
        }

        return $prompt;
    }

    /**
     * Merge default options with provided options
     */
    protected function mergeOptions(array $options): array
    {
        $defaults = [
            'model' => $this->config?->primary_model ?? $this->defaultModel,
            'max_tokens' => $this->config?->max_tokens ?? 1024,
            'temperature' => $this->config?->temperature ?? 0.7,
        ];

        return array_merge($defaults, $options);
    }

    /**
     * Execute a function with error handling
     */
    protected function executeWithErrorHandling(callable $callback): AiResponseInterface
    {
        try {
            return $callback();
        } catch (\Exception $e) {
            Log::error("AI Provider Error [{$this->name}]: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            return AiResponse::error($e->getMessage());
        }
    }

    /**
     * Build messages array with vision support (images included in user messages)
     *
     * @param string $message The user message
     * @param array $context Context including image data
     * @return array Messages formatted for the specific provider
     */
    protected function buildMessagesWithVision(string $message, array $context): array
    {
        $messages = [];

        // Add system prompt from context (takes priority) or configuration
        if (!empty($context['system'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $context['system'],
            ];
        } elseif ($this->config && $this->config->system_prompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $this->buildSystemPrompt(),
            ];
        }

        // Add conversation history if provided
        if (!empty($context['history'])) {
            foreach ($context['history'] as $historyMessage) {
                $messages[] = [
                    'role' => $historyMessage['is_from_customer'] ? 'user' : 'assistant',
                    'content' => $historyMessage['content'],
                ];
            }
        }

        // Add knowledge base context if provided
        if (!empty($context['knowledge'])) {
            $knowledgeContext = "Relevant information from the knowledge base:\n\n";
            foreach ($context['knowledge'] as $knowledge) {
                $knowledgeContext .= "- {$knowledge['content']}\n";
            }
            $messages[] = [
                'role' => 'system',
                'content' => $knowledgeContext,
            ];
        }

        // Build user message with optional image
        $userContent = $this->buildVisionUserMessage($message, $context);
        $messages[] = [
            'role' => 'user',
            'content' => $userContent,
        ];

        return $messages;
    }

    /**
     * Build user message content with vision support
     * Override in specific providers for their format
     *
     * @param string $message The text message
     * @param array $context Context that may contain image data
     * @return mixed Content in provider-specific format
     */
    protected function buildVisionUserMessage(string $message, array $context): mixed
    {
        // Default implementation - just return text
        // Providers should override this to support their vision format
        return $message;
    }

    /**
     * Check if the current model supports vision
     */
    public function supportsVision(): bool
    {
        return $this->supportsCapability('image');
    }
}
