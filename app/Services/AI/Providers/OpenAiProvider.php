<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AbstractAiProvider;
use App\Services\AI\AiResponse;
use App\Services\AI\Contracts\AiResponseInterface;
use Illuminate\Support\Facades\Http;

class OpenAiProvider extends AbstractAiProvider
{
    protected string $name = 'openai';
    protected array $capabilities = ['text', 'image', 'audio'];
    protected array $availableModels = ['gpt-5-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo'];
    protected string $defaultModel = 'gpt-5-mini'; // Cost-effective, high RPM
    protected string $baseUrl = 'https://api.openai.com/v1';

    protected function loadCredentials(): void
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
    }

    protected function makeApiCall(array $messages, array $options): AiResponseInterface
    {
        if (!$this->validateCredentials()) {
            return AiResponse::error('OpenAI API key is not configured');
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])->timeout(60)->post("{$this->baseUrl}/chat/completions", [
            'model' => $options['model'] ?? $this->defaultModel,
            'messages' => $messages,
            'max_tokens' => $options['max_tokens'] ?? 1024,
            'temperature' => $options['temperature'] ?? 0.7,
        ]);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error from OpenAI');
            return AiResponse::error($error, $response->json());
        }

        $data = $response->json();
        $choice = $data['choices'][0] ?? null;

        if (!$choice) {
            return AiResponse::error('No response from OpenAI', $data);
        }

        return AiResponse::success(
            content: $choice['message']['content'] ?? '',
            model: $data['model'] ?? $options['model'],
            usage: [
                'prompt_tokens' => $data['usage']['prompt_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['completion_tokens'] ?? 0,
                'total_tokens' => $data['usage']['total_tokens'] ?? 0,
            ],
            finishReason: $choice['finish_reason'] ?? null,
            rawResponse: $data,
        );
    }

    public function validateCredentials(): bool
    {
        if (empty($this->apiKey)) {
            return false;
        }

        // Optionally validate by making a test request
        return true;
    }

    /**
     * Build user message content with vision support for OpenAI
     * Uses the image_url content type for GPT-4V models
     */
    protected function buildVisionUserMessage(string $message, array $context): mixed
    {
        // If no image in context, return plain text
        if (empty($context['image'])) {
            return $message;
        }

        $content = [];

        // Add text content
        $content[] = [
            'type' => 'text',
            'text' => $message,
        ];

        // Add image(s)
        $images = is_array($context['image']) && isset($context['image'][0])
            ? $context['image']
            : [$context['image']];

        foreach ($images as $image) {
            if (isset($image['base64']) && isset($image['mime_type'])) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => "data:{$image['mime_type']};base64,{$image['base64']}",
                        'detail' => $image['detail'] ?? 'auto',
                    ],
                ];
            } elseif (isset($image['url'])) {
                $content[] = [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => $image['url'],
                        'detail' => $image['detail'] ?? 'auto',
                    ],
                ];
            }
        }

        return $content;
    }

    /**
     * Override makeApiCall to handle vision messages
     */
    public function sendMessageWithVision(string $message, array $context = [], array $options = []): AiResponseInterface
    {
        $mergedOptions = $this->mergeOptions($options);

        // Use vision-capable model
        if (!empty($context['image'])) {
            $mergedOptions['model'] = $options['model'] ?? config('media.openai_vision_model', 'gpt-4o');
        }

        $messages = $this->buildMessagesWithVision($message, $context);

        return $this->executeWithErrorHandling(function () use ($messages, $mergedOptions) {
            return $this->makeApiCall($messages, $mergedOptions);
        });
    }
}
