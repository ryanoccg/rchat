<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Customer;
use App\Services\AI\AiService;
use Illuminate\Support\Facades\Log;

class ConversationSummaryService
{
    /**
     * Generate AI-powered summary for a conversation
     */
    public function generateSummary(Conversation $conversation, ?int $userId = null): ConversationSummary
    {
        // Ensure relationships are loaded
        $conversation->loadMissing(['company', 'customer', 'platformConnection.messagingPlatform']);

        Log::info('ConversationSummary: Starting generation', [
            'conversation_id' => $conversation->id,
            'company_id' => $conversation->company_id,
        ]);

        // Get all messages from the conversation
        $messages = $conversation->messages()
            ->orderBy('created_at', 'asc')
            ->get();

        if ($messages->isEmpty()) {
            Log::warning('ConversationSummary: No messages found', [
                'conversation_id' => $conversation->id,
            ]);
            
            return ConversationSummary::updateOrCreate(
                ['conversation_id' => $conversation->id],
                [
                    'summary' => 'No messages in this conversation.',
                    'key_points' => [],
                    'action_items' => [],
                    'keywords' => [],
                    'last_request' => null,
                    'resolution' => null,
                    'generated_by' => $userId,
                    'is_ai_generated' => false,
                ]
            );
        }

        // Build conversation transcript for AI
        $transcript = $this->buildTranscript($messages, $conversation);

        // Check if company is available for AI service
        if (!$conversation->company) {
            Log::warning('ConversationSummary: Company not found', [
                'conversation_id' => $conversation->id,
            ]);
            return $this->generateBasicSummary($conversation, $messages, $userId);
        }

        // Generate summary using AI
        $aiService = new AiService($conversation->company);

        if (!$aiService->isConfigured()) {
            Log::warning('ConversationSummary: AI not configured', [
                'company_id' => $conversation->company_id,
            ]);

            return $this->generateBasicSummary($conversation, $messages, $userId);
        }

        // Get existing summary to preserve important key points
        $existingSummary = ConversationSummary::where('conversation_id', $conversation->id)->first();

        $systemPrompt = $this->buildSummaryPrompt($conversation, $existingSummary);
        
        Log::info('ConversationSummary: Calling AI', [
            'conversation_id' => $conversation->id,
            'message_count' => $messages->count(),
            'transcript_length' => strlen($transcript),
        ]);

        try {
            $response = $aiService->generateSimpleResponse($systemPrompt, $transcript);

            if ($response->isSuccessful()) {
                $summaryData = $this->parseSummaryResponse($response->getContent());
                
                Log::info('ConversationSummary: AI summary generated', [
                    'conversation_id' => $conversation->id,
                    'summary_length' => strlen($summaryData['summary']),
                    'key_points_count' => count($summaryData['key_points']),
                    'action_items_count' => count($summaryData['action_items']),
                ]);

                $summary = ConversationSummary::updateOrCreate(
                    ['conversation_id' => $conversation->id],
                    [
                        'summary' => $summaryData['summary'],
                        'key_points' => $summaryData['key_points'],
                        'action_items' => $summaryData['action_items'],
                        'keywords' => $summaryData['keywords'] ?? [],
                        'last_request' => $summaryData['last_request'] ?? null,
                        'resolution' => $summaryData['resolution'],
                        'generated_by' => $userId,
                        'is_ai_generated' => true,
                    ]
                );

                // Update customer tags based on action_items (category)
                $this->updateCustomerTagsFromSummary($conversation, $summaryData['action_items']);

                return $summary;
            }
        } catch (\Exception $e) {
            Log::error('ConversationSummary: AI generation failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback to basic summary if AI fails
        return $this->generateBasicSummary($conversation, $messages, $userId);
    }

    /**
     * Build conversation transcript for AI analysis
     */
    protected function buildTranscript($messages, Conversation $conversation): string
    {
        $customerName = $conversation->customer?->name ?? 'Unknown Customer';
        $platformName = $conversation->platformConnection?->messagingPlatform?->name ?? 'Unknown Platform';

        $transcript = "Customer: {$customerName}\n";
        $transcript .= "Platform: {$platformName}\n";
        $transcript .= "Status: {$conversation->status}\n\n";
        $transcript .= "--- CONVERSATION ---\n\n";

        foreach ($messages as $message) {
            $sender = $message->is_from_customer ? 'Customer' :
                     ($message->sender_type === 'ai' ? 'AI Agent' : 'Human Agent');
            $transcript .= "{$sender}: {$message->content}\n\n";
        }

        return $transcript;
    }

    /**
     * Build system prompt for summary generation
     */
    protected function buildSummaryPrompt(Conversation $conversation, ?ConversationSummary $existingSummary = null): string
    {
        // Get company's knowledge base to understand their services
        $knowledgeContext = '';
        if ($conversation->company) {
            $knowledgeBases = $conversation->company->knowledgeBase()->limit(5)->get();
        } else {
            $knowledgeBases = collect();
        }
        if ($knowledgeBases->isNotEmpty()) {
            $knowledgeContext = "\n\nCompany Services/Products:\n";
            foreach ($knowledgeBases as $kb) {
                $knowledgeContext .= "- {$kb->title}\n";
            }
        }

        // Include existing key points to preserve them
        $existingKeyPointsContext = '';
        if ($existingSummary && !empty($existingSummary->key_points)) {
            $existingKeyPointsContext = "\n\nPREVIOUS CLIENT REQUESTS (preserve these and ADD new ones):\n";
            foreach ($existingSummary->key_points as $point) {
                $existingKeyPointsContext .= "- {$point}\n";
            }
        }

        return "You are an expert customer service analyst. Analyze this conversation and provide a CONCISE summary.

Your response MUST be in this EXACT JSON format:
{
    \"summary\": \"A brief 1-2 sentence description of what this conversation is about\",
    \"key_points\": [
        \"What the client specifically requested or asked for\",
        \"Any additional client requirements or concerns\"
    ],
    \"action_items\": [
        \"Category tag that best describes this request (choose ONE)\"
    ],
    \"keywords\": [
        \"keyword1\",
        \"keyword2\",
        \"keyword3\"
    ],
    \"last_request\": \"The customer's most recent specific request or question\",
    \"resolution\": \"YES or NO - Does this need human agent handling?\"
}

RULES:

1. SUMMARY: Write in the SAME language as conversation. Keep it to 1-2 sentences describing what happened.

2. KEY_POINTS: List what the CLIENT requested or asked for. Focus on their needs, not what we said. Be specific. 2-4 points maximum.

3. ACTION_ITEMS: Create ONE appropriate category tag based on what the client wants. Consider the company's services and choose the most relevant category. Examples:
   - Technical Support - Bug fixes, technical issues, system problems
   - Product Inquiry - Questions about products/services, features, pricing
   - Sales/Purchase - Want to buy, pricing negotiation, payment
   - Service Request - Requesting specific company service
   - Consultation - Seeking advice, recommendations, guidance
   - Complaint - Issues, dissatisfaction, problems with service
   - General Question - Simple questions, information requests
   - Follow-up - Continuing previous conversation
   
