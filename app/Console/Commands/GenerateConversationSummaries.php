<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Services\AI\ConversationContextService;
use Illuminate\Console\Command;

class GenerateConversationSummaries extends Command
{
    protected $signature = 'conversations:summarize 
                            {--conversation= : Specific conversation ID to summarize}
                            {--all : Summarize all conversations with many messages}
                            {--threshold=20 : Message threshold for summarization}';

    protected $description = 'Generate AI summaries for long conversations to save tokens';

    public function handle()
    {
        $threshold = (int) $this->option('threshold');
        $conversationId = $this->option('conversation');
        
        if ($conversationId) {
            $conversations = Conversation::where('id', $conversationId)->get();
        } elseif ($this->option('all')) {
            $conversations = Conversation::withCount('messages')
                ->having('messages_count', '>=', $threshold)
                ->whereDoesntHave('summaries', function ($q) {
                    $q->where('updated_at', '>=', now()->subDay());
                })
                ->limit(50)
                ->get();
        } else {
            $this->error('Please specify --conversation=ID or --all');
            return 1;
        }
        
        $this->info("Processing {$conversations->count()} conversations...");
        
        $contextService = new ConversationContextService();
        $processed = 0;
        
        foreach ($conversations as $conversation) {
            $this->line("Processing conversation #{$conversation->id}...");
            
            try {
                // This will trigger summary generation
                $contextService->buildOptimizedHistory($conversation, '');
                $processed++;
                $this->info("  ✓ Summarized");
            } catch (\Exception $e) {
                $this->error("  ✗ Failed: {$e->getMessage()}");
            }
        }
        
        $this->info("Done! Processed {$processed} conversations.");
        
        return 0;
    }
}
