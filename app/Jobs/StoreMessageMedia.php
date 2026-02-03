<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\PlatformConnection;
use App\Services\Media\MediaStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StoreMessageMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $messageId;
    public int $connectionId;
    public string $platform;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public array $backoff = [10, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(int $messageId, int $connectionId, string $platform)
    {
        $this->messageId = $messageId;
        $this->connectionId = $connectionId;
        $this->platform = $platform;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $message = Message::find($this->messageId);
        $connection = PlatformConnection::find($this->connectionId);

        if (!$message || !$connection) {
            Log::warning('StoreMessageMedia: Message or connection not found', [
                'message_id' => $this->messageId,
                'connection_id' => $this->connectionId,
            ]);
            return;
        }

        $mediaUrls = $message->media_urls ?? [];

        if (empty($mediaUrls)) {
            Log::info('StoreMessageMedia: No media to process', [
                'message_id' => $this->messageId,
            ]);
            return;
        }

        // Check if all media already has local URLs
        $needsProcessing = false;
        foreach ($mediaUrls as $media) {
            if (empty($media['local_url'])) {
                $needsProcessing = true;
                break;
            }
        }

        if (!$needsProcessing) {
            Log::info('StoreMessageMedia: All media already has local URLs', [
                'message_id' => $this->messageId,
            ]);
            return;
        }

        Log::info('StoreMessageMedia: Processing media', [
            'message_id' => $this->messageId,
            'platform' => $this->platform,
            'media_count' => count($mediaUrls),
        ]);

        $storageService = new MediaStorageService();
        $updatedMediaUrls = $storageService->processMessageMedia($mediaUrls, $connection, $this->platform);

        // Update message with local URLs
        $message->update([
            'media_urls' => $updatedMediaUrls,
        ]);

        // Log results
        $storedCount = 0;
        foreach ($updatedMediaUrls as $media) {
            if (!empty($media['local_url'])) {
                $storedCount++;
            }
        }

        Log::info('StoreMessageMedia: Completed', [
            'message_id' => $this->messageId,
            'total_media' => count($mediaUrls),
            'stored_count' => $storedCount,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('StoreMessageMedia: Job failed', [
            'message_id' => $this->messageId,
            'error' => $exception->getMessage(),
        ]);
    }
}
