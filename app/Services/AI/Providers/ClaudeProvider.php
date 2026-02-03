<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AbstractAiProvider;
use App\Services\AI\AiResponse;
use App\Services\AI\Contracts\AiResponseInterface;
use Illuminate\Support\Facades\Http;

class ClaudeProvider extends AbstractAiProvider
{
    protected string $name = 'claude';
    protected array $capabilities = ['text', 'image'];
    protected array $availableModels = ['claude-3-opus-20240229', 'claude-3-sonnet-20240229', 'claude-3-haiku-20240307', 'claude-3-5-sonnet-20241022'];
    protected string $defaultModel = 'claude-3-5-sonnet-20241022';
    protected string $baseUrl = 'https://api.anthropic.com/v1';

    protected function loadCredentials(): void
    {
        $this->apiKey = config('services.anthropic.api_key', env('ANTHROPIC_API_KEY'));
    }

    protected function makeApiCall(array $messages, array $options): AiResponseInterface
    {
        if (!$this->validateCredentials()) {
            return AiResponse::error('Anthropic API key is not configured');
        }

        // Extract system message if present
        $systemPrompt = '';
        $claudeMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemPrompt .= $message['content'] . "\n";
            } else {
                $claudeMessages[] = [
                    'role' => $message['role'],
                    'content' => $message['content'],
                ];
            }
        }

        $payload = [
            'model' => $options['model'] ?? $this->defaultModel,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages' => $claudeMessages,
        ];

        if (!empty(trim($systemPrompt))) {
            $payload['system'] = trim($systemPrompt);
        }

        // Add temperature if not default
        if (isset($options['temperature']) && $options['temperature'] != 1.0) {
            $payload['temperature'] = $options['temperature'];
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/messages", $payload);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error from Claude');
            return AiResponse::error($error, $response->json());
        }

        $data = $response->json();

        if ($data['type'] === 'error') {
            return AiResponse::error($data['error']['message'] ?? 'Unknown error', $data);
        }

        $content = '';
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            }
        }

        return AiResponse::success(
            content: $content,
            model: $data['model'] ?? $options['model'],
            usage: [
                'prompt_tokens' => $data['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
            ],
            finishReason: $data['stop_reason'] ?? null,
            rawResponse: $data,
        );
    }

    public function validateCredentials(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Build user message content with vision support for Claude
     * Uses base64 image source format
     */
    protected function buildVisionUserMessage(string $message, array $context): mixed
    {
        // If no image in context, return plain text
        if (empty($context['image'])) {
            return $message;
        }

        $content = [];

        // Add image(s) first (Claude prefers images before text)
        $images = is_array($context['image']) && isset($context['image'][0])
            ? $context['image']
            : [$context['image']];

        foreach ($images as $image) {
            if (isset($image['base64']) && isset($image['mime_type'])) {
                $content[] = [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $image['mime_type'],
                        'data' => $image['base64'],
                    ],
                ];
            } elseif (isset($image['url'])) {
                // Claude doesn't support URL images directly, would need to download first
                // Skip URL-based images for now
                continue;
            }
        }

        // Add text content
        $content[] = [
            'type' => 'text',
            'text' => $message,
        ];

        return $content;
    }

    /**
     * Override makeApiCall to handle vision messages properly
     */
    protected function makeApiCallWithVision(array $messages, array $options): AiResponseInterface
    {
        if (!$this->validateCredentials()) {
            return AiResponse::error('Anthropic API key is not configured');
        }

        // Extract system message if present
        $systemPrompt = '';
        $claudeMessages = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'system') {
                $systemPrompt .= $message['content'] . "\n";
            } else {
                $claudeMessages[] = [
                    'role' => $message['role'],
                    'content' => $message['content'], // Already in vision format
                ];
            }
        }

        $payload = [
            'model' => $options['model'] ?? $this->defaultModel,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'messages' => $claudeMessages,
        ];

        if (!empty(trim($systemPrompt))) {
            $payload['system'] = trim($systemPrompt);
        }

        if (isset($options['temperature']) && $options['temperature'] != 1.0) {
            $payload['temperature'] = $options['temperature'];
        }

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/messages", $payload);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error from Claude');
            return AiResponse::error($error, $response->json());
        }

        $data = $response->json();

        if ($data['type'] === 'error') {
            return AiResponse::error($data['error']['message'] ?? 'Unknown error', $data);
        }

        $content = '';
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            }
        }

        return AiResponse::success(
            content: $content,
            model: $data['model'] ?? $options['model'],
            usage: [
                'prompt_tokens' => $data['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['output_tokens'] ?? 0,
                'total_tokens' => ($data['usage']['input_tokens'] ?? 0) + ($data['usage']['output_tokens'] ?? 0),
            ],
            finishReason: $data['stop_reason'] ?? null,
            rawResponse: $data,
        );
    }

    /**
     * Send message with vision support
     */
    public function sendMessageWithVision(string $message, array $context = [], array $options = []): AiResponseInterface
    {
        $mergedOptions = $this->mergeOptions($options);
        $messages = $this->buildMessagesWithVision($message, $context);

        return $this->executeWithErrorHandling(function () use ($messages, $mergedOptions) {
            return $this->makeApiCallWithVision($messages, $mergedOptions);
        });
    }
}