   IMPORTANT: Choose or create a category that matches the company's actual services. If the request relates to a specific service this company offers, use that service name as the category.

4. KEYWORDS: Extract 3-6 relevant keywords/topics from the conversation. These help identify what the customer is interested in. Examples: product names, service types, issues mentioned, preferences.

5. LAST_REQUEST: Capture the customer's most recent specific request or question. This is useful for follow-up conversations. Be specific about what they wanted.

6. RESOLUTION: Analyze if this needs HUMAN AGENT handling:
   - Answer \"YES\" if:
     * Complex technical issue AI cannot solve
     * Customer explicitly wants human agent
     * Requires manual actions (refunds, account changes)
     * Customer is frustrated/angry
     * AI tried but couldn't resolve after multiple attempts
   - Answer \"NO\" if:
     * Simple question AI can answer
     * Information request handled by AI
     * Issue was resolved by AI
     * Customer seems satisfied with AI response

Company: {$conversation->company?->name}
Customer: {$conversation->customer?->name}{$knowledgeContext}{$existingKeyPointsContext}";
    }

    /**
     * Parse AI response into structured summary data
     */
    protected function parseSummaryResponse(string $content): array
    {
        // Try to extract JSON from response
        $json = $content;
        
        // Remove markdown code blocks if present
        if (preg_match('/```json\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/```\s*(\{.*?\})\s*```/s', $content, $matches)) {
            $json = $matches[1];
        } elseif (preg_match('/(\{.*?\})/s', $content, $matches)) {
            $json = $matches[1];
        }

        try {
            $data = json_decode($json, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                return [
                    'summary' => $data['summary'] ?? 'Summary not available',
                    'key_points' => $data['key_points'] ?? [],
                    'action_items' => $data['action_items'] ?? [],
                    'keywords' => $data['keywords'] ?? [],
                    'last_request' => $data['last_request'] ?? null,
                    'resolution' => $data['resolution'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning('ConversationSummary: Failed to parse JSON', [
                'content' => $content,
                'error' => $e->getMessage(),
            ]);
        }

        // Fallback: use raw content as summary
        return [
            'summary' => substr($content, 0, 500),
            'key_points' => [],
            'action_items' => [],
            'keywords' => [],
            'last_request' => null,
            'resolution' => null,
        ];
    }

    /**
     * Generate basic summary without AI (fallback)
     */
    protected function generateBasicSummary(Conversation $conversation, $messages, ?int $userId): ConversationSummary
    {
        $messageCount = $messages->count();
        $customerMessages = $messages->where('is_from_customer', true)->count();
        $agentMessages = $messages->where('is_from_customer', false)->count();

        $customerName = $conversation->customer?->name ?? 'Unknown Customer';
        $platformName = $conversation->platformConnection?->messagingPlatform?->name ?? 'Unknown Platform';

        $summary = "Conversation with {$customerName} via {$platformName}. ";
        $summary .= "Total messages: {$messageCount} ({$customerMessages} from customer, {$agentMessages} from agent). ";
        $summary .= "Status: {$conversation->status}.";

        return ConversationSummary::updateOrCreate(
            ['conversation_id' => $conversation->id],
            [
                'summary' => $summary,
                'key_points' => [
                    "Customer: {$customerName}",
                    "Platform: {$platformName}",
                    "Messages: {$messageCount}",
                ],
                'action_items' => [],
                'resolution' => $conversation->status === 'closed' ? 'Conversation closed' : null,
                'generated_by' => $userId,
                'is_ai_generated' => false,
            ]
        );
    }

    /**
     * Regenerate summary for a conversation
     */
    public function regenerateSummary(Conversation $conversation, ?int $userId = null): ConversationSummary
    {
        Log::info('ConversationSummary: Regenerating', [
            'conversation_id' => $conversation->id,
        ]);

        // Delete existing summary if any
        $conversation->summary()?->delete();

        return $this->generateSummary($conversation, $userId);
    }

    /**
     * Update customer tags based on conversation summary action_items (category)
     */
    protected function updateCustomerTagsFromSummary(Conversation $conversation, array $actionItems): void
    {
        if (empty($actionItems) || !$conversation->customer_id) {
            return;
        }

        $customer = Customer::find($conversation->customer_id);
        if (!$customer) {
            return;
        }

        // Get current tags from customer metadata
        $metadata = $customer->metadata ?? [];
        $currentTags = $metadata['tags'] ?? [];

        // Add action_items as tags (these are category tags from the summary)
        $newTags = [];
        foreach ($actionItems as $item) {
            $tag = trim($item);
            if (!empty($tag) && !in_array($tag, $currentTags)) {
                $newTags[] = $tag;
            }
        }

        if (!empty($newTags)) {
            $metadata['tags'] = array_merge($currentTags, $newTags);
            $customer->metadata = $metadata;
            $customer->save();

            Log::info('ConversationSummary: Customer tags updated from summary', [
                'customer_id' => $customer->id,
                'new_tags' => $newTags,
                'total_tags' => count($metadata['tags']),
            ]);
        }
    }

    /**
     * Generate summaries for multiple conversations (batch)
     */
    public function generateBatchSummaries(array $conversationIds, ?int $userId = null): array
    {
        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($conversationIds as $conversationId) {
            try {
                $conversation = Conversation::findOrFail($conversationId);
                $summary = $this->generateSummary($conversation, $userId);
                $results['success'][] = $conversationId;
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'conversation_id' => $conversationId,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
