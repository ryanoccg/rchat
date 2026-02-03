<?php

namespace App\Services\Media;

use App\Models\Media;
use App\Models\PlatformConnection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class MediaLibraryService
{
    protected array $config;
    protected ImageManager $imageManager;

    public function __construct()
    {
        $this->config = config('media', []);
        $this->imageManager = new ImageManager(new Driver());
    }

    /**
     * Upload a file from an UploadedFile object
     *
     * @param UploadedFile $file The uploaded file
     * @param int $companyId Company ID
     * @param int $userId User ID who is uploading
     * @param array $options Additional options
     * @return Media
     */
    public function upload(UploadedFile $file, int $companyId, int $userId, array $options = []): Media
    {
        $mimeType = $file->getMimeType();
        $extension = $file->getClientOriginalExtension() ?: Media::getExtensionFromMime($mimeType);
        $fileName = $this->sanitizeFileName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));
        $fileSize = $file->getSize();

        // Validate file size
        $this->validateFileSize($fileSize, $mimeType);

        // Generate unique filename
        $uniqueFileName = $this->generateUniqueFileName($fileName, $extension);
        $folderPath = $options['folder'] ?? $this->getDefaultFolder($mimeType);
        $storagePath = "media/{$companyId}/{$folderPath}/" . $uniqueFileName;

        // Store the file
        $disk = $options['disk'] ?? 'public';
        $path = $file->storeAs("media/{$companyId}/{$folderPath}", $uniqueFileName, $disk);
        $url = Storage::disk($disk)->url($path);

        // Get media type
        $mediaType = Media::getMediaTypeFromMime($mimeType);
        $fileType = Media::getFileTypeFromMime($mimeType);

        // Prepare media data
        $mediaData = [
            'company_id' => $companyId,
            'file_name' => $fileName,
            'file_type' => $fileType,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'file_size' => $fileSize,
            'disk' => $disk,
            'path' => $path,
            'url' => $url,
            'media_type' => $mediaType,
            'folder_path' => $folderPath,
            'collection' => $options['collection'] ?? null,
            'uploaded_by' => $userId,
            'source' => $options['source'] ?? Media::SOURCE_DIRECT,
            'source_url' => $options['source_url'] ?? null,
            'alt' => $options['alt'] ?? null,
            'title' => $options['title'] ?? $fileName,
            'description' => $options['description'] ?? null,
            'caption' => $options['caption'] ?? null,
            'mediable_type' => $options['mediable_type'] ?? null,
            'mediable_id' => $options['mediable_id'] ?? null,
            'mediable_order' => $options['mediable_order'] ?? 0,
            'custom_properties' => $options['custom_properties'] ?? [],
        ];

        // Process image metadata and generate thumbnails
        if ($mediaType === Media::TYPE_IMAGE && $this->canProcessImages()) {
            $imageData = $this->processImage($path, $disk);
            $mediaData['metadata'] = $imageData['metadata'];
            $mediaData['thumbnail_url'] = $imageData['thumbnail_url'] ?? null;
            $mediaData['conversions'] = $imageData['conversions'] ?? [];
        }

        // Process video metadata
        if ($mediaType === Media::TYPE_VIDEO) {
            $mediaData['metadata'] = $this->getVideoMetadata($path, $disk);
        }

        // Process audio metadata
        if ($mediaType === Media::TYPE_AUDIO) {
            $mediaData['metadata'] = $this->getAudioMetadata($path, $disk);
        }

        // Create media record
        $media = Media::create($mediaData);

        // Optional: AI analysis
        if ($options['ai_analyze'] ?? true) {
            $this->dispatchAiAnalysis($media);
        }

        Log::info('Media uploaded successfully', [
            'media_id' => $media->id,
            'company_id' => $companyId,
            'file_name' => $media->file_name,
            'media_type' => $media->media_type,
        ]);

        return $media;
    }

    /**
     * Upload media from a platform (Facebook, WhatsApp, etc.)
     *
     * @param array $mediaInfo Media information from platform
     * @param PlatformConnection $connection Platform connection
     * @param string $platform Platform identifier
     * @param array $options Additional options
     * @return Media|null
     */
    public function uploadFromPlatform(
        array $mediaInfo,
        PlatformConnection $connection,
        string $platform,
        array $options = []
    ): ?Media {
        try {
            $downloader = new PlatformMediaDownloader();
            $downloadResult = $downloader->download($mediaInfo, $connection, $platform);

            if (empty($downloadResult['content'])) {
                Log::warning('Failed to download media from platform', [
                    'platform' => $platform,
                    'media_info' => $mediaInfo,
                ]);
                return null;
            }

            // Decode base64 content
            $content = base64_decode($downloadResult['content']);
            $mimeType = $downloadResult['mime_type'] ?? 'application/octet-stream';
            $extension = Media::getExtensionFromMime($mimeType);
            $mediaType = Media::getMediaTypeFromMime($mimeType);
            $fileType = Media::getFileTypeFromMime($mimeType);

            // Generate filename
            $fileName = $options['file_name'] ?? ($mediaInfo['file_name'] ?? 'platform-media');
            $fileName = $this->sanitizeFileName($fileName);
            $uniqueFileName = $this->generateUniqueFileName($fileName, $extension);
            $folderPath = $options['folder'] ?? $this->getDefaultFolder($mimeType);
            $companyId = $connection->company_id;

            $path = "media/{$companyId}/{$folderPath}/" . $uniqueFileName;
            $disk = 'public';

            Storage::disk($disk)->put($path, $content);
            $url = Storage::disk($disk)->url($path);

            $mediaData = [
                'company_id' => $companyId,
                'file_name' => $fileName,
                'file_type' => $fileType,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'file_size' => strlen($content),
                'disk' => $disk,
                'path' => $path,
                'url' => $url,
                'media_type' => $mediaType,
                'folder_path' => $folderPath,
                'collection' => $options['collection'] ?? 'messages',
                'source' => Media::SOURCE_PLATFORM,
                'source_url' => $mediaInfo['url'] ?? null,
                'custom_properties' => array_merge($options['custom_properties'] ?? [], [
                    'platform' => $platform,
                    'platform_media_id' => $mediaInfo['media_id'] ?? null,
                ]),
            ];

            // Process image
            if ($mediaType === Media::TYPE_IMAGE && $this->canProcessImages()) {
                $imageData = $this->processImage($path, $disk);
                $mediaData['metadata'] = $imageData['metadata'];
                $mediaData['thumbnail_url'] = $imageData['thumbnail_url'] ?? null;
            }

            return Media::create($mediaData);
        } catch (\Exception $e) {
            Log::error('Failed to upload media from platform', [
                'platform' => $platform,
                'error' => $e->getMessage(),
                'media_info' => $mediaInfo,
            ]);
            return null;
        }
    }

    /**
     * Import media from a URL
     *
     * @param string $url The URL to import from
     * @param int $companyId Company ID
     * @param int $userId User ID who is importing
     * @param array $options Additional options
     * @return Media|null
     */
    public function importFromUrl(string $url, int $companyId, int $userId, array $options = []): ?Media
    {
        try {
            // Fetch the file
            $response = \Illuminate\Support\Facades\Http::timeout(30)->get($url);

            if (!$response->successful()) {
                Log::warning('Failed to fetch media from URL', [
                    'url' => $url,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $content = $response->body();
            $mimeType = $response->header('Content-Type') ?? 'application/octet-stream';

            // If content type not detected, try from extension
            if ($mimeType === 'application/octet-stream') {
                $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
                $mimeMap = [
                    'jpg' => 'image/jpeg',
                    'jpeg' => 'image/jpeg',
                    'png' => 'image/png',
                    'gif' => 'image/gif',
                    'webp' => 'image/webp',
                    'pdf' => 'application/pdf',
                    'mp4' => 'video/mp4',
                    'mp3' => 'audio/mpeg',
                ];
                $mimeType = $mimeMap[$extension] ?? $mimeType;
            }

            $extension = Media::getExtensionFromMime($mimeType);
            $mediaType = Media::getMediaTypeFromMime($mimeType);
            $fileType = Media::getFileTypeFromMime($mimeType);

            // Generate filename
            $fileName = $options['file_name'] ?? pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME);
            $fileName = $this->sanitizeFileName($fileName);
            $uniqueFileName = $this->generateUniqueFileName($fileName, $extension);
            $folderPath = $options['folder'] ?? $this->getDefaultFolder($mimeType);

            $path = "media/{$companyId}/{$folderPath}/" . $uniqueFileName;
            $disk = 'public';

            Storage::disk($disk)->put($path, $content);
            $fileUrl = Storage::disk($disk)->url($path);

            $mediaData = [
                'company_id' => $companyId,
                'file_name' => $fileName,
                'file_type' => $fileType,
                'mime_type' => $mimeType,
                'extension' => $extension,
                'file_size' => strlen($content),
                'disk' => $disk,
                'path' => $path,
                'url' => $fileUrl,
                'media_type' => $mediaType,
                'folder_path' => $folderPath,
                'uploaded_by' => $userId,
                'source' => Media::SOURCE_IMPORT,
                'source_url' => $url,
            ];

            // Process image
            if ($mediaType === Media::TYPE_IMAGE && $this->canProcessImages()) {
                $imageData = $this->processImage($path, $disk);
                $mediaData['metadata'] = $imageData['metadata'];
                $mediaData['thumbnail_url'] = $imageData['thumbnail_url'] ?? null;
            }

            return Media::create($mediaData);
        } catch (\Exception $e) {
            Log::error('Failed to import media from URL', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Delete a media file
     */
    public function delete(Media $media): bool
    {
        try {
            // Delete the physical file
            if (Storage::disk($media->disk)->exists($media->path)) {
                Storage::disk($media->disk)->delete($media->path);
            }

            // Delete conversions
            if ($media->conversions) {
                foreach ($media->conversions as $conversion) {
                    $conversionPath = $conversion['path'] ?? null;
                    if ($conversionPath && Storage::disk($media->disk)->exists($conversionPath)) {
                        Storage::disk($media->disk)->delete($conversionPath);
                    }
                }
            }

            // Delete the model
            $media->delete();

            Log::info('Media deleted successfully', [
                'media_id' => $media->id,
                'file_name' => $media->file_name,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to delete media', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Bulk delete media files
     */
    public function bulkDelete(array $mediaIds): int
    {
        $mediaItems = Media::whereIn('id', $mediaIds)->get();
        $deleted = 0;

        foreach ($mediaItems as $media) {
            if ($this->delete($media)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Attach media to a model
     */
    public function attachToModel(Media $media, string $modelType, int $modelId, int $order = 0): bool
    {
        $media->update([
            'mediable_type' => $modelType,
            'mediable_id' => $modelId,
            'mediable_order' => $order,
        ]);

        return true;
    }

    /**
     * Detach media from any model
     */
    public function detachFromModel(Media $media): bool
    {
        $media->update([
            'mediable_type' => null,
            'mediable_id' => null,
            'mediable_order' => 0,
        ]);

        return true;
    }

    /**
     * Copy a media file
     */
    public function copy(Media $media, ?string $newFileName = null): ?Media
    {
        try {
            if (!Storage::disk($media->disk)->exists($media->path)) {
                return null;
            }

            $content = Storage::disk($media->disk)->get($media->path);
            $fileName = $newFileName ?? ($media->file_name . '-copy');
            $uniqueFileName = $this->generateUniqueFileName($fileName, $media->extension);
            $newPath = "media/{$media->company_id}/{$media->folder_path}/" . $uniqueFileName;

            Storage::disk($media->disk)->put($newPath, $content);
            $newUrl = Storage::disk($media->disk)->url($newPath);

            $newMedia = Media::create([
                'company_id' => $media->company_id,
                'file_name' => $fileName,
                'file_type' => $media->file_type,
                'mime_type' => $media->mime_type,
                'extension' => $media->extension,
                'file_size' => $media->file_size,
                'disk' => $media->disk,
                'path' => $newPath,
                'url' => $newUrl,
                'thumbnail_url' => $media->thumbnail_url,
                'media_type' => $media->media_type,
                'folder_path' => $media->folder_path,
                'collection' => $media->collection,
                'metadata' => $media->metadata,
                'custom_properties' => $media->custom_properties,
                'conversions' => $media->conversions,
                'alt' => $media->alt,
                'title' => $media->title,
                'description' => $media->description,
                'caption' => $media->caption,
                'uploaded_by' => auth()->id(),
                'source' => Media::SOURCE_DIRECT,
            ]);

            return $newMedia;
        } catch (\Exception $e) {
            Log::error('Failed to copy media', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Move media to a different folder
     */
    public function moveToFolder(Media $media, string $folderPath): bool
    {
        try {
            $oldPath = $media->path;
            $fileName = basename($oldPath);
            $newPath = "media/{$media->company_id}/{$folderPath}/" . $fileName;

            if ($oldPath === $newPath) {
                return true;
            }

            Storage::disk($media->disk)->move($oldPath, $newPath);

            $media->update([
                'path' => $newPath,
                'url' => Storage::disk($media->disk)->url($newPath),
                'folder_path' => $folderPath,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to move media', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get storage usage statistics for a company
     */
    public function getStorageUsage(int $companyId): array
    {
        $media = Media::where('company_id', $companyId);

        $totalSize = (clone $media)->sum('file_size');
        $totalFiles = $media->count();

        $byType = [
            Media::TYPE_IMAGE => (clone $media)->ofType(Media::TYPE_IMAGE)->count(),
            Media::TYPE_VIDEO => (clone $media)->ofType(Media::TYPE_VIDEO)->count(),
            Media::TYPE_AUDIO => (clone $media)->ofType(Media::TYPE_AUDIO)->count(),
            Media::TYPE_DOCUMENT => (clone $media)->ofType(Media::TYPE_DOCUMENT)->count(),
            Media::TYPE_FILE => (clone $media)->ofType(Media::TYPE_FILE)->count(),
        ];

        $sizeByType = [
            Media::TYPE_IMAGE => (clone $media)->ofType(Media::TYPE_IMAGE)->sum('file_size'),
            Media::TYPE_VIDEO => (clone $media)->ofType(Media::TYPE_VIDEO)->sum('file_size'),
            Media::TYPE_AUDIO => (clone $media)->ofType(Media::TYPE_AUDIO)->sum('file_size'),
            Media::TYPE_DOCUMENT => (clone $media)->ofType(Media::TYPE_DOCUMENT)->sum('file_size'),
            Media::TYPE_FILE => (clone $media)->ofType(Media::TYPE_FILE)->sum('file_size'),
        ];

        return [
            'total_size' => $totalSize,
            'total_files' => $totalFiles,
            'by_type' => $byType,
            'size_by_type' => $sizeByType,
            'human_size' => $this->formatBytes($totalSize),
        ];
    }

    /**
     * Process an image and generate thumbnails
     */
    protected function processImage(string $path, string $disk): array
    {
        $result = [
            'metadata' => [],
            'thumbnail_url' => null,
            'conversions' => [],
        ];

        try {
            $fullPath = Storage::disk($disk)->path($path);
            if (!file_exists($fullPath)) {
                return $result;
            }

            $image = $this->imageManager->read($fullPath);

            // Get image dimensions
            $result['metadata'] = [
                'width' => $image->width(),
                'height' => $image->height(),
                'orientation' => $image->width() >= $image->height() ? 'landscape' : 'portrait',
            ];

            // Generate thumbnails if enabled
            $thumbnailSizes = $this->config['thumbnail_sizes'] ?? [
                'thumbnail' => [150, 150],
                'small' => [300, 300],
                'medium' => [768, 768],
                'large' => [1024, 1024],
            ];

            $directory = dirname($path);
            $fileName = pathinfo($path, PATHINFO_FILENAME);
            $extension = pathinfo($path, PATHINFO_EXTENSION);

            foreach ($thumbnailSizes as $name => [$width, $height]) {
                try {
                    $thumbnailPath = "{$directory}/{$fileName}-{$name}.{$extension}";

                    // Check if thumbnail already exists
                    if (Storage::disk($disk)->exists($thumbnailPath)) {
                        $thumbnailUrl = Storage::disk($disk)->url($thumbnailPath);
                        $result['conversions'][$name] = [
                            'path' => $thumbnailPath,
                            'url' => $thumbnailUrl,
                            'width' => $width,
                            'height' => $height,
                        ];
                        continue;
                    }

                    // Create thumbnail
                    $thumbnail = $image->scale($width, $height);
                    $tempPath = sys_get_temp_dir() . '/' . $fileName . '-' . $name . '.' . $extension;
                    $thumbnail->save($tempPath);

                    Storage::disk($disk)->put($thumbnailPath, file_get_contents($tempPath));
                    unlink($tempPath);

                    $thumbnailUrl = Storage::disk($disk)->url($thumbnailPath);

                    $result['conversions'][$name] = [
                        'path' => $thumbnailPath,
                        'url' => $thumbnailUrl,
                        'width' => $width,
                        'height' => $height,
                    ];

                    // Set default thumbnail
                    if ($name === 'thumbnail') {
                        $result['thumbnail_url'] = $thumbnailUrl;
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to generate thumbnail', [
                        'path' => $path,
                        'size' => $name,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to process image', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * Get video metadata
     */
    protected function getVideoMetadata(string $path, string $disk): array
    {
        return [
            'width' => null,
            'height' => null,
            'duration' => null,
        ];
    }

    /**
     * Get audio metadata
     */
    protected function getAudioMetadata(string $path, string $disk): array
    {
        return [
            'duration' => null,
            'bitrate' => null,
        ];
    }

    /**
     * Dispatch AI analysis job
     */
    protected function dispatchAiAnalysis(Media $media): void
    {
        // Only analyze images for now
        if ($media->media_type !== Media::TYPE_IMAGE) {
            return;
        }

        dispatch(function () use ($media) {
            try {
                $processor = new \App\Services\Media\Processors\ImageProcessor();
                $fullPath = Storage::disk($media->disk)->path($media->path);
                $imageContent = base64_encode(file_get_contents($fullPath));

                $analysis = $processor->process($imageContent, $media->mime_type, [
                    'prompt' => 'Describe this image in detail. Include: main subjects, colors, mood, setting, and any text visible.',
                ]);

                if ($analysis->isSuccessful()) {
                    $media->update([
                        'ai_analysis' => $analysis->getTextContent(),
                        'ai_tags' => $this->extractTags($analysis->getTextContent()),
                    ]);
                }
            } catch (\Exception $e) {
                Log::warning('Failed to analyze media with AI', [
                    'media_id' => $media->id,
                    'error' => $e->getMessage(),
                ]);
            }
        })->afterResponse();
    }

    /**
     * Extract tags from AI analysis text
     */
    protected function extractTags(string $text): array
    {
        // Simple tag extraction - can be improved
        $words = str_word_count(strtolower($text), 1);
        $stopWords = ['the', 'a', 'an', 'is', 'are', 'was', 'were', 'this', 'that', 'with', 'from', 'have', 'has', 'been', 'image', 'shows', 'visible'];

        return array_values(array_unique(array_filter($words, function ($word) use ($stopWords) {
            return strlen($word) > 3 && !in_array($word, $stopWords);
        })));
    }

    /**
     * Sanitize file name
     */
    protected function sanitizeFileName(string $fileName): string
    {
        // Remove special characters, keep spaces, dashes, underscores
        $fileName = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $fileName);
        $fileName = preg_replace('/\s+/', '-', $fileName);
        $fileName = trim($fileName, '-_');

        return $fileName ?: 'file';
    }

    /**
     * Generate unique filename
     */
    protected function generateUniqueFileName(string $fileName, string $extension): string
    {
        $base = Str::slug($fileName);
        $counter = 1;
        $uniqueName = $base . '.' . $extension;

        // Simple check - in production, check against database
        while (strlen($uniqueName) > 255) {
            $base = substr($base, 0, -1);
            $uniqueName = $base . '.' . $extension;
        }

        return $uniqueName;
    }

    /**
     * Get default folder for media type
     */
    protected function getDefaultFolder(string $mimeType): string
    {
        $mediaType = Media::getMediaTypeFromMime($mimeType);

        return match ($mediaType) {
            Media::TYPE_IMAGE => 'images',
            Media::TYPE_VIDEO => 'videos',
            Media::TYPE_AUDIO => 'audio',
            Media::TYPE_DOCUMENT => 'documents',
            default => 'files',
        };
    }

    /**
     * Validate file size
     */
    protected function validateFileSize(int $size, string $mimeType): void
    {
        $maxSize = $this->getMaxFileSize($mimeType);
        $maxSizeBytes = $maxSize * 1024 * 1024;

        if ($size > $maxSizeBytes) {
            throw new \InvalidArgumentException("File size exceeds maximum allowed size of {$maxSize}MB");
        }
    }

    /**
     * Get max file size for MIME type
     */
    protected function getMaxFileSize(string $mimeType): int
    {
        $mediaType = Media::getMediaTypeFromMime($mimeType);

        return match ($mediaType) {
            Media::TYPE_IMAGE => (int) ($this->config['max_image_size_mb'] ?? 20),
            Media::TYPE_VIDEO => (int) ($this->config['max_video_size_mb'] ?? 100),
            Media::TYPE_AUDIO => (int) ($this->config['max_audio_size_mb'] ?? 25),
            default => (int) ($this->config['max_file_size_mb'] ?? 50),
        };
    }

    /**
     * Check if image processing is available
     */
    protected function canProcessImages(): bool
    {
        return $this->config['image_processing_enabled'] ?? true;
    }

    /**
     * Format bytes to human readable
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
