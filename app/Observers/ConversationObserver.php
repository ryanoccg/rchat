<?php

namespace App\Observers;

use App\Models\Conversation;
use App\Services\Workflow\WorkflowTriggerService;
use Illuminate\Support\Facades\Log;

class ConversationObserver
{
    /**
     * Handle the Conversation "created" event.
     */
    public function created(Conversation $conversation): void
    {
        Log::channel('ai')->info('Conversationcreated', [
            'conversation_id' => $conversation->id,
            'company_id' => $conversation->company_id,
            'customer_id' => $conversation->customer_id,
        ]);

        // Trigger workflows for new conversation
        try {
            app(WorkflowTriggerService::class)->onConversationCreated($conversation);
        } catch (\Throwable $e) {
            Log::error('Failed to trigger workflows for new conversation', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle the Conversation "updated" event.
     */
    public function updated(Conversation $conversation): void
    {
        // Check if status changed to closed
        if ($conversation->wasChanged('status') && $conversation->status === 'closed') {
            Log::channel('ai')->info('Conversationclosed', [
                'conversation_id' => $conversation->id,
                'company_id' => $conversation->company_id,
                'closed_reason' => $conversation->closed_reason,
            ]);

            // Trigger workflows for closed conversation
            try {
                app(WorkflowTriggerService::class)->onConversationClosed($conversation);
            } catch (\Throwable $e) {
                Log::error('Failed to trigger workflows for closed conversation', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
