<?php

namespace App\Services\Media;

use App\Models\Message;

class MediaExtractor
{
    /**
     * Extract media information from a message based on its metadata and type
     *
     * @param array $messageData Raw message data from platform handler
     * @param string $platform Platform identifier (whatsapp, telegram, facebook, line)
     * @return array Array of media items with type, id/url, and mime_type
     */
    public function extract(array $messageData, string $platform): array
    {
        return match ($platform) {
            'whatsapp' => $this->extractFromWhatsApp($messageData),
            'telegram' => $this->extractFromTelegram($messageData),
            'facebook' => $this->extractFromFacebook($messageData),
            'line' => $this->extractFromLine($messageData),
            default => [],
        };
    }

    /**
     * Extract media from WhatsApp message data
     */
    protected function extractFromWhatsApp(array $data): array
    {
        $media = [];
        $metadata = $data['metadata'] ?? [];
        $rawMessage = $metadata['raw_message'] ?? $data;

        // Image message
        if (isset($rawMessage['image'])) {
            $media[] = [
                'type' => 'image',
                'media_id' => $rawMessage['image']['id'] ?? null,
                'mime_type' => $rawMessage['image']['mime_type'] ?? 'image/jpeg',
                'sha256' => $rawMessage['image']['sha256'] ?? null,
                'caption' => $rawMessage['image']['caption'] ?? null,
            ];
        }

        // Audio message
        if (isset($rawMessage['audio'])) {
            $media[] = [
                'type' => 'audio',
                'media_id' => $rawMessage['audio']['id'] ?? null,
                'mime_type' => $rawMessage['audio']['mime_type'] ?? 'audio/ogg',
            ];
        }

        // Voice message (treated as audio)
        if (isset($rawMessage['voice'])) {
            $media[] = [
                'type' => 'audio',
                'media_id' => $rawMessage['voice']['id'] ?? null,
                'mime_type' => $rawMessage['voice']['mime_type'] ?? 'audio/ogg; codecs=opus',
            ];
        }

        // Video message
        if (isset($rawMessage['video'])) {
            $media[] = [
                'type' => 'video',
                'media_id' => $rawMessage['video']['id'] ?? null,
                'mime_type' => $rawMessage['video']['mime_type'] ?? 'video/mp4',
                'caption' => $rawMessage['video']['caption'] ?? null,
            ];
        }

        // Document
        if (isset($rawMessage['document'])) {
            $media[] = [
                'type' => 'document',
                'media_id' => $rawMessage['document']['id'] ?? null,
                'mime_type' => $rawMessage['document']['mime_type'] ?? 'application/octet-stream',
                'filename' => $rawMessage['document']['filename'] ?? null,
            ];
        }

        return $media;
    }

    /**
     * Extract media from Telegram message data
     */
    protected function extractFromTelegram(array $data): array
    {
        $media = [];
        $metadata = $data['metadata'] ?? [];
        $rawMessage = $metadata['raw_message'] ?? $data;

        // Photo message (Telegram sends array of sizes, use largest)
        if (isset($rawMessage['photo']) && is_array($rawMessage['photo'])) {
            $photo = end($rawMessage['photo']); // Get largest size
            $media[] = [
                'type' => 'image',
                'file_id' => $photo['file_id'] ?? null,
                'file_unique_id' => $photo['file_unique_id'] ?? null,
                'mime_type' => 'image/jpeg', // Telegram photos are always JPEG
                'file_size' => $photo['file_size'] ?? null,
            ];
        }

        // Voice message
        if (isset($rawMessage['voice'])) {
            $media[] = [
                'type' => 'audio',
                'file_id' => $rawMessage['voice']['file_id'] ?? null,
                'file_unique_id' => $rawMessage['voice']['file_unique_id'] ?? null,
                'mime_type' => $rawMessage['voice']['mime_type'] ?? 'audio/ogg',
                'duration' => $rawMessage['voice']['duration'] ?? null,
            ];
        }

        // Audio message
        if (isset($rawMessage['audio'])) {
            $media[] = [
                'type' => 'audio',
                'file_id' => $rawMessage['audio']['file_id'] ?? null,
                'file_unique_id' => $rawMessage['audio']['file_unique_id'] ?? null,
                'mime_type' => $rawMessage['audio']['mime_type'] ?? 'audio/mpeg',
                'duration' => $rawMessage['audio']['duration'] ?? null,
                'title' => $rawMessage['audio']['title'] ?? null,
            ];
        }

        // Video message
        if (isset($rawMessage['video'])) {
            $media[] = [
                'type' => 'video',
                'file_id' => $rawMessage['video']['file_id'] ?? null,
                'file_unique_id' => $rawMessage['video']['file_unique_id'] ?? null,
                'mime_type' => $rawMessage['video']['mime_type'] ?? 'video/mp4',
                'duration' => $rawMessage['video']['duration'] ?? null,
            ];
        }

        // Video note (circular video)
        if (isset($rawMessage['video_note'])) {
            $media[] = [
                'type' => 'video',
                'file_id' => $rawMessage['video_note']['file_id'] ?? null,
                'file_unique_id' => $rawMessage['video_note']['file_unique_id'] ?? null,
                'mime_type' => 'video/mp4',
                'duration' => $rawMessage['video_note']['duration'] ?? null,
            ];
        }

        // Document
        if (isset($rawMessage['document'])) {
            $media[] = [
                'type' => 'document',
                'file_id' => $rawMessage['document']['file_id'] ?? null,
                'file_unique_id' => $rawMessage['document']['file_unique_id'] ?? null,
                'mime_type' => $rawMessage['document']['mime_type'] ?? 'application/octet-stream',
                'file_name' => $rawMessage['document']['file_name'] ?? null,
            ];
        }

        return $media;
    }

