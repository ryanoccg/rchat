<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Message;
use App\Services\AI\Providers\GeminiProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ConversationContextService
{
    // Token limits (approximate - 1 token ≈ 4 chars for English)
    protected const MAX_HISTORY_TOKENS = 1500;      // Reduced: ~6000 chars for history
    protected const MAX_MESSAGE_LENGTH = 300;       // Reduced: Truncate long individual messages
    protected const RECENT_MESSAGES_COUNT = 5;      // Reduced: Keep last 5 messages only
    protected const SUMMARY_THRESHOLD = 30;         // Reduced: Summarize when > 30 messages
    protected const ENABLE_AI_SUMMARY = false;      // DISABLED: Don't make extra API calls for summaries
    
    /**
     * Build optimized conversation history with token management
     * 
     * Strategy:
     * 1. Keep last N messages in full detail (most relevant)
     * 2. Summarize older messages to save tokens
     * 3. Truncate very long individual messages
     */
    public function buildOptimizedHistory(Conversation $conversation, string $currentMessage): array
    {
        $messages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->get();
        
        $totalMessages = $messages->count();

        Log::channel('ai')->info('ConversationContext: Building optimized history', [
            'conversation_id' => $conversation->id,
            'total_messages' => $totalMessages,
        ]);
        
        // If few messages, return all with truncation
        if ($totalMessages <= self::RECENT_MESSAGES_COUNT) {
            return $this->formatMessages($messages->reverse());
        }
        
        // Split into recent and older messages
        $recentMessages = $messages->take(self::RECENT_MESSAGES_COUNT)->reverse();
        $olderMessages = $messages->skip(self::RECENT_MESSAGES_COUNT);
        
        // Get or create summary for older messages
        $summary = $this->getOrCreateSummary($conversation, $olderMessages);
        
        $history = [];
        
        // Add summary as context if exists
        if ($summary) {
            $history[] = [
                'is_from_customer' => false,
                'content' => "[Previous conversation summary: {$summary}]",
                'is_summary' => true,
            ];
        }
        
        // Add recent messages in full
        foreach ($recentMessages as $msg) {
            $content = $this->truncateMessage($msg->content);

            // Include reply/quote context if present
            $replyTo = $msg->metadata['reply_to'] ?? null;
            if ($replyTo && !empty($replyTo['text'])) {
                $quotedText = Str::limit($replyTo['text'], 150);
                $content = "[Replying to: \"{$quotedText}\"] " . $content;
            }

            $history[] = [
                'is_from_customer' => $msg->is_from_customer,
                'content' => $content,
            ];
        }
        
        Log::channel('ai')->info('ConversationContext: History optimized', [
            'conversation_id' => $conversation->id,
            'summary_included' => !empty($summary),
            'recent_messages' => count($recentMessages),
            'estimated_tokens' => $this->estimateTokens($history),
        ]);

        return $history;
    }
    
    /**
     * Format messages with truncation
     */
    protected function formatMessages($messages): array
    {
        return $messages->map(fn($m) => [
            'is_from_customer' => $m->is_from_customer,
            'content' => $this->truncateMessage($m->content),
        ])->toArray();
    }
    
    /**
     * Truncate long messages to save tokens
     */
    protected function truncateMessage(string $content): string
    {
        if (strlen($content) <= self::MAX_MESSAGE_LENGTH * 4) { // ~500 tokens
            return $content;
        }
        
        // Keep first and last parts of long messages
        $halfLength = (self::MAX_MESSAGE_LENGTH * 4) / 2;
        $first = substr($content, 0, $halfLength);
        $last = substr($content, -$halfLength);
        
        return $first . "\n...[message truncated]...\n" . $last;
    }
    
    /**
     * Get existing summary or create new one
     */
    protected function getOrCreateSummary(Conversation $conversation, $olderMessages): ?string
    {
        // Check for existing summary
        $existingSummary = ConversationSummary::where('conversation_id', $conversation->id)
            ->latest()
            ->first();
        
        // If summary exists and covers these messages, use it
        if ($existingSummary && $this->isSummaryValid($existingSummary, $olderMessages)) {
            return $existingSummary->summary;
        }
        
        // Skip AI summary generation if disabled (saves API calls)
        if (!self::ENABLE_AI_SUMMARY) {
            return $this->createSimpleSummary($olderMessages);
        }
        
        // Generate new summary if needed
        if ($olderMessages->count() >= self::SUMMARY_THRESHOLD) {
            return $this->generateSummary($conversation, $olderMessages);
        }
        
        // For smaller message sets, create a simple summary
        return $this->createSimpleSummary($olderMessages);
    }
    
    /**
     * Check if existing summary is still valid
     */
    protected function isSummaryValid($summary, $messages): bool
    {
        // Summary is valid if it was created after the last message in the set
        $lastMessage = $messages->first(); // Already sorted desc
        return $summary->updated_at > $lastMessage->created_at;
    }
    
    /**
     * Generate AI summary for older messages (async/background preferred)
     */
    protected function generateSummary(Conversation $conversation, $messages): ?string
    {
        try {
            $provider = new GeminiProvider(null);
            
            // Build summary prompt
            $messageText = $messages->map(function ($m) {
                $role = $m->is_from_customer ? 'Customer' : 'Agent';
                $content = substr($m->content, 0, 200); // Limit each message
                return "{$role}: {$content}";
            })->implode("\n");
            
            // Limit total text for summary generation
            $messageText = substr($messageText, 0, 3000);
            
            $summaryPrompt = "Summarize this customer service conversation in 2-3 sentences. Focus on: the customer's main issue/question, any solutions discussed, and current status.\n\nConversation:\n{$messageText}\n\nSummary:";
            
            $response = $provider->sendMessage($summaryPrompt, [], [
                'model' => 'gemini-2.5-flash-lite',
                'max_tokens' => 150, // Short summary
                'temperature' => 0.3, // More factual
            ]);
            
            if ($response->isSuccessful()) {
                $summary = $response->getContent();
                
                // Save summary to database
                ConversationSummary::updateOrCreate(
                    ['conversation_id' => $conversation->id],
                    [
                        'summary' => $summary,
                        'key_points' => $this->extractKeyPoints($messages),
                        'is_ai_generated' => true,
                    ]
                );

                Log::channel('ai')->info('ConversationContext: AI summary generated', [
                    'conversation_id' => $conversation->id,
                    'summary_length' => strlen($summary),
                ]);

                return $summary;
            }
        } catch (\Exception $e) {
            Log::channel('ai')->warning('ConversationContext: Failed to generate AI summary', [
                'error' => $e->getMessage(),
            ]);
        }
        
        // Fallback to simple summary
        return $this->createSimpleSummary($messages);
    }
    
    /**
     * Create simple summary without AI (fallback)
     */
    protected function createSimpleSummary($messages): ?string
    {
        if ($messages->isEmpty()) {
            return null;
        }
        
        $customerMessages = $messages->where('is_from_customer', true);
        $agentMessages = $messages->where('is_from_customer', false);
        
        $topics = [];
        
        // Extract first customer message as topic indicator
        $firstCustomerMsg = $customerMessages->last();
        if ($firstCustomerMsg) {
            $topics[] = substr($firstCustomerMsg->content, 0, 100);
        }
        
        return "Previous discussion ({$messages->count()} messages): " . 
               "Customer inquired about: " . implode('; ', array_slice($topics, 0, 2)) . "...";
    }
    
    /**
     * Extract key points from messages
     */
    protected function extractKeyPoints($messages): array
    {
        $keyPoints = [];
        
        // Get unique topics from customer messages
        $customerMessages = $messages->where('is_from_customer', true)->take(5);
        foreach ($customerMessages as $msg) {
            $keyPoints[] = substr($msg->content, 0, 100);
        }
        
        return array_slice($keyPoints, 0, 5);
    }
    
    /**
     * Estimate token count for history
     */
    public function estimateTokens(array $history): int
    {
        $totalChars = 0;
        foreach ($history as $msg) {
            $totalChars += strlen($msg['content'] ?? '');
        }
        
        // Approximate: 1 token ≈ 4 characters for English
        return (int) ceil($totalChars / 4);
    }
    
    /**
     * Optimize system prompt by removing redundant info
     */
    public function optimizeSystemPrompt(string $prompt, int $maxTokens = 1500): string
    {
        $estimatedTokens = (int) ceil(strlen($prompt) / 4);
        
        if ($estimatedTokens <= $maxTokens) {
            return $prompt;
        }
        
        // Remove less important sections if over limit
        $prompt = preg_replace('/- Business Hours:.*?(?=\n## |$)/s', '', $prompt);
        
        // If still too long, truncate knowledge base sections
        if (strlen($prompt) / 4 > $maxTokens) {
            $prompt = preg_replace('/### .*?\n.*?\n\n/s', '', $prompt);
        }

        Log::channel('ai')->info('ConversationContext: System prompt optimized', [
            'original_tokens' => $estimatedTokens,
            'optimized_tokens' => (int) ceil(strlen($prompt) / 4),
        ]);

        return $prompt;
    }
    
    /**
     * Get context window info for debugging
     */
    public function getContextStats(array $history, string $systemPrompt): array
    {
        return [
            'history_messages' => count($history),
            'history_tokens' => $this->estimateTokens($history),
            'system_prompt_tokens' => (int) ceil(strlen($systemPrompt) / 4),
            'total_estimated_tokens' => $this->estimateTokens($history) + (int) ceil(strlen($systemPrompt) / 4),
        ];
    }
}
