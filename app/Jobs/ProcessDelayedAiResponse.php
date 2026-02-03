<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\AiConfiguration;
use App\Services\AI\AiService;
use App\Services\Messaging\MessageHandlerFactory;
use App\Services\CustomerInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessDelayedAiResponse implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $conversationId;
    public int $triggeredByMessageId;
    public string $dispatchedAt;
    public ?int $aiAgentId;

    /**
     * Default number of seconds to wait before processing (if not configured)
     * Recommended: 30 seconds to allow message batching
     */
    public const DEFAULT_DELAY_SECONDS = 30;

    /**
     * Create a new job instance.
     *
     * @param int $conversationId
     * @param int $triggeredByMessageId
     * @param string|null $dispatchedAt
     * @param int|null $aiAgentId Optional specific AI agent to use (for workflow integration)
     */
    public function __construct(int $conversationId, int $triggeredByMessageId, ?string $dispatchedAt = null, ?int $aiAgentId = null)
    {
        $this->conversationId = $conversationId;
        $this->triggeredByMessageId = $triggeredByMessageId;
        $this->dispatchedAt = $dispatchedAt ?? now()->toIso8601String();
        $this->aiAgentId = $aiAgentId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (!$conversation) {
            Log::warning('ProcessDelayedAiResponse: Conversation not found', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Check if there's a newer job scheduled for this conversation
        $latestJobKey = "ai_pending_job_{$this->conversationId}";
        $latestJobTime = Cache::get($latestJobKey);

        if ($latestJobTime && $latestJobTime !== $this->dispatchedAt) {
            Log::info('ProcessDelayedAiResponse: Skipping - newer job exists', [
                'conversation_id' => $this->conversationId,
                'this_job_time' => $this->dispatchedAt,
                'latest_job_time' => $latestJobTime,
            ]);
            return;
        }

        // Clear the pending job marker
        Cache::forget($latestJobKey);

        // Check if conversation should still be handled by AI
        if (!$conversation->is_ai_handling) {
            Log::info('ProcessDelayedAiResponse: Skipping - AI handling disabled', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Get all unprocessed customer messages since the trigger
        $pendingMessages = Message::where('conversation_id', $this->conversationId)
            ->where('sender_type', 'customer')
            ->where('id', '>=', $this->triggeredByMessageId)
            ->whereNull('ai_processed_at')
            ->orderBy('created_at', 'asc')
            ->get();

        if ($pendingMessages->isEmpty()) {
            Log::info('ProcessDelayedAiResponse: No pending messages', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Combine all pending messages into one context (including media text and reply context)
        $combinedContent = $pendingMessages->map(function ($message) {
            $parts = [];
            $metadata = $message->metadata ?? [];

            // Add reply/quote context if present (for WhatsApp, Telegram, Facebook replies)
            if (!empty($metadata['reply_to']['text'])) {
                $quotedText = Str::limit($metadata['reply_to']['text'], 200);
                $parts[] = "[Replying to message: \"{$quotedText}\"]";
            }

            // Add the text content if present
            if (!empty($message->content)) {
                $parts[] = $message->content;
            }

            // Add media text if present (from image/audio processing)
            if (!empty($metadata['media_text'])) {
                $mediaType = $message->message_type ?? 'media';
                $parts[] = "[{$mediaType} content: {$metadata['media_text']}]";
            }

            return implode("\n", $parts);
        })->filter()->implode("\n");

        if (empty(trim($combinedContent))) {
            Log::info('ProcessDelayedAiResponse: No text content in pending messages', [
                'conversation_id' => $this->conversationId,
                'message_count' => $pendingMessages->count(),
            ]);
            return;
        }

        Log::info('ProcessDelayedAiResponse: Processing combined messages', [
            'conversation_id' => $this->conversationId,
            'message_count' => $pendingMessages->count(),
            'combined_length' => strlen($combinedContent),
        ]);

        // Get AI configuration
        $aiConfig = AiConfiguration::where('company_id', $conversation->company_id)->first();

        if (!$aiConfig || !$aiConfig->auto_respond) {
            Log::info('ProcessDelayedAiResponse: Auto-respond disabled', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Acquire processing lock
        $lockKey = "ai_processing_conversation_{$this->conversationId}";
        if (Cache::has($lockKey)) {
            Log::info('ProcessDelayedAiResponse: Already processing', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        Cache::put($lockKey, true, 30);

        try {
            // Get the latest message for context
            $latestMessage = $pendingMessages->last();

            // Generate AI response
            // If aiAgentId is set (from workflow), use that specific agent
            // Otherwise AiService will auto-select based on context
            $aiService = new AiService($conversation->company);
            $options = [];
            if ($this->aiAgentId) {
                $options['ai_agent_id'] = $this->aiAgentId;
            }
            $aiResponse = $aiService->respondToMessage($conversation, $combinedContent, $latestMessage, $options);

            // Get agent info for logging
            $agentType = $aiService->getAgentType();
            $agentConfig = $aiService->getAgentConfig();

            Log::info('ProcessDelayedAiResponse: Agent selected', [
                'conversation_id' => $this->conversationId,
                'agent_type' => $agentType,
                'agent_name' => $agentConfig['name'] ?? 'Default',
            ]);

            // Mark all pending messages as processed
            Message::whereIn('id', $pendingMessages->pluck('id'))
                ->update(['ai_processed_at' => now()]);

            if ($aiResponse->isSuccessful() && $aiResponse->getContent()) {
                // Parse AI response to extract product images
                $responseContent = $aiResponse->getContent();
                $productImages = $this->extractProductImages($responseContent);
                $textContent = $this->removeProductImageTags($responseContent);

                // Limit product images to maximum of 10 for both sending and storage
                $productImagesToSend = array_slice($productImages, 0, 10);

                // Send response
                $connection = $conversation->platformConnection;

                if ($connection && $connection->is_active) {
                    $platform = $connection->messagingPlatform->slug;
                    $handler = MessageHandlerFactory::create($platform);

                    try {

                        // For Facebook, WhatsApp, Telegram, LINE: send images first, then text
                        if (in_array($platform, ['facebook', 'whatsapp', 'telegram', 'line'])) {
                            foreach ($productImagesToSend as $imageUrl) {
                                try {
                                    $handler->sendImage(
                                        $connection,
                                        $conversation->customer->platform_user_id,
                                        $imageUrl
                                    );
                                    Log::info('ProcessDelayedAiResponse: Product image sent', [
                                        'conversation_id' => $this->conversationId,
                                        'image_url' => $imageUrl,
                                    ]);
                                } catch (\Exception $e) {
                                    Log::warning('ProcessDelayedAiResponse: Failed to send product image', [
                                        'conversation_id' => $this->conversationId,
                                        'image_url' => $imageUrl,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                            if (!empty(trim($textContent))) {
                                $handler->sendMessage(
                                    $connection,
                                    $conversation->customer->platform_user_id,
                                    $textContent
                                );
                            }
                        } else {
                            // Default: send text first, then images
                            if (!empty(trim($textContent))) {
                                $handler->sendMessage(
                                    $connection,
                                    $conversation->customer->platform_user_id,
                                    $textContent
                                );
                            }
                            foreach ($productImagesToSend as $imageUrl) {
                                try {
                                    $handler->sendImage(
                                        $connection,
                                        $conversation->customer->platform_user_id,
                                        $imageUrl
                                    );
                                    Log::info('ProcessDelayedAiResponse: Product image sent', [
                                        'conversation_id' => $this->conversationId,
                                        'image_url' => $imageUrl,
                                    ]);
                                } catch (\Exception $e) {
                                    Log::warning('ProcessDelayedAiResponse: Failed to send product image', [
                                        'conversation_id' => $this->conversationId,
                                        'image_url' => $imageUrl,
                                        'error' => $e->getMessage(),
                                    ]);
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('ProcessDelayedAiResponse: Failed to send', [
                            'conversation_id' => $this->conversationId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Store AI response (with original content including image tags for reference)
                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'ai',
                    'sender_id' => null,
                    'is_from_customer' => false,
                    'content' => $textContent,
                    'message_type' => !empty($productImagesToSend) ? 'text_with_images' : 'text',
                    'media_urls' => !empty($productImagesToSend) ? array_map(fn($url) => ['type' => 'image', 'url' => $url], $productImagesToSend) : null,
                    'metadata' => [
                        'ai_provider' => $agentConfig['primary_provider_id'] ?? ($aiConfig->primary_provider_id ?? null),
                        'model' => $agentConfig['primary_model'] ?? ($aiConfig->primary_model ?? null),
                        'agent_type' => $agentType,
                        'agent_name' => $agentConfig['name'] ?? null,
                        'agent_id' => $agentConfig['id'] ?? null,
                        'confidence' => $aiResponse->getConfidence(),
                        'auto_generated' => true,
                        'processed_messages' => $pendingMessages->pluck('id')->toArray(),
                        'product_images_sent' => count($productImagesToSend),
                        'product_images_total' => count($productImages), // Track total for reference
                    ],
                ]);

                Log::info('ProcessDelayedAiResponse: Response sent', [
                    'conversation_id' => $this->conversationId,
                    'messages_processed' => $pendingMessages->count(),
                ]);

                // Generate customer insights in background (non-blocking)
                // Note: afterResponse() doesn't work in queued jobs, so we dispatch directly
                \App\Jobs\GenerateCustomerInsights::dispatch($conversation->id);

                // Auto-generate/update conversation summary in background
                \App\Jobs\GenerateConversationSummary::dispatch($conversation->id);
            }
        } catch (\Exception $e) {
            Log::error('ProcessDelayedAiResponse: Failed', [
                'conversation_id' => $this->conversationId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            Cache::forget($lockKey);
        }
    }

    /**
     * Schedule a delayed AI response for a conversation
     *
     * @param int $conversationId
     * @param int $messageId
     * @param int|null $delaySeconds Optional custom delay (uses config or default if null)
     * @param int|null $aiAgentId Optional specific AI agent to use (for workflow integration)
     */
    public static function scheduleForConversation(int $conversationId, int $messageId, ?int $delaySeconds = null, ?int $aiAgentId = null): void
    {
        // Get the configured delay if not provided
        if ($delaySeconds === null) {
            $conversation = Conversation::find($conversationId);
            if ($conversation) {
                $aiConfig = AiConfiguration::where('company_id', $conversation->company_id)->first();
                $delaySeconds = $aiConfig?->response_delay_seconds ?? self::DEFAULT_DELAY_SECONDS;
            } else {
                $delaySeconds = self::DEFAULT_DELAY_SECONDS;
            }
        }

        $jobTime = now()->toIso8601String();
        $jobKey = "ai_pending_job_{$conversationId}";

        // Store this job's timestamp as the latest
        // Cache duration should be longer than the max possible delay
        Cache::put($jobKey, $jobTime, max(120, $delaySeconds + 60));

        // Dispatch with delay - pass the same jobTime to ensure matching
        self::dispatch($conversationId, $messageId, $jobTime, $aiAgentId)
            ->delay(now()->addSeconds($delaySeconds));

        Log::info('ProcessDelayedAiResponse: Scheduled', [
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'delay_seconds' => $delaySeconds,
            'ai_agent_id' => $aiAgentId,
        ]);
    }

    /**
     * Get the configured delay for a company
     *
     * @param int $companyId
     * @return int Delay in seconds
     */
    public static function getDelayForCompany(int $companyId): int
    {
        $aiConfig = AiConfiguration::where('company_id', $companyId)->first();
        return $aiConfig?->response_delay_seconds ?? self::DEFAULT_DELAY_SECONDS;
    }

    /**
     * Extract product image URLs from AI response
     * Looks for pattern: [PRODUCT_IMAGE: url]
     *
     * @param string $content
     * @return array Array of image URLs
     */
    protected function extractProductImages(string $content): array
    {
        $images = [];

        // Match [PRODUCT_IMAGE: url] pattern
        if (preg_match_all('/\[PRODUCT_IMAGE:\s*([^\]]+)\]/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $url = trim($url);
                // Validate it looks like a URL
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $images[] = $url;
                }
            }
        }

        return $images;
    }

    /**
     * Remove product image tags from AI response content
     *
     * @param string $content
     * @return string Clean text content
     */
    protected function removeProductImageTags(string $content): string
    {
        // Remove [PRODUCT_IMAGE: url] patterns and any surrounding whitespace
        $cleaned = preg_replace('/\[PRODUCT_IMAGE:\s*[^\]]+\]\s*/i', '', $content);

        // Clean up any double newlines that might result
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);

        return trim($cleaned);
    }
}
