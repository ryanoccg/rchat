<?php

namespace App\Services\Media;

use App\Models\PlatformConnection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class MediaStorageService
{
    protected PlatformMediaDownloader $downloader;

    public function __construct(?PlatformMediaDownloader $downloader = null)
    {
        $this->downloader = $downloader ?? new PlatformMediaDownloader();
    }

    /**
     * Download media from platform and store locally
     *
     * @param array $mediaInfo Media information (url, file_id, type, mime_type)
     * @param PlatformConnection $connection Platform connection with credentials
     * @param string $platform Platform identifier (facebook, telegram, whatsapp, line)
     * @param int $companyId Company ID for organizing storage
     * @return array|null Returns array with local_url, or null on failure
     */
    public function downloadAndStore(array $mediaInfo, PlatformConnection $connection, string $platform, int $companyId): ?array
    {
        try {
            // Skip if no media identifier
            if (empty($mediaInfo['url']) && empty($mediaInfo['file_id']) && empty($mediaInfo['media_id']) && empty($mediaInfo['message_id'])) {
                Log::warning('MediaStorageService: No media identifier found', [
                    'media_info' => $mediaInfo,
                ]);
                return null;
            }

            // Download media from platform
            $downloadResult = $this->downloader->download($mediaInfo, $connection, $platform);

            if (empty($downloadResult['content'])) {
                Log::warning('MediaStorageService: Download returned empty content', [
                    'platform' => $platform,
                ]);
                return null;
            }

            // Generate storage path
            $mediaType = $mediaInfo['type'] ?? 'file';
            $mimeType = $downloadResult['mime_type'] ?? $mediaInfo['mime_type'] ?? 'application/octet-stream';
            $extension = $this->getExtensionFromMimeType($mimeType);
            $filename = Str::uuid() . '.' . $extension;
            $path = "media/{$companyId}/{$mediaType}s/{$filename}";

            // Decode base64 content
            $content = base64_decode($downloadResult['content']);

            // Store in public disk
            Storage::disk('public')->put($path, $content);

            // Generate public URL
            $localUrl = Storage::disk('public')->url($path);

            Log::info('MediaStorageService: Media stored successfully', [
                'platform' => $platform,
                'media_type' => $mediaType,
                'path' => $path,
                'size' => strlen($content),
            ]);

            return [
                'local_url' => $localUrl,
                'local_path' => $path,
                'mime_type' => $mimeType,
                'size' => strlen($content),
            ];

        } catch (\Exception $e) {
            Log::error('MediaStorageService: Failed to download and store media', [
                'platform' => $platform,
                'media_info' => $mediaInfo,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Process and store all media items from a message
     *
     * @param array $mediaUrls Array of media info from message
     * @param PlatformConnection $connection Platform connection
     * @param string $platform Platform identifier
     * @return array Updated media_urls with local URLs
     */
    public function processMessageMedia(array $mediaUrls, PlatformConnection $connection, string $platform): array
    {
        if (empty($mediaUrls)) {
            return [];
        }

        $companyId = $connection->company_id;
        $updatedMedia = [];

        foreach ($mediaUrls as $mediaInfo) {
            // Already has a local URL? Skip downloading
            if (!empty($mediaInfo['local_url'])) {
                $updatedMedia[] = $mediaInfo;
                continue;
            }

            // Try to download and store
            $result = $this->downloadAndStore($mediaInfo, $connection, $platform, $companyId);

            if ($result) {
                // Merge local URL with original media info
                $mediaInfo['local_url'] = $result['local_url'];
                $mediaInfo['local_path'] = $result['local_path'];
                // Use local_url as the primary URL for display
                $mediaInfo['url'] = $result['local_url'];
            }

            $updatedMedia[] = $mediaInfo;
        }

        return $updatedMedia;
    }

    /**
     * Delete stored media file
     */
    public function delete(string $path): bool
    {
        try {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
                return true;
            }
            return false;
        } catch (\Exception $e) {
            Log::error('MediaStorageService: Failed to delete media', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get file extension from MIME type
     */
    protected function getExtensionFromMimeType(string $mimeType): string
    {
        // Remove parameters from mime type
        $mimeType = explode(';', $mimeType)[0];
        $mimeType = trim($mimeType);

        return match ($mimeType) {
            // Images
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',

            // Audio
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/wav' => 'wav',
            'audio/webm' => 'weba',
            'audio/m4a', 'audio/x-m4a', 'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',

            // Video
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
            'video/quicktime' => 'mov',

            // Documents
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',

            default => 'bin',
        };
    }
}
