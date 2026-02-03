<?php

namespace App\Services\Media\Processors;

use App\Services\Media\Contracts\MediaProcessorInterface;
use App\Services\Media\Contracts\MediaProcessingResultInterface;
use App\Services\Media\ProcessingResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ImageProcessor implements MediaProcessorInterface
{
    protected string $provider;
    protected string $apiKey;
    protected string $model;

    protected array $supportedMimeTypes = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    protected const OPENAI_URL = 'https://api.openai.com/v1/chat/completions';
    protected const ANTHROPIC_URL = 'https://api.anthropic.com/v1/messages';
    protected const GEMINI_URL = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function __construct(?string $provider = null)
    {
        $this->provider = $provider ?? config('media.vision_provider', 'openai');
        $this->loadCredentials();
    }

    protected function loadCredentials(): void
    {
        match ($this->provider) {
            'openai' => $this->loadOpenAiCredentials(),
            'anthropic', 'claude' => $this->loadClaudeCredentials(),
            'gemini', 'google' => $this->loadGeminiCredentials(),
            default => $this->loadOpenAiCredentials(),
        };
    }

    protected function loadOpenAiCredentials(): void
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        $this->model = config('media.openai_vision_model', env('OPENAI_VISION_MODEL', 'gpt-4o'));
    }

    protected function loadClaudeCredentials(): void
    {
        $this->apiKey = config('services.anthropic.api_key', env('ANTHROPIC_API_KEY'));
        $this->model = config('media.claude_vision_model', 'claude-3-5-sonnet-20241022');
    }

    protected function loadGeminiCredentials(): void
    {
        $this->apiKey = config('services.gemini.api_key', env('GEMINI_API_KEY'));
        $this->model = config('media.gemini_vision_model', 'gemini-2.0-flash');
    }

    public function process(string $mediaContent, string $mimeType, array $options = []): MediaProcessingResultInterface
    {
        $startTime = microtime(true);

        if (!$this->apiKey) {
            return ProcessingResult::failure(
                'image',
                $this->getName(),
                "{$this->provider} API key is not configured"
            );
        }

        // Get the analysis prompt
        $prompt = $options['prompt'] ?? $this->getDefaultPrompt($options);

        try {
            $result = match ($this->provider) {
                'openai' => $this->analyzeWithOpenAi($mediaContent, $mimeType, $prompt),
                'anthropic', 'claude' => $this->analyzeWithClaude($mediaContent, $mimeType, $prompt),
                'gemini', 'google' => $this->analyzeWithGemini($mediaContent, $mimeType, $prompt),
                default => $this->analyzeWithOpenAi($mediaContent, $mimeType, $prompt),
            };

            $processingTime = (int) ((microtime(true) - $startTime) * 1000);

            return ProcessingResult::success(
                mediaType: 'image',
                processor: $this->getName(),
                textContent: $result['description'],
                analysisData: [
                    'model' => $result['model'],
                    'usage' => $result['usage'] ?? [],
                    'product_search' => $options['product_search'] ?? false,
                ],
                processingTimeMs: $processingTime
            );
        } catch (\Exception $e) {
            Log::error('Image analysis failed', [
                'provider' => $this->provider,
                'error' => $e->getMessage(),
                'mime_type' => $mimeType,
            ]);

            return ProcessingResult::failure(
                'image',
                $this->getName(),
                $e->getMessage()
            );
        }
    }

    /**
     * Get the default prompt for image analysis
     */
    protected function getDefaultPrompt(array $options = []): string
    {
        if (!empty($options['product_search'])) {
            return "Analyze this product image and provide a detailed description that could be used to search a product catalog. Include: product type, brand if visible, color, size/dimensions if apparent, material, style, any text/labels visible, and notable features. Be specific and concise.";
        }

        return "Describe this image in detail. What do you see? Include any text, objects, people, colors, and context that might be relevant for customer service purposes. Be concise but thorough.";
    }

    /**
     * Analyze image using OpenAI GPT-4 Vision
     */
    protected function analyzeWithOpenAi(string $base64Content, string $mimeType, string $prompt): array
    {
        $response = Http::withHeaders([
            'Authorization' => "Bearer {$this->apiKey}",
            'Content-Type' => 'application/json',
        ])
        ->timeout(60)
        ->post(self::OPENAI_URL, [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Content}",
                                'detail' => 'auto',
                            ],
                        ],
                    ],
                ],
            ],
            'max_tokens' => 500,
        ]);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error from OpenAI Vision');
            throw new \Exception("OpenAI Vision API error: {$error}");
        }

        $data = $response->json();

        return [
            'description' => $data['choices'][0]['message']['content'] ?? '',
            'model' => $data['model'] ?? $this->model,
            'usage' => $data['usage'] ?? [],
        ];
    }

    /**
     * Analyze image using Claude Vision
     */
    protected function analyzeWithClaude(string $base64Content, string $mimeType, string $prompt): array
    {
        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => '2023-06-01',
            'Content-Type' => 'application/json',
        ])
        ->timeout(60)
        ->post(self::ANTHROPIC_URL, [
            'model' => $this->model,
            'max_tokens' => 500,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'image',
                            'source' => [
                                'type' => 'base64',
                                'media_type' => $mimeType,
                                'data' => $base64Content,
                            ],
                        ],
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                    ],
                ],
            ],
        ]);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error from Claude Vision');
            throw new \Exception("Claude Vision API error: {$error}");
        }

        $data = $response->json();
        $content = '';
        foreach ($data['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content .= $block['text'];
            }
        }

        return [
            'description' => $content,
            'model' => $data['model'] ?? $this->model,
            'usage' => [
                'prompt_tokens' => $data['usage']['input_tokens'] ?? 0,
                'completion_tokens' => $data['usage']['output_tokens'] ?? 0,
            ],
        ];
    }

    /**
     * Analyze image using Google Gemini Vision
     */
    protected function analyzeWithGemini(string $base64Content, string $mimeType, string $prompt): array
    {
        $url = self::GEMINI_URL . "/{$this->model}:generateContent?key={$this->apiKey}";

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
        ])
        ->timeout(60)
        ->post($url, [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt],
                        [
                            'inline_data' => [
                                'mime_type' => $mimeType,
                                'data' => $base64Content,
                            ],
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'maxOutputTokens' => 500,
                'temperature' => 0.4,
            ],
        ]);

        if (!$response->successful()) {
            $error = $response->json('error.message', 'Unknown error from Gemini Vision');
            throw new \Exception("Gemini Vision API error: {$error}");
        }

        $data = $response->json();
        $content = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

        return [
            'description' => $content,
            'model' => $this->model,
            'usage' => [
                'prompt_tokens' => $data['usageMetadata']['promptTokenCount'] ?? 0,
                'completion_tokens' => $data['usageMetadata']['candidatesTokenCount'] ?? 0,
            ],
        ];
    }

    public function getSupportedMimeTypes(): array
    {
        return $this->supportedMimeTypes;
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, $this->supportedMimeTypes);
    }

    public function getName(): string
    {
        return match ($this->provider) {
            'openai' => 'openai_vision',
            'anthropic', 'claude' => 'claude_vision',
            'gemini', 'google' => 'gemini_vision',
            default => 'openai_vision',
        };
    }

    /**
     * Set the vision provider
     */
    public function setProvider(string $provider): self
    {
        $this->provider = $provider;
        $this->loadCredentials();
        return $this;
    }
}
