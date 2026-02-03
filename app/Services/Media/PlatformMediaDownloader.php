<?php

namespace App\Services\Media;

use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class PlatformMediaDownloader
{
    protected const WHATSAPP_API_URL = 'https://graph.facebook.com/v18.0';
    protected const TELEGRAM_API_URL = 'https://api.telegram.org';
    protected const LINE_API_URL = 'https://api-data.line.me/v2/bot/message';

    /**
     * Download media content from the platform
     *
     * @param array $mediaInfo Media information from MediaExtractor
     * @param PlatformConnection $connection Platform connection with credentials
     * @param string $platform Platform identifier
     * @return array ['content' => base64 encoded content, 'mime_type' => string]
     * @throws Exception
     */
    public function download(array $mediaInfo, PlatformConnection $connection, string $platform): array
    {
        return match ($platform) {
            'whatsapp' => $this->downloadFromWhatsApp($mediaInfo, $connection),
            'telegram' => $this->downloadFromTelegram($mediaInfo, $connection),
            'facebook' => $this->downloadFromFacebook($mediaInfo, $connection),
            'line' => $this->downloadFromLine($mediaInfo, $connection),
            default => throw new Exception("Unsupported platform: {$platform}"),
        };
    }

    /**
     * Download media from WhatsApp
     */
    protected function downloadFromWhatsApp(array $mediaInfo, PlatformConnection $connection): array
    {
        $accessToken = $connection->credentials['access_token'] ?? null;
        $mediaId = $mediaInfo['media_id'] ?? null;

        if (!$accessToken || !$mediaId) {
            throw new Exception('Missing WhatsApp credentials or media ID');
        }

        // First, get the media URL
        $urlResponse = Http::withToken($accessToken)
            ->get(self::WHATSAPP_API_URL . "/{$mediaId}");

        if (!$urlResponse->successful()) {
            Log::error('Failed to get WhatsApp media URL', [
                'media_id' => $mediaId,
                'response' => $urlResponse->json(),
            ]);
            throw new Exception('Failed to get WhatsApp media URL');
        }

        $mediaUrl = $urlResponse->json('url');

        if (!$mediaUrl) {
            throw new Exception('No media URL returned from WhatsApp');
        }

        // Download the actual media content
        $contentResponse = Http::withToken($accessToken)
            ->timeout(60)
            ->get($mediaUrl);

        if (!$contentResponse->successful()) {
            throw new Exception('Failed to download WhatsApp media content');
        }

        return [
            'content' => base64_encode($contentResponse->body()),
            'mime_type' => $mediaInfo['mime_type'] ?? $contentResponse->header('Content-Type') ?? 'application/octet-stream',
        ];
    }

    /**
     * Download media from Telegram
     */
    protected function downloadFromTelegram(array $mediaInfo, PlatformConnection $connection): array
    {
        $botToken = $connection->credentials['bot_token'] ?? null;
        $fileId = $mediaInfo['file_id'] ?? null;

        if (!$botToken || !$fileId) {
            throw new Exception('Missing Telegram credentials or file ID');
        }

        // Get file path
        $fileResponse = Http::get(self::TELEGRAM_API_URL . "/bot{$botToken}/getFile", [
            'file_id' => $fileId,
        ]);

        if (!$fileResponse->successful() || !$fileResponse->json('ok')) {
            Log::error('Failed to get Telegram file path', [
                'file_id' => $fileId,
                'response' => $fileResponse->json(),
            ]);
            throw new Exception('Failed to get Telegram file path');
        }

        $filePath = $fileResponse->json('result.file_path');

        if (!$filePath) {
            throw new Exception('No file path returned from Telegram');
        }

        // Download the file
        $downloadUrl = self::TELEGRAM_API_URL . "/file/bot{$botToken}/{$filePath}";
        $contentResponse = Http::timeout(60)->get($downloadUrl);

        if (!$contentResponse->successful()) {
            throw new Exception('Failed to download Telegram media content');
        }

        return [
            'content' => base64_encode($contentResponse->body()),
            'mime_type' => $mediaInfo['mime_type'] ?? $contentResponse->header('Content-Type') ?? 'application/octet-stream',
        ];
    }

    /**
     * Download media from Facebook Messenger
     * Facebook provides direct URLs, so we just need to download
     */
    protected function downloadFromFacebook(array $mediaInfo, PlatformConnection $connection): array
    {
        $url = $mediaInfo['url'] ?? null;

        if (!$url) {
            throw new Exception('Missing Facebook media URL');
        }

        $contentResponse = Http::timeout(60)->get($url);

        if (!$contentResponse->successful()) {
            Log::error('Failed to download Facebook media', [
                'url' => $url,
                'status' => $contentResponse->status(),
            ]);
            throw new Exception('Failed to download Facebook media content');
        }

        return [
            'content' => base64_encode($contentResponse->body()),
            'mime_type' => $mediaInfo['mime_type'] ?? $contentResponse->header('Content-Type') ?? 'application/octet-stream',
        ];
    }

    /**
     * Download media from LINE
     */
    protected function downloadFromLine(array $mediaInfo, PlatformConnection $connection): array
    {
        $channelAccessToken = $connection->credentials['channel_access_token'] ?? null;
        $messageId = $mediaInfo['message_id'] ?? null;

        if (!$channelAccessToken || !$messageId) {
            throw new Exception('Missing LINE credentials or message ID');
        }

        $contentResponse = Http::withHeaders([
            'Authorization' => "Bearer {$channelAccessToken}",
        ])->timeout(60)->get(self::LINE_API_URL . "/{$messageId}/content");

        if (!$contentResponse->successful()) {
            Log::error('Failed to download LINE media', [
                'message_id' => $messageId,
                'status' => $contentResponse->status(),
            ]);
            throw new Exception('Failed to download LINE media content');
        }

        return [
            'content' => base64_encode($contentResponse->body()),
            'mime_type' => $mediaInfo['mime_type'] ?? $contentResponse->header('Content-Type') ?? 'application/octet-stream',
        ];
    }

    /**
     * Validate media size is within limits
     */
    public function validateMediaSize(string $base64Content, string $mediaType): bool
    {
        $sizeBytes = strlen(base64_decode($base64Content));
        $sizeMb = $sizeBytes / (1024 * 1024);

        $maxSize = match ($mediaType) {
            'image' => config('media.max_image_size_mb', 20),
            'audio' => config('media.max_audio_size_mb', 25),
            'video' => config('media.max_video_size_mb', 100),
            default => 20,
        };

        return $sizeMb <= $maxSize;
    }
}
