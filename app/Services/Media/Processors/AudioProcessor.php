<?php

namespace App\Services\Media\Processors;

use App\Services\Media\Contracts\MediaProcessorInterface;
use App\Services\Media\Contracts\MediaProcessingResultInterface;
use App\Services\Media\ProcessingResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AudioProcessor implements MediaProcessorInterface
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl = 'https://api.openai.com/v1';

    protected array $supportedMimeTypes = [
        'audio/mpeg',
        'audio/mp3',
        'audio/mp4',
        'audio/m4a',
        'audio/wav',
        'audio/webm',
        'audio/ogg',
        'audio/flac',
        'audio/x-m4a',
    ];

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        $this->model = config('media.whisper_model', env('OPENAI_WHISPER_MODEL', 'whisper-1'));
    }

    public function process(string $mediaContent, string $mimeType, array $options = []): MediaProcessingResultInterface
    {
        $startTime = microtime(true);

        if (!$this->apiKey) {
            return ProcessingResult::failure(
                'audio',
                $this->getName(),
                'OpenAI API key is not configured'
            );
        }

        // Decode base64 content
        $audioData = base64_decode($mediaContent);
        if ($audioData === false) {
            return ProcessingResult::failure(
                'audio',
                $this->getName(),
                'Invalid base64 audio content'
            );
        }

        // Check audio duration if specified
        $maxDuration = config('media.max_audio_duration_seconds', 300);
        if (isset($options['duration']) && $options['duration'] > $maxDuration) {
            return ProcessingResult::failure(
                'audio',
                $this->getName(),
                "Audio exceeds maximum duration of {$maxDuration} seconds"
            );
        }

        try {
            $transcription = $this->transcribe($audioData, $mimeType, $options);

            $processingTime = (int) ((microtime(true) - $startTime) * 1000);

            return ProcessingResult::success(
                mediaType: 'audio',
                processor: $this->getName(),
                textContent: $transcription['text'],
                analysisData: [
                    'language' => $transcription['language'] ?? null,
                    'duration' => $transcription['duration'] ?? null,
                    'segments' => $transcription['segments'] ?? [],
                ],
                processingTimeMs: $processingTime
            );
        } catch (\Exception $e) {
            Log::error('Audio transcription failed', [
                'error' => $e->getMessage(),
                'mime_type' => $mimeType,
            ]);

            return ProcessingResult::failure(
                'audio',
                $this->getName(),
                $e->getMessage()
            );
        }
    }

    /**
     * Transcribe audio using OpenAI Whisper API with retry logic
     */
    protected function transcribe(string $audioData, string $mimeType, array $options = []): array
    {
        // Determine file extension from mime type
        $extension = $this->getExtensionFromMimeType($mimeType);
        $filename = "audio.{$extension}";

        $maxRetries = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                // Create multipart form data
                $response = Http::withHeaders([
                    'Authorization' => "Bearer {$this->apiKey}",
                ])
                ->timeout(120)
                ->attach('file', $audioData, $filename)
                ->post("{$this->baseUrl}/audio/transcriptions", [
                    'model' => $this->model,
                    'response_format' => 'verbose_json',
                    'language' => $options['language'] ?? null,
                ]);

                if ($response->successful()) {
                    return $response->json();
                }

                $lastError = $response->json('error.message', 'Unknown error from Whisper API');

                // Check if it's a server error (5xx) - retry
                if ($response->serverError() && $attempt < $maxRetries) {
                    Log::warning("Whisper API server error, retrying ({$attempt}/{$maxRetries})", [
                        'error' => $lastError,
                    ]);
                    // Exponential backoff: 1s, 2s, 4s
                    sleep(pow(2, $attempt - 1));
                    continue;
                }

                // For client errors (4xx) or final attempt, break
                break;

            } catch (\Exception $e) {
                $lastError = $e->getMessage();

                if ($attempt < $maxRetries) {
                    Log::warning("Whisper API exception, retrying ({$attempt}/{$maxRetries})", [
                        'error' => $lastError,
                    ]);
                    sleep(pow(2, $attempt - 1));
                    continue;
                }
            }
        }

        throw new \Exception("Whisper API error after {$maxRetries} attempts: {$lastError}");
    }

    /**
     * Get file extension from MIME type
     */
    protected function getExtensionFromMimeType(string $mimeType): string
    {
        // Remove codecs information if present
        $mimeType = explode(';', $mimeType)[0];
        $mimeType = trim($mimeType);

        return match ($mimeType) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/mp4', 'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/wav' => 'wav',
            'audio/webm' => 'webm',
            'audio/ogg' => 'ogg',
            'audio/flac' => 'flac',
            default => 'mp3',
        };
    }

    public function getSupportedMimeTypes(): array
    {
        return $this->supportedMimeTypes;
    }

    public function supports(string $mimeType): bool
    {
        // Remove codecs information
        $mimeType = explode(';', $mimeType)[0];
        $mimeType = trim($mimeType);

        return in_array($mimeType, $this->supportedMimeTypes);
    }

    public function getName(): string
    {
        return 'whisper';
    }
}
