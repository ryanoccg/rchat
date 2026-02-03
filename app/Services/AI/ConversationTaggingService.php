<?php

namespace App\Services\AI;

use App\Models\Conversation;
use App\Models\ConversationTag;
use Illuminate\Support\Facades\Log;

class ConversationTaggingService
{
    /**
     * Issue categories with keywords for rule-based detection
     * Ordered by priority (more specific first)
     */
    protected const ISSUE_PATTERNS = [
        // Technical Issues
        'technical_support' => [
            'keywords' => ['error', 'bug', 'not working', 'broken', 'crash', 'issue', 'problem', 'fix', 'stuck', 'freeze', 'slow', 'fail', 'cant', "can't", 'unable', 'doesnt', "doesn't", 'wont', "won't"],
            'category' => 'Support',
            'priority' => 'high',
        ],
        // Billing Issues
        'billing' => [
            'keywords' => ['bill', 'charge', 'payment', 'invoice', 'refund', 'cancel subscription', 'pricing', 'cost', 'fee', 'money', 'paid', 'pay', 'receipt', 'transaction'],
            'category' => 'Billing',
            'priority' => 'high',
        ],
        // Account Issues
        'account_issue' => [
            'keywords' => ['login', 'password', 'account', 'sign in', 'signin', 'sign up', 'signup', 'register', 'locked', 'access', 'forgot', 'reset', 'verify', 'verification'],
            'category' => 'Account',
            'priority' => 'medium',
        ],
        // Complaints
        'complaint' => [
            'keywords' => ['complaint', 'unhappy', 'disappointed', 'terrible', 'worst', 'horrible', 'angry', 'frustrated', 'unacceptable', 'ridiculous', 'scam', 'awful', 'hate'],
            'category' => 'Complaint',
            'priority' => 'urgent',
        ],
        // Urgent/Emergency
        'urgent' => [
            'keywords' => ['urgent', 'emergency', 'asap', 'immediately', 'right now', 'critical', 'important', 'deadline'],
            'category' => 'Urgent',
            'priority' => 'urgent',
        ],
        // Product Inquiry
        'product_inquiry' => [
            'keywords' => ['product', 'service', 'feature', 'how does', 'what is', 'tell me about', 'information', 'details', 'learn more', 'interested', 'offer', 'available'],
            'category' => 'Sales',
            'priority' => 'medium',
        ],
        // Pricing/Sales
        'pricing_inquiry' => [
            'keywords' => ['price', 'cost', 'how much', 'quote', 'discount', 'deal', 'promotion', 'plan', 'package', 'tier', 'subscription'],
            'category' => 'Sales',
            'priority' => 'medium',
        ],
        // Shipping/Delivery
        'shipping' => [
            'keywords' => ['shipping', 'delivery', 'track', 'tracking', 'arrived', 'deliver', 'package', 'order status', 'where is my', 'when will'],
            'category' => 'Logistics',
            'priority' => 'medium',
        ],
        // Returns/Exchanges
        'returns' => [
            'keywords' => ['return', 'exchange', 'replace', 'replacement', 'swap', 'send back', 'wrong item', 'damaged'],
            'category' => 'Returns',
            'priority' => 'medium',
        ],
        // General Question
        'general_inquiry' => [
            'keywords' => ['question', 'help', 'support', 'assist', 'need', 'want', 'looking for', 'can you'],
            'category' => 'General',
            'priority' => 'low',
        ],
        // Feedback
        'feedback' => [
            'keywords' => ['feedback', 'suggestion', 'improve', 'recommend', 'great', 'love', 'thanks', 'thank you', 'appreciate', 'good job', 'amazing'],
            'category' => 'Feedback',
            'priority' => 'low',
        ],
    ];