    /**
     * Extract media from Facebook Messenger message data
     */
    protected function extractFromFacebook(array $data): array
    {
        $media = [];
        $metadata = $data['metadata'] ?? [];
        $attachments = $metadata['attachments'] ?? [];

        foreach ($attachments as $attachment) {
            $type = $attachment['type'] ?? 'file';
            $payload = $attachment['payload'] ?? [];

            switch ($type) {
                case 'image':
                    $media[] = [
                        'type' => 'image',
                        'url' => $payload['url'] ?? null,
                        'mime_type' => 'image/jpeg', // Facebook doesn't always provide mime type
                        'sticker_id' => $payload['sticker_id'] ?? null,
                    ];
                    break;

                case 'audio':
                    $media[] = [
                        'type' => 'audio',
                        'url' => $payload['url'] ?? null,
                        'mime_type' => 'audio/mpeg',
                    ];
                    break;

                case 'video':
                    $media[] = [
                        'type' => 'video',
                        'url' => $payload['url'] ?? null,
                        'mime_type' => 'video/mp4',
                    ];
                    break;

                case 'file':
                    $media[] = [
                        'type' => 'document',
                        'url' => $payload['url'] ?? null,
                        'mime_type' => 'application/octet-stream',
                    ];
                    break;
            }
        }

        return $media;
    }

    /**
     * Extract media from LINE message data
     */
    protected function extractFromLine(array $data): array
    {
        $media = [];
        $metadata = $data['metadata'] ?? [];
        $rawMessage = $metadata['raw_message'] ?? $data;
        $messageType = $rawMessage['type'] ?? 'text';

        switch ($messageType) {
            case 'image':
                $media[] = [
                    'type' => 'image',
                    'message_id' => $rawMessage['id'] ?? null,
                    'mime_type' => 'image/jpeg',
                    'content_provider' => $rawMessage['contentProvider'] ?? null,
                ];
                break;

            case 'audio':
                $media[] = [
                    'type' => 'audio',
                    'message_id' => $rawMessage['id'] ?? null,
                    'mime_type' => 'audio/m4a',
                    'duration' => $rawMessage['duration'] ?? null,
                ];
                break;

            case 'video':
                $media[] = [
                    'type' => 'video',
                    'message_id' => $rawMessage['id'] ?? null,
                    'mime_type' => 'video/mp4',
                    'duration' => $rawMessage['duration'] ?? null,
                ];
                break;

            case 'file':
                $media[] = [
                    'type' => 'document',
                    'message_id' => $rawMessage['id'] ?? null,
                    'mime_type' => 'application/octet-stream',
                    'file_name' => $rawMessage['fileName'] ?? null,
                    'file_size' => $rawMessage['fileSize'] ?? null,
                ];
                break;
        }

        return $media;
    }

    /**
     * Check if a message type is a media type that should be processed
     */
    public function isProcessableMediaType(string $type): bool
    {
        return in_array($type, ['image', 'audio', 'video']);
    }

    /**
     * Get the media type category for AI processing
     */
    public function getMediaCategory(string $type): ?string
    {
        return match ($type) {
            'image' => 'image',
            'audio', 'voice' => 'audio',
            default => null,
        };
    }
}
