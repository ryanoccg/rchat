<?php

namespace App\Services\Media;

use App\Models\Message;
use App\Models\MediaProcessingResult as MediaProcessingResultModel;
use App\Models\PlatformConnection;
use App\Services\Media\Contracts\MediaProcessingResultInterface;
use App\Services\Media\Processors\AudioProcessor;
use App\Services\Media\Processors\ImageProcessor;
use Illuminate\Support\Facades\Log;

class MediaProcessingService
{
    protected MediaExtractor $extractor;
    protected PlatformMediaDownloader $downloader;
    protected AudioProcessor $audioProcessor;
    protected ImageProcessor $imageProcessor;

    public function __construct(
        ?MediaExtractor $extractor = null,
        ?PlatformMediaDownloader $downloader = null,
        ?AudioProcessor $audioProcessor = null,
        ?ImageProcessor $imageProcessor = null
    ) {
        $this->extractor = $extractor ?? new MediaExtractor();
        $this->downloader = $downloader ?? new PlatformMediaDownloader();
        $this->audioProcessor = $audioProcessor ?? new AudioProcessor();
        $this->imageProcessor = $imageProcessor ?? new ImageProcessor();
    }

    /**
     * Check if media processing is enabled
     */
    public function isEnabled(): bool
    {
        return config('media.processing_enabled', env('MEDIA_PROCESSING_ENABLED', true));
    }

    /**
     * Process media from a message
     *
     * @param Message $message The message containing media
     * @param PlatformConnection $connection Platform connection for downloading
     * @param string $platform Platform identifier
     * @param array $options Processing options
     * @return array<MediaProcessingResultModel> Array of processing result models
     */
    public function processMessageMedia(
        Message $message,
        PlatformConnection $connection,
        string $platform,
        array $options = []
    ): array {
        if (!$this->isEnabled()) {
            Log::info('Media processing is disabled');
            return [];
        }

        $results = [];

        // Extract media info from message
        $mediaItems = $this->extractMediaFromMessage($message, $platform);

        if (empty($mediaItems)) {
            return [];
        }

        foreach ($mediaItems as $mediaInfo) {
            $mediaType = $this->extractor->getMediaCategory($mediaInfo['type']);

            // Skip unsupported media types
            if (!$mediaType || !in_array($mediaType, ['image', 'audio'])) {
                continue;
            }

            // Deduplication: use url or media_id as unique key
            $mediaKey = $mediaInfo['url'] ?? $mediaInfo['media_id'] ?? null;
            $existing = null;
            if ($mediaKey) {
                $existing = MediaProcessingResultModel::where('message_id', $message->id)
                    ->where('media_type', $mediaType)
                    ->where(function($q) use ($mediaKey) {
                        $q->where('analysis_data->url', $mediaKey)
                          ->orWhere('analysis_data->media_id', $mediaKey);
                    })
                    ->first();
            }
            if ($existing) {
                Log::info('Duplicate media processing result skipped', [
                    'message_id' => $message->id,
                    'media_type' => $mediaType,
                    'media_key' => $mediaKey,
                ]);
                $results[] = $existing;
                continue;
            }

            // Create pending result record
            $resultModel = MediaProcessingResultModel::create([
                'message_id' => $message->id,
                'media_type' => $mediaType,
                'processor' => $this->getProcessorName($mediaType),
                'status' => MediaProcessingResultModel::STATUS_PENDING,
                // Save media key in analysis_data for deduplication
                'analysis_data' => [
                    'url' => $mediaInfo['url'] ?? null,
                    'media_id' => $mediaInfo['media_id'] ?? null,
                ],
            ]);

            try {
                $resultModel->markAsProcessing();

                // Download media content
                $downloadedMedia = $this->downloader->download($mediaInfo, $connection, $platform);

                // Validate size
                if (!$this->downloader->validateMediaSize($downloadedMedia['content'], $mediaType)) {
                    throw new \Exception("Media file exceeds maximum allowed size");
                }

                // Process based on type
                $processingResult = $this->processMedia(
                    $mediaType,
                    $downloadedMedia['content'],
                    $downloadedMedia['mime_type'],
                    array_merge($options, $mediaInfo)
                );

                // Update result model
                if ($processingResult->isSuccessful()) {
                    $resultModel->markAsCompleted(
                        $processingResult->getTextContent() ?? '',
                        $processingResult->getAnalysisData(),
                        $processingResult->getProcessingTimeMs()
                    );
                    $resultModel->processor = $processingResult->getProcessor();
                    $resultModel->save();
                } else {
                    $resultModel->markAsFailed($processingResult->getError() ?? 'Unknown error');
                }
            } catch (\Exception $e) {
                Log::error('Media processing failed', [
                    'message_id' => $message->id,
                    'media_type' => $mediaType,
                    'error' => $e->getMessage(),
                ]);
                $resultModel->markAsFailed($e->getMessage());
            }

            $results[] = $resultModel->fresh();
        }

        return $results;
    }

    /**
     * Extract media information from a message
     */
    protected function extractMediaFromMessage(Message $message, string $platform): array
    {
        $messageData = [
            'type' => $message->message_type,
            'metadata' => array_merge(
                $message->metadata ?? [],
                ['raw_message' => $message->metadata['raw_message'] ?? $message->metadata ?? []]
            ),
        ];

        return $this->extractor->extract($messageData, $platform);
    }

    /**
     * Process media content
     */
    protected function processMedia(
        string $mediaType,
        string $content,
        string $mimeType,
        array $options = []
    ): MediaProcessingResultInterface {
        return match ($mediaType) {
            'audio' => $this->audioProcessor->process($content, $mimeType, $options),
            'image' => $this->imageProcessor->process($content, $mimeType, $options),
            default => ProcessingResult::failure($mediaType, 'unknown', 'Unsupported media type'),
        };
    }

    /**
     * Get processor name for a media type
     */
    protected function getProcessorName(string $mediaType): string
    {
        return match ($mediaType) {
            'audio' => $this->audioProcessor->getName(),
            'image' => $this->imageProcessor->getName(),
            default => 'unknown',
        };
    }

    /**
     * Get completed processing results for a message
     */
    public function getProcessingResults(Message $message): array
    {
        return MediaProcessingResultModel::where('message_id', $message->id)
            ->completed()
            ->get()
            ->toArray();
    }

    /**
     * Get the text content from all processed media for a message
     */
    public function getMediaTextContent(Message $message): ?string
    {
        $results = MediaProcessingResultModel::where('message_id', $message->id)
            ->completed()
            ->get();

        if ($results->isEmpty()) {
            return null;
        }

        $textParts = [];

        foreach ($results as $result) {
            if ($result->text_content) {
                $prefix = match ($result->media_type) {
                    'audio' => '[Voice/Audio transcription]',
                    'image' => '[Image description]',
                    default => '[Media]',
                };
                $textParts[] = "{$prefix}: {$result->text_content}";
            }
        }

        return implode("\n", $textParts);
    }

    /**
     * Check if a message has pending media processing
     */
    public function hasPendingProcessing(Message $message): bool
    {
        return MediaProcessingResultModel::where('message_id', $message->id)
            ->whereIn('status', [
                MediaProcessingResultModel::STATUS_PENDING,
                MediaProcessingResultModel::STATUS_PROCESSING,
            ])
            ->exists();
    }

    /**
     * Set the image processor provider
     */
    public function setImageProvider(string $provider): self
    {
        $this->imageProcessor->setProvider($provider);
        return $this;
    }
}