    /**
     * Auto-detect and tag issues from customer message
     * Uses rule-based pattern matching (no AI call needed)
     */
    public function autoTag(Conversation $conversation, string $customerMessage): array
    {
        $detectedTags = [];
        $normalizedMessage = strtolower($customerMessage);
        
        foreach (self::ISSUE_PATTERNS as $tag => $config) {
            $matchCount = 0;
            $matchedKeywords = [];
            
            foreach ($config['keywords'] as $keyword) {
                if (str_contains($normalizedMessage, $keyword)) {
                    $matchCount++;
                    $matchedKeywords[] = $keyword;
                }
            }
            
            // Calculate confidence based on keyword matches
            if ($matchCount > 0) {
                $confidence = min(0.99, 0.5 + ($matchCount * 0.15));
                
                $detectedTags[] = [
                    'tag' => $tag,
                    'category' => $config['category'],
                    'confidence' => $confidence,
                    'priority' => $config['priority'],
                    'matched_keywords' => $matchedKeywords,
                ];
            }
        }
        
        // Sort by confidence (highest first)
        usort($detectedTags, fn($a, $b) => $b['confidence'] <=> $a['confidence']);
        
        // Take top 3 tags max
        $topTags = array_slice($detectedTags, 0, 3);
        
        // Save tags to database
        $savedTags = [];
        foreach ($topTags as $tagData) {
            $savedTag = $this->saveTag($conversation, $tagData);
            if ($savedTag) {
                $savedTags[] = $savedTag;
            }
        }
        
        // Update conversation priority if urgent tag detected
        $this->updateConversationPriority($conversation, $topTags);
        
        if (!empty($savedTags)) {
            Log::info('ConversationTagging: Tags detected', [
                'conversation_id' => $conversation->id,
                'tags' => array_column($savedTags, 'tag'),
            ]);
        }
        
        return $savedTags;
    }
    
    /**
     * Save tag to database (avoid duplicates)
     */
    protected function saveTag(Conversation $conversation, array $tagData): ?ConversationTag
    {
        // Check if tag already exists for this conversation
        $existing = ConversationTag::where('conversation_id', $conversation->id)
            ->where('tag', $tagData['tag'])
            ->first();
        
        if ($existing) {
            // Update confidence if higher
            if ($tagData['confidence'] > $existing->confidence) {
                $existing->update(['confidence' => $tagData['confidence']]);
            }
            return $existing;
        }
        
        return ConversationTag::create([
            'conversation_id' => $conversation->id,
            'tag' => $tagData['tag'],
            'category' => $tagData['category'],
            'confidence' => $tagData['confidence'],
            'is_ai_generated' => true,
        ]);
    }
    
    /**
     * Update conversation priority based on detected tags
     */
    protected function updateConversationPriority(Conversation $conversation, array $tags): void
    {
        $priorities = ['low' => 1, 'normal' => 2, 'medium' => 2, 'high' => 3, 'urgent' => 4];
        $currentPriority = $priorities[$conversation->priority] ?? 2;
        $highestPriority = $currentPriority;
        
        foreach ($tags as $tag) {
            $tagPriority = $priorities[$tag['priority']] ?? 2;
            if ($tagPriority > $highestPriority) {
                $highestPriority = $tagPriority;
            }
        }
        
        // Map back to priority string
        $priorityMap = [1 => 'low', 2 => 'normal', 3 => 'high', 4 => 'urgent'];
        $newPriority = $priorityMap[$highestPriority] ?? 'normal';
        
        if ($newPriority !== $conversation->priority) {
            $conversation->update(['priority' => $newPriority]);
            Log::info('ConversationTagging: Priority updated', [
                'conversation_id' => $conversation->id,
                'old_priority' => $conversation->priority,
                'new_priority' => $newPriority,
            ]);
        }
    }
    
    /**
     * Get all tags for a conversation
     */
    public function getConversationTags(Conversation $conversation): array
    {
        return $conversation->tags()
            ->orderByDesc('confidence')
            ->get()
            ->toArray();
    }
    
    /**
     * Get available tag categories
     */
    public static function getCategories(): array
    {
        return array_unique(array_column(self::ISSUE_PATTERNS, 'category'));
    }
    
    /**
     * Get all available tags
     */
    public static function getAvailableTags(): array
    {
        return array_keys(self::ISSUE_PATTERNS);
    }
}
