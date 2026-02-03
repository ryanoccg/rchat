<?php

namespace App\Services\AI\Providers;

use App\Services\AI\AbstractAiProvider;
use App\Services\AI\AiResponse;
use App\Services\AI\Contracts\AiResponseInterface;
use Illuminate\Support\Facades\Http;

class GeminiProvider extends AbstractAiProvider
{
    protected string $name = 'gemini';
    protected array $capabilities = ['text', 'image'];
    // Current free tier models (Jan 2026):
    // gemini-2.0-flash: Best for chat (good quality + speed)
    // gemini-2.0-flash-lite: Faster, lower quality
    // Check https://ai.google.dev/pricing for latest free tier info
    protected array $availableModels = ['gemini-2.0-flash', 'gemini-2.0-flash-lite', 'gemini-1.5-flash', 'gemini-1.5-pro'];
    protected string $defaultModel = 'gemini-2.0-flash'; // Current recommended free tier model
    protected string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta';

    protected function loadCredentials(): void
    {
        $this->apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
    }

    protected function makeApiCall(array $messages, array $options): AiResponseInterface
    {
        if (!$this->validateCredentials()) {
            return AiResponse::error('Google Gemini API key is not configured');
        }

        $model = $options['model'] ?? $this->defaultModel;
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        // Convert OpenAI-style messages to Gemini format
        $result = $this->convertMessagesToGeminiFormat($messages);
        $contents = $result['contents'];
        $systemInstruction = $result['systemInstruction'];

        // Build request payload
        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $options['max_tokens'] ?? 1024,
                'temperature' => $options['temperature'] ?? 0.7,
            ],
        ];
        
        // Add system instruction if present (Gemini's proper way to handle system prompts)
        if (!empty($systemInstruction)) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        // Retry logic with exponential backoff for rate limits
        $maxRetries = 3;
        $retryDelay = 2; // seconds
        
        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($url, $payload);

            // If rate limited (429), wait and retry
            if ($response->status() === 429 && $attempt < $maxRetries) {
                $retryAfter = $response->json('error.details.2.retryDelay', $retryDelay);
                $waitTime = is_numeric($retryAfter) ? $retryAfter : $retryDelay * $attempt;
                
                \Log::warning('Gemini rate limited, retrying...', [
                    'attempt' => $attempt,
                    'wait_seconds' => $waitTime,
                    'model' => $model,
                ]);
                
                sleep(min($waitTime, 30)); // Cap at 30 seconds
                continue;
            }
            
            break;
        }

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error from Gemini');
            \Log::error('Gemini API error', [
                'error' => $error,
                'response' => $response->json(),
                'status' => $response->status(),
            ]);
            return AiResponse::error($error, $response->json());
        }

        $data = $response->json();
        $candidate = $data['candidates'][0] ?? null;

        if (!$candidate) {
            return AiResponse::error('No response from Gemini', $data);
        }

        $content = $candidate['content']['parts'][0]['text'] ?? '';

        return AiResponse::success(
            content: $content,
            model: $model,
            usage: [
                'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0,
            ],
            finishReason: $candidate['finishReason'] ?? null,
            rawResponse: $data,
        );
    }

    /**
     * Convert OpenAI-style messages to Gemini format
     * Returns array with 'contents' and 'systemInstruction'
     */
    protected function convertMessagesToGeminiFormat(array $messages): array
    {
        $contents = [];
        $systemInstruction = '';

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Collect system messages for systemInstruction
            if ($role === 'system') {
                $systemInstruction .= $content . "\n\n";
                continue;
            }

            // Map OpenAI roles to Gemini roles
            $geminiRole = $role === 'assistant' ? 'model' : 'user';

            $contents[] = [
                'role' => $geminiRole,
                'parts' => [['text' => $content]],
            ];
        }
        
        // Ensure alternating user/model pattern (Gemini requirement)
        // If first message is not from user, add a placeholder
        if (!empty($contents) && $contents[0]['role'] !== 'user') {
            array_unshift($contents, [
                'role' => 'user',
                'parts' => [['text' => 'Hello']],
            ]);
        }

        return [
            'contents' => $contents,
            'systemInstruction' => trim($systemInstruction),
        ];
    }

    public function validateCredentials(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Build user message content with vision support for Gemini
     * Uses inline_data format for images
     */
    protected function buildVisionUserMessage(string $message, array $context): mixed
    {
        // If no image in context, return plain text
        if (empty($context['image'])) {
            return $message;
        }

        // For Gemini, we return an array of parts
        $parts = [];

        // Add text part
        $parts[] = ['text' => $message];

        // Add image(s)
        $images = is_array($context['image']) && isset($context['image'][0])
            ? $context['image']
            : [$context['image']];

        foreach ($images as $image) {
            if (isset($image['base64']) && isset($image['mime_type'])) {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $image['mime_type'],
                        'data' => $image['base64'],
                    ],
                ];
            }
        }

        return $parts;
    }

    /**
     * Convert messages with vision to Gemini format
     */
    protected function convertMessagesWithVisionToGeminiFormat(array $messages): array
    {
        $contents = [];
        $systemInstruction = '';

        foreach ($messages as $message) {
            $role = $message['role'];
            $content = $message['content'];

            // Collect system messages for systemInstruction
            if ($role === 'system') {
                if (is_string($content)) {
                    $systemInstruction .= $content . "\n\n";
                }
                continue;
            }

            // Map OpenAI roles to Gemini roles
            $geminiRole = $role === 'assistant' ? 'model' : 'user';

            // Handle vision content (array of parts)
            if (is_array($content) && isset($content[0]) && (isset($content[0]['text']) || isset($content[0]['inline_data']))) {
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => $content,
                ];
            } else {
                // Regular text content
                $contents[] = [
                    'role' => $geminiRole,
                    'parts' => [['text' => is_string($content) ? $content : json_encode($content)]],
                ];
            }
        }

        // Ensure alternating user/model pattern
        if (!empty($contents) && $contents[0]['role'] !== 'user') {
            array_unshift($contents, [
                'role' => 'user',
                'parts' => [['text' => 'Hello']],
            ]);
        }

        return [
            'contents' => $contents,
            'systemInstruction' => trim($systemInstruction),
        ];
    }

    /**
     * Send message with vision support
     */
    public function sendMessageWithVision(string $message, array $context = [], array $options = []): AiResponseInterface
    {
        $mergedOptions = $this->mergeOptions($options);
        $model = $mergedOptions['model'] ?? $this->defaultModel;
        $url = "{$this->baseUrl}/models/{$model}:generateContent?key={$this->apiKey}";

        $messages = $this->buildMessagesWithVision($message, $context);
        $result = $this->convertMessagesWithVisionToGeminiFormat($messages);
        $contents = $result['contents'];
        $systemInstruction = $result['systemInstruction'];

        $payload = [
            'contents' => $contents,
            'generationConfig' => [
                'maxOutputTokens' => $mergedOptions['max_tokens'] ?? 1024,
                'temperature' => $mergedOptions['temperature'] ?? 0.7,
            ],
        ];

        if (!empty($systemInstruction)) {
            $payload['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]]
            ];
        }

        return $this->executeWithErrorHandling(function () use ($url, $payload, $mergedOptions, $model) {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(60)->post($url, $payload);

            if (!$response->successful()) {
                $error = $response->json('error.message', 'Unknown error from Gemini');
                return AiResponse::error($error, $response->json());
            }

            $data = $response->json();
            $candidate = $data['candidates'][0] ?? null;

            if (!$candidate) {
                return AiResponse::error('No response from Gemini', $data);
            }

            $content = $candidate['content']['parts'][0]['text'] ?? '';

            return AiResponse::success(
                content: $content,
                model: $model,
                usage: [
                    'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                    'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
                    'total_tokens' => $data['usageMetadata']['totalTokenCount'] ?? 0,
                ],
                finishReason: $candidate['finishReason'] ?? null,
                rawResponse: $data,
            );
        });
    }
}
