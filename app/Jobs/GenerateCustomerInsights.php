<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\CustomerInsightService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateCustomerInsights implements ShouldQueue
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
            Log::warning('GenerateCustomerInsights: Conversation not found', [
                'conversation_id' => $this->conversationId,
            ]);
            return;
        }

        try {
            $insightService = new CustomerInsightService();
            $insightService->analyzeAndTagCustomer($conversation);
        } catch (\Exception $e) {
            Log::warning('Customer insight generation failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
