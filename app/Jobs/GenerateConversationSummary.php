<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\ConversationSummaryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateConversationSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $conversationId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $conversationId)
    {
        $this->conversationId = $conversationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $conversation = Conversation::find($this->conversationId);

        if (!$conversation) {
            Log::warning('GenerateConversationSummary: Conversation not found', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        try {
            $summaryService = new ConversationSummaryService();
            $summaryService->generateSummary($conversation);
            Log::info('GenerateConversationSummary: Conversation summary updated', [
                'conversation_id' => $conversation->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Conversation summary generation failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
