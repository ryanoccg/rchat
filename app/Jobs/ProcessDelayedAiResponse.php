<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\AiConfiguration;
use App\Models\Appointment;
use App\Services\AI\AiService;
use App\Services\Calendar\AppointmentService;
use App\Services\Messaging\MessageHandlerFactory;
use App\Services\CustomerInsightService;
use Carbon\Carbon;
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
     * Workflow-specific AI options (optional)
     */
    public ?string $systemPrompt;
    public ?string $promptTemplate;
    public ?string $additionalContext;
    public ?bool $enableProductSearch;
    public ?int $ragTopK;

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
     * @param string|null $systemPrompt Optional custom system prompt (from workflow)
     * @param string|null $promptTemplate Optional prompt template (from workflow)
     * @param string|null $additionalContext Optional additional instructions (from workflow)
     * @param bool|null $enableProductSearch Override agent's product search setting
     * @param int|null $ragTopK Override agent's RAG chunk count
     */
    public function __construct(
        int $conversationId,
        int $triggeredByMessageId,
        ?string $dispatchedAt = null,
        ?int $aiAgentId = null,
        ?string $systemPrompt = null,
        ?string $promptTemplate = null,
        ?string $additionalContext = null,
        ?bool $enableProductSearch = null,
        ?int $ragTopK = null
    ) {
        $this->conversationId = $conversationId;
        $this->triggeredByMessageId = $triggeredByMessageId;
        $this->dispatchedAt = $dispatchedAt ?? now()->toIso8601String();
        $this->aiAgentId = $aiAgentId;
        $this->systemPrompt = $systemPrompt;
        $this->promptTemplate = $promptTemplate;
        $this->additionalContext = $additionalContext;
        $this->enableProductSearch = $enableProductSearch;
        $this->ragTopK = $ragTopK;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (!$conversation) {
            Log::channel('ai')->warning('ProcessDelayedAiResponse: Conversation not found', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Check if there's a newer job scheduled for this conversation
        $latestJobKey = "ai_pending_job_{$this->conversationId}";
        $latestJobTime = Cache::get($latestJobKey);

        if ($latestJobTime && $latestJobTime !== $this->dispatchedAt) {
            Log::channel('ai')->info('ProcessDelayedAiResponse: Skipping - newer job exists', [
                'conversation_id' => $this->conversationId,
                'this_job_time' => $this->dispatchedAt,
                'latest_job_time' => $latestJobTime,
            ]);
            return;
        }

        // Clear the pending job marker and first message tracking
        Cache::forget($latestJobKey);
        Cache::forget("ai_first_message_{$this->conversationId}");

        // Check if conversation should still be handled by AI
        if (!$conversation->is_ai_handling) {
            Log::channel('ai')->info('ProcessDelayedAiResponse: Skipping - AI handling disabled', [
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
            Log::channel('ai')->info('ProcessDelayedAiResponse: No pending messages', [
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
            Log::channel('ai')->info('ProcessDelayedAiResponse: No text content in pending messages', [
                'conversation_id' => $this->conversationId,
                'message_count' => $pendingMessages->count(),
            ]);
            return;
        }

        Log::channel('ai')->info('ProcessDelayedAiResponse: Processing combined messages', [
            'conversation_id' => $this->conversationId,
            'triggered_by_message_id' => $this->triggeredByMessageId,
            'message_count' => $pendingMessages->count(),
            'message_ids' => $pendingMessages->pluck('id')->toArray(),
            'combined_length' => strlen($combinedContent),
            'batching_window' => $pendingMessages->count() > 1 ? 'MULTIPLE_MESSAGES_BATCHED' : 'single_message',
        ]);

        // Get AI configuration
        $aiConfig = AiConfiguration::where('company_id', $conversation->company_id)->first();

        if (!$aiConfig || !$aiConfig->auto_respond) {
            Log::channel('ai')->info('ProcessDelayedAiResponse: Auto-respond disabled', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        // Acquire processing lock
        $lockKey = "ai_processing_conversation_{$this->conversationId}";
        if (Cache::has($lockKey)) {
            Log::channel('ai')->info('ProcessDelayedAiResponse: Already processing', [
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

            // Pass workflow-specific AI options
            if ($this->aiAgentId) {
                $options['ai_agent_id'] = $this->aiAgentId;
            }
            if ($this->systemPrompt) {
                $options['system_prompt'] = $this->systemPrompt;
            }
            if ($this->promptTemplate) {
                $options['prompt_template'] = $this->promptTemplate;
            }
            if ($this->additionalContext) {
                $options['additional_context'] = $this->additionalContext;
            }
            if ($this->enableProductSearch !== null) {
                $options['enable_product_search'] = $this->enableProductSearch;
            }
            if ($this->ragTopK !== null) {
                $options['rag_top_k'] = $this->ragTopK;
            }

            $aiResponse = $aiService->respondToMessage($conversation, $combinedContent, $latestMessage, $options);

            // Get agent info for logging
            $agentType = $aiService->getAgentType();
            $agentConfig = $aiService->getAgentConfig();

            Log::channel('ai')->info('ProcessDelayedAiResponse: Agent selected', [
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

                // Process appointment booking if present
                $appointmentData = $this->extractAppointmentBooking($textContent);
                $bookedAppointment = null;

                Log::channel('ai')->info('ProcessDelayedAiResponse: Appointment extraction check', [
                    'conversation_id' => $conversation->id,
                    'has_appointment_data' => !empty($appointmentData),
                ]);

                if ($appointmentData) {
                    $bookedAppointment = $this->processAppointmentBooking(
                        $appointmentData,
                        $conversation
                    );
                    // Remove the booking tag from text content (always remove if found)
                    $textContent = $this->removeAppointmentBookingTags($textContent);

                    Log::channel('ai')->info('ProcessDelayedAiResponse: Appointment processing complete', [
                        'conversation_id' => $conversation->id,
                        'booked' => !empty($bookedAppointment),
                        'appointment_id' => $bookedAppointment?->id,
                    ]);
                }

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
                                    Log::channel('ai')->info('ProcessDelayedAiResponse: Product image sent', [
                                        'conversation_id' => $this->conversationId,
                                        'image_url' => $imageUrl,
                                    ]);
                                } catch (\Exception $e) {
                                    Log::channel('ai')->warning('ProcessDelayedAiResponse: Failed to send product image', [
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
                                    Log::channel('ai')->info('ProcessDelayedAiResponse: Product image sent', [
                                        'conversation_id' => $this->conversationId,
                                        'image_url' => $imageUrl,
                                    ]);
                                } catch (\Exception $e) {
                                    Log::channel('ai')->warning('ProcessDelayedAiResponse: Failed to send product image', [
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
                $messageMetadata = [
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
                ];

                // Add appointment info to metadata if one was booked
                if ($bookedAppointment) {
                    $messageMetadata['appointment_booked'] = [
                        'appointment_id' => $bookedAppointment->id,
                        'start_time' => $bookedAppointment->start_time->toDateTimeString(),
                        'customer_name' => $bookedAppointment->customer_name,
                    ];
                }

                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_type' => 'ai',
                    'sender_id' => null,
                    'is_from_customer' => false,
                    'content' => $textContent,
                    'message_type' => !empty($productImagesToSend) ? 'text_with_images' : 'text',
                    'media_urls' => !empty($productImagesToSend) ? array_map(fn($url) => ['type' => 'image', 'url' => $url], $productImagesToSend) : null,
                    'metadata' => $messageMetadata,
                ]);

                Log::channel('ai')->info('ProcessDelayedAiResponse: Response sent', [
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
     * @param string|null $systemPrompt Optional custom system prompt (from workflow)
     * @param string|null $promptTemplate Optional prompt template (from workflow)
     * @param string|null $additionalContext Optional additional instructions (from workflow)
     * @param bool|null $enableProductSearch Override agent's product search setting
     * @param int|null $ragTopK Override agent's RAG chunk count
     */
    public static function scheduleForConversation(
        int $conversationId,
        int $messageId,
        ?int $delaySeconds = null,
        ?int $aiAgentId = null,
        ?string $systemPrompt = null,
        ?string $promptTemplate = null,
        ?string $additionalContext = null,
        ?bool $enableProductSearch = null,
        ?int $ragTopK = null
    ): void {
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
        $firstMessageKey = "ai_first_message_{$conversationId}";

        // CRITICAL: Track the FIRST message ID for proper batching
        // If there's already a batching window in progress, use that first message ID
        // Otherwise, start a new batching window with this message
        $firstMessageId = Cache::get($firstMessageKey);
        if ($firstMessageId === null) {
            // No active batching window - this is the first message
            $firstMessageId = $messageId;
            Cache::put($firstMessageKey, $firstMessageId, max(120, $delaySeconds + 60));

            Log::channel('ai')->info('ProcessDelayedAiResponse: Starting new batching window', [
                'conversation_id' => $conversationId,
                'first_message_id' => $firstMessageId,
                'current_message_id' => $messageId,
            ]);
        } else {
            Log::channel('ai')->info('ProcessDelayedAiResponse: Extending existing batching window', [
                'conversation_id' => $conversationId,
                'first_message_id' => $firstMessageId,
                'current_message_id' => $messageId,
                'messages_to_batch' => $messageId - $firstMessageId + 1,
            ]);
        }

        // Store this job's timestamp as the latest
        // Cache duration should be longer than the max possible delay
        Cache::put($jobKey, $jobTime, max(120, $delaySeconds + 60));

        // Dispatch with delay - use the FIRST message ID for proper batching
        self::dispatch(
            $conversationId,
            $firstMessageId, // IMPORTANT: Use first message ID, not current message ID
            $jobTime,
            $aiAgentId,
            $systemPrompt,
            $promptTemplate,
            $additionalContext,
            $enableProductSearch,
            $ragTopK
        )->delay(now()->addSeconds($delaySeconds));

        Log::channel('ai')->info('ProcessDelayedAiResponse: Scheduled', [
            'conversation_id' => $conversationId,
            'trigger_message_id' => $firstMessageId,
            'current_message_id' => $messageId,
            'delay_seconds' => $delaySeconds,
            'ai_agent_id' => $aiAgentId,
            'has_workflow_options' => !is_null($systemPrompt) || !is_null($promptTemplate),
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

    /**
     * Extract appointment booking data from AI response
     * Looks for pattern: [BOOK_APPOINTMENT: date=YYYY-MM-DD, time=HH:MM, name=Name, phone=XXX, email=XXX]
     *
     * @param string $content
     * @return array|null Appointment data or null if not found
     */
    protected function extractAppointmentBooking(string $content): ?array
    {
        // Match [BOOK_APPOINTMENT: key=value, key=value, ...] pattern
        if (preg_match('/\[BOOK_APPOINTMENT:\s*([^\]]+)\]/i', $content, $matches)) {
            $params = $matches[1];
            $data = [];

            Log::channel('ai')->info('ProcessDelayedAiResponse: Found BOOK_APPOINTMENT tag', [
                'params' => $params,
            ]);

            // Parse key=value pairs - handle values with spaces better
            $parts = explode(',', $params);
            foreach ($parts as $part) {
                $part = trim($part);
                if (strpos($part, '=') !== false) {
                    list($key, $value) = explode('=', $part, 2);
                    $key = strtolower(trim($key));
                    $value = trim($value);
                    $data[$key] = $value;
                }
            }

            Log::channel('ai')->info('ProcessDelayedAiResponse: Parsed appointment data', [
                'data' => $data,
            ]);

            // Validate required fields
            if (!empty($data['date']) && !empty($data['time'])) {
                return $data;
            }

            Log::channel('ai')->warning('ProcessDelayedAiResponse: Invalid appointment booking data - missing date or time', [
                'raw' => $params,
                'parsed' => $data,
            ]);
        } else {
            Log::channel('ai')->debug('ProcessDelayedAiResponse: No BOOK_APPOINTMENT tag found', [
                'content_length' => strlen($content),
                'content_preview' => substr($content, 0, 500),
            ]);
        }

        return null;
    }

    /**
     * Process appointment booking and create the appointment
     *
     * @param array $appointmentData
     * @param Conversation $conversation
     * @return \App\Models\Appointment|null
     */
    protected function processAppointmentBooking(array $appointmentData, Conversation $conversation): ?\App\Models\Appointment
    {
        try {
            // Build start time from date and time
            $startTime = Carbon::parse($appointmentData['date'] . ' ' . $appointmentData['time']);
            $endTime = $startTime->copy()->addMinutes(60); // Default 1 hour

            // Prepare booking data
            $customerName = $appointmentData['name'] ?? $conversation->customer?->name ?? 'Customer';
            $customerEmail = !empty($appointmentData['email']) ? $appointmentData['email'] : $conversation->customer?->email;
            $customerPhone = !empty($appointmentData['phone']) ? $appointmentData['phone'] : $conversation->customer?->phone;

            // Check if Google Calendar is connected
            $calendarAvailable = AppointmentService::isAvailable($conversation->company_id);

            if ($calendarAvailable) {
                // Use AppointmentService for full booking with Google Calendar sync
                $bookingData = [
                    'start_time' => $startTime,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'title' => 'Appointment with ' . $customerName,
                    'notes' => 'Booked via AI chat',
                ];

                $appointmentService = new AppointmentService($conversation->company_id);
                $appointment = $appointmentService->bookAppointment(
                    $bookingData,
                    $conversation->customer,
                    $conversation
                );

                Log::channel('ai')->info('ProcessDelayedAiResponse: Appointment booked successfully (with Google Calendar)', [
                    'conversation_id' => $conversation->id,
                    'appointment_id' => $appointment->id,
                    'start_time' => $startTime->toDateTimeString(),
                    'customer_name' => $customerName,
                ]);

                return $appointment;
            } else {
                // Create local appointment without Google Calendar sync
                $appointment = Appointment::create([
                    'company_id' => $conversation->company_id,
                    'customer_id' => $conversation->customer?->id,
                    'conversation_id' => $conversation->id,
                    'google_event_id' => null, // No Google Calendar event
                    'title' => 'Appointment with ' . $customerName,
                    'description' => 'Booked via AI chat',
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone,
                    'status' => Appointment::STATUS_CONFIRMED,
                    'notes' => 'Booked via AI chat (local only - Google Calendar not connected)',
                    'metadata' => [
                        'booked_via' => 'ai_chat',
                        'local_only' => true,
                        'google_not_connected' => true,
                    ],
                ]);

                Log::channel('ai')->info('ProcessDelayedAiResponse: Appointment booked (local only)', [
                    'conversation_id' => $conversation->id,
                    'appointment_id' => $appointment->id,
                    'start_time' => $startTime->toDateTimeString(),
                    'customer_name' => $customerName,
                    'note' => 'Google Calendar not connected - created local appointment',
                ]);

                return $appointment;
            }
        } catch (\Exception $e) {
            Log::channel('ai')->error('ProcessDelayedAiResponse: Failed to book appointment', [
                'conversation_id' => $conversation->id,
                'appointment_data' => $appointmentData,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Remove appointment booking tags from AI response content
     *
     * @param string $content
     * @return string Clean text content
     */
    protected function removeAppointmentBookingTags(string $content): string
    {
        // Remove [BOOK_APPOINTMENT: ...] patterns and any surrounding whitespace
        $cleaned = preg_replace('/\[BOOK_APPOINTMENT:\s*[^\]]+\]\s*/i', '', $content);

        // Clean up any double newlines that might result
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);

        return trim($cleaned);
    }
}
