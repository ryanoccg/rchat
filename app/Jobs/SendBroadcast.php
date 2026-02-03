<?php

namespace App\Jobs;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Message;
use App\Services\Messaging\MessageHandlerFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class SendBroadcast implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $broadcastId;
    public int $tries = 3;
    public int $timeout = 300; // 5 minutes max for sending a batch

    // Batch size for sending messages
    private const BATCH_SIZE = 50;
    // Delay between batches in seconds (to respect API rate limits)
    private const BATCH_DELAY = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(int $broadcastId)
    {
        $this->broadcastId = $broadcastId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $broadcast = Broadcast::find($this->broadcastId);

        if (!$broadcast) {
            Log::warning('SendBroadcast: Broadcast not found', [
                'broadcast_id' => $this->broadcastId,
            ]);
            return;
        }

        // Check if broadcast was cancelled
        if ($broadcast->status === 'cancelled') {
            Log::info('SendBroadcast: Broadcast cancelled, skipping', [
                'broadcast_id' => $this->broadcastId,
            ]);
            return;
        }

        // Check if already completed
        if (in_array($broadcast->status, ['completed', 'failed'])) {
            Log::info('SendBroadcast: Broadcast already processed', [
                'broadcast_id' => $this->broadcastId,
                'status' => $broadcast->status,
            ]);
            return;
        }

        // Verify platform connection is still active
        if (!$broadcast->platformConnection || !$broadcast->platformConnection->is_active) {
            $broadcast->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            Log::error('SendBroadcast: Platform connection not active', [
                'broadcast_id' => $this->broadcastId,
            ]);
            return;
        }

        // Acquire lock to prevent duplicate processing
        $lockKey = "broadcast_sending_{$this->broadcastId}";
        if (Cache::has($lockKey)) {
            Log::info('SendBroadcast: Already processing', [
                'broadcast_id' => $this->broadcastId,
            ]);
            return;
        }

        Cache::put($lockKey, true, 600); // 10 minutes

        try {
            $this->processBroadcast($broadcast);
        } catch (\Exception $e) {
            Log::error('SendBroadcast: Failed', [
                'broadcast_id' => $this->broadcastId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Check if we should fail the entire broadcast
            $pendingCount = $broadcast->recipients()->where('status', 'pending')->count();
            if ($pendingCount === $broadcast->total_recipients) {
                // Nothing was sent, mark as failed
                $broadcast->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                ]);
            }
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Process the broadcast - send messages to recipients
     */
    protected function processBroadcast(Broadcast $broadcast): void
    {
        $connection = $broadcast->platformConnection;
        $platform = $connection->messagingPlatform->slug;
        $handler = MessageHandlerFactory::create($platform);

        // Get pending recipients in batches
        $pendingRecipients = $broadcast->recipients()
            ->with(['customer', 'conversation'])
            ->where('status', 'pending')
            ->limit(self::BATCH_SIZE)
            ->get();

        if ($pendingRecipients->isEmpty()) {
            // No more recipients, mark as completed
            $broadcast->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('SendBroadcast: Completed', [
                'broadcast_id' => $this->broadcastId,
                'sent_count' => $broadcast->sent_count,
                'failed_count' => $broadcast->failed_count,
            ]);
            return;
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($pendingRecipients as $recipient) {
            try {
                $this->sendToRecipient($broadcast, $recipient, $handler);
                $successCount++;
            } catch (\Exception $e) {
                $failureCount++;
                Log::warning('SendBroadcast: Failed to send to recipient', [
                    'broadcast_id' => $this->broadcastId,
                    'recipient_id' => $recipient->id,
                    'customer_id' => $recipient->customer_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Update broadcast counters
        $broadcast->increment('sent_count', $successCount);
        $broadcast->increment('failed_count', $failureCount);

        // Check if there are more recipients to process
        $remainingCount = $broadcast->recipients()->where('status', 'pending')->count();

        if ($remainingCount > 0) {
            // Dispatch next batch
            self::dispatch($this->broadcastId)
                ->delay(now()->addSeconds(self::BATCH_DELAY));

            Log::info('SendBroadcast: Batch processed, next batch queued', [
                'broadcast_id' => $this->broadcastId,
                'success' => $successCount,
                'failed' => $failureCount,
                'remaining' => $remainingCount,
            ]);
        } else {
            // All recipients processed
            $broadcast->update([
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            Log::info('SendBroadcast: All recipients processed', [
                'broadcast_id' => $this->broadcastId,
                'sent_count' => $broadcast->sent_count,
                'failed_count' => $broadcast->failed_count,
            ]);
        }
    }

    /**
     * Send message to a single recipient
     */
    protected function sendToRecipient(
        Broadcast $broadcast,
        BroadcastRecipient $recipient,
        mixed $handler
    ): void {
        $customer = $recipient->customer;
        $conversation = $recipient->conversation;

        // Mark as sending
        $recipient->update(['status' => 'sending']);

        $platformMessageId = null;

        try {
            if ($broadcast->message_type === 'image' && !empty($broadcast->media_urls)) {
                // Send image(s)
                foreach ($broadcast->media_urls as $imageUrl) {
                    $result = $handler->sendImage(
                        $broadcast->platformConnection,
                        $customer->platform_user_id,
                        $imageUrl,
                        $broadcast->message // Use broadcast message as caption
                    );
                    $platformMessageId = $result['message_id'] ?? null;
                }
            } else {
                // Send text message
                $result = $handler->sendMessage(
                    $broadcast->platformConnection,
                    $customer->platform_user_id,
                    $broadcast->message
                );
                $platformMessageId = $result['message_id'] ?? null;
            }

            // Mark as sent
            $recipient->markAsSent($platformMessageId);

            // Create message record in conversation
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'agent',
                'sender_id' => $broadcast->user_id,
                'is_from_customer' => false,
                'content' => $broadcast->message,
                'message_type' => $broadcast->message_type,
                'media_urls' => $broadcast->media_urls,
                'platform_message_id' => $platformMessageId,
                'metadata' => [
                    'broadcast_id' => $broadcast->id,
                    'broadcast_name' => $broadcast->name,
                    'is_broadcast' => true,
                ],
            ]);

        } catch (\Exception $e) {
            $recipient->markAsFailed($e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendBroadcast job failed', [
            'broadcast_id' => $this->broadcastId,
            'error' => $exception->getMessage(),
        ]);

        Cache::forget("broadcast_sending_{$this->broadcastId}");
    }
}
