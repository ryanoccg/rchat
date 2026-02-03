<?php

namespace App\Observers;

use App\Models\Message;
use App\Models\AiConfiguration;
use App\Jobs\ProcessDelayedAiResponse;
use App\Jobs\ExtractCustomerInfoFromMessage;
use App\Services\Workflow\WorkflowTriggerService;
use Illuminate\Support\Facades\Log;

class MessageObserver
{
    /**
     * Handle the Message "created" event.
     * Uses delayed processing to batch multiple customer messages together.
     */
    public function created(Message $message): void
    {
        Log::info('MessageObserver: Message created', [
            'message_id' => $message->id,
            'sender_type' => $message->sender_type,
            'is_from_customer' => $message->is_from_customer,
            'content_preview' => substr($message->content ?: '', 0, 50),
        ]);

        // CRITICAL: Only process messages FROM CUSTOMERS, skip AI/agent messages
        if (in_array($message->sender_type, ['ai', 'agent', 'system'])) {
            Log::info('MessageObserver: Skipping - message from AI/agent/system', [
                'message_id' => $message->id,
                'sender_type' => $message->sender_type,
            ]);
            return;
        }

        // Double-check: must be from customer
        if ($message->sender_type !== 'customer' && !$message->is_from_customer) {
            Log::info('MessageObserver: Skipping - not from customer', [
                'message_id' => $message->id,
                'sender_type' => $message->sender_type,
                'is_from_customer' => $message->is_from_customer,
            ]);
            return;
        }

        // Extract and update customer info from message content
        // This runs for all customer messages (text and media)
        if (!empty($message->content)) {
            ExtractCustomerInfoFromMessage::dispatch($message);
        }

        $conversation = $message->conversation;

        Log::info('MessageObserver: Loading conversation', [
            'message_id' => $message->id,
            'conversation_id' => $conversation?->id,
            'is_ai_handling' => $conversation?->is_ai_handling,
            'message_type' => $message->message_type,
            'has_media' => !empty($message->media_urls),
        ]);

        // Skip AI response for media messages - let media processing trigger response later
        if ($message->message_type !== 'text' || !empty($message->media_urls)) {
            Log::info('MessageObserver: Skipping AI response - media message needs processing first', [
                'message_id' => $message->id,
                'message_type' => $message->message_type,
                'has_media' => !empty($message->media_urls),
            ]);
            return;
        }

        // Check if conversation should be handled by AI
        if (!$conversation || !$conversation->is_ai_handling) {
            Log::info('Message received but AI handling disabled', [
                'message_id' => $message->id,
                'conversation_id' => $conversation?->id,
            ]);
            return;
        }

        // Trigger workflows - this is now the SOLE entry point for AI responses
        // Workflows handle routing to appropriate AI agents/personalities
        // WorkflowTriggerService has fallback logic for companies without workflows
        try {
            app(WorkflowTriggerService::class)->onMessageReceived($message);
        } catch (\Throwable $e) {
            Log::error('Failed to trigger workflows for message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
