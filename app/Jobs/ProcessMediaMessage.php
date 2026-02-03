<?php

namespace App\Jobs;

use App\Models\Message;
use App\Models\PlatformConnection;
use App\Services\Media\MediaProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMediaMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * The maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Message $message,
        public PlatformConnection $platformConnection,
        public string $platform,
        public array $options = []
    ) {
        $this->onQueue('media-processing');
    }

    /**
     * Execute the job.
     */
    public function handle(MediaProcessingService $mediaProcessingService): void
    {
        Log::info('Processing media message', [
            'message_id' => $this->message->id,
            'platform' => $this->platform,
            'message_type' => $this->message->message_type,
        ]);

        try {
            $results = $mediaProcessingService->processMessageMedia(
                $this->message,
                $this->platformConnection,
                $this->platform,
                $this->options
            );

            Log::info('Media processing completed', [
                'message_id' => $this->message->id,
                'results_count' => count($results),
                'results' => collect($results)->map(fn($r) => [
                    'id' => $r->id,
                    'media_type' => $r->media_type,
                    'status' => $r->status,
                    'processor' => $r->processor,
                ])->toArray(),
            ]);

            // If we have successful transcription/description, update the message
            $this->updateMessageWithMediaContext($mediaProcessingService);
        } catch (\Exception $e) {
            Log::error('Media processing job failed', [
                'message_id' => $this->message->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Update the message with processed media context
     */
    protected function updateMessageWithMediaContext(MediaProcessingService $mediaProcessingService): void
    {
        $mediaText = $mediaProcessingService->getMediaTextContent($this->message);
        $metadata = $this->message->metadata ?? [];
        $metadata['media_processed'] = true;
        $metadata['processed_at'] = now()->toIso8601String();

        // Check for failed audio processing - add friendly error context
        $failedResults = $this->message->mediaProcessingResults()
            ->where('status', 'failed')
            ->get();

        $hasFailedAudio = $failedResults->where('media_type', 'audio')->isNotEmpty();

        if ($mediaText) {
            $metadata['media_text'] = $mediaText;

            Log::info('Message updated with media context', [
                'message_id' => $this->message->id,
                'media_text_length' => strlen($mediaText),
            ]);
        } elseif ($hasFailedAudio) {
            // Provide context for AI that audio processing failed
            $metadata['media_text'] = '[Audio message could not be transcribed - please ask the customer to speak again or type their message]';
            $metadata['media_error'] = 'audio_transcription_failed';

            Log::warning('Audio transcription failed, adding error context for AI', [
                'message_id' => $this->message->id,
            ]);
        }

        $this->message->update(['metadata' => $metadata]);

        // Trigger AI response now that media is processed
        $this->triggerAiResponse();
    }

    /**
     * Trigger AI response after media processing is complete
     *
     * Phase 1.3: Changed to use WorkflowTriggerService::onMessageReceived()
     * instead of scheduling ProcessDelayedAiResponse directly.
     * This ensures audio/image messages go through workflow conditions (VIP detection, personality selection).
     */
    protected function triggerAiResponse(): void
    {
        $conversation = $this->message->conversation;

        if (!$conversation) {
            Log::warning('ProcessMediaMessage: Cannot trigger AI - conversation not found', [
                'message_id' => $this->message->id,
            ]);
            return;
        }

        // Check if conversation should be handled by AI
        if (!$conversation->is_ai_handling) {
            Log::info('ProcessMediaMessage: Skipping AI response - AI handling disabled', [
                'message_id' => $this->message->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        // Get AI configuration for the company
        $aiConfig = \App\Models\AiConfiguration::where('company_id', $conversation->company_id)->first();

        if (!$aiConfig || !$aiConfig->auto_respond) {
            Log::info('ProcessMediaMessage: Skipping AI response - auto-respond disabled', [
                'message_id' => $this->message->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        // Use WorkflowTriggerService to go through workflow conditions
        // This ensures VIP detection, personality selection, and other workflow rules apply to media messages
        $workflowTriggerService = new \App\Services\Workflow\WorkflowTriggerService();
        $workflowTriggerService->onMessageReceived($this->message);

        Log::info('ProcessMediaMessage: Workflow trigger invoked after media processing', [
            'message_id' => $this->message->id,
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessMediaMessage job failed permanently', [
            'message_id' => $this->message->id,
            'platform' => $this->platform,
            'error' => $exception->getMessage(),
        ]);

        // Update any pending results to failed status
        $this->message->mediaProcessingResults()
            ->whereIn('status', ['pending', 'processing'])
            ->update([
                'status' => 'failed',
                'error_message' => 'Job failed: ' . $exception->getMessage(),
                'processed_at' => now(),
            ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'media-processing',
            "message:{$this->message->id}",
            "platform:{$this->platform}",
            "type:{$this->message->message_type}",
        ];
    }
}
