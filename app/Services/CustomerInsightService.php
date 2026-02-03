<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\AiConfiguration;
use App\Services\AI\AiServiceFactory;
use Illuminate\Support\Facades\Log;

class CustomerInsightService
{
    /**
     * Analyze recent conversation and generate customer tags/notes
     */
    public function analyzeAndTagCustomer(Conversation $conversation): array
    {
        $customer = $conversation->customer;

        if (!$customer) {
            return ['success' => false, 'error' => 'No customer found'];
        }

        $company = $conversation->company;
        $aiConfig = AiConfiguration::where('company_id', $company->id)->first();

        if (!$aiConfig) {
            return ['success' => false, 'error' => 'No AI configuration'];
        }

        // Get recent messages from this conversation
        $messages = Message::where('conversation_id', $conversation->id)
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            return ['success' => false, 'error' => 'No messages to analyze'];
        }

        // Build conversation context for analysis
        $conversationText = $messages->map(function ($msg) {
            $sender = $msg->is_from_customer ? 'Customer' : 'Agent';
            return "{$sender}: {$msg->content}";
        })->implode("\n");

        // Generate insight prompt
        $prompt = $this->buildInsightPrompt($customer, $conversationText);

        try {
            $provider = AiServiceFactory::fromConfiguration($aiConfig);

            $response = $provider->sendMessage($prompt, [
                'system' => 'You are a customer insight analyzer. Analyze conversations and extract key information about customers. Respond ONLY in valid JSON format.',
            ], [
                'model' => $aiConfig->primary_model,
                'max_tokens' => 300,
                'temperature' => 0.3,
            ]);

            if (!$response->isSuccessful()) {
                return ['success' => false, 'error' => $response->getError()];
            }

            $insights = $this->parseInsights($response->getContent());

            if (!empty($insights)) {
                $this->applyInsights($customer, $insights);

                Log::info('CustomerInsightService: Applied insights', [
                    'customer_id' => $customer->id,
                    'tags' => $insights['tags'] ?? [],
                    'note' => $insights['note'] ?? null,
                ]);

                return [
                    'success' => true,
                    'insights' => $insights,
                ];
            }

            return ['success' => false, 'error' => 'Could not parse insights'];
        } catch (\Exception $e) {
            Log::error('CustomerInsightService: Analysis failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Build the prompt for customer insight extraction
     */
    protected function buildInsightPrompt(Customer $customer, string $conversationText): string
    {
        $existingTags = $customer->tags ?? [];
        $existingTagsStr = !empty($existingTags)
            ? implode(', ', array_map(fn($t) => is_array($t) ? $t['tag'] : $t, $existingTags))
            : 'none';

        return <<<PROMPT
Analyze this customer conversation and extract insights.

Customer Name: {$customer->name}
Existing Tags: {$existingTagsStr}

CONVERSATION:
{$conversationText}

Based on this conversation, provide:
1. Relevant tags for this customer (max 5 tags, short keywords like "toys", "skin issues", "budget conscious", "repeat customer")
2. A brief note about the customer (1-2 sentences summarizing their needs/interests)
3. Customer sentiment (positive, neutral, negative)
4. Purchase intent level (high, medium, low, none)

Respond in this exact JSON format:
{
    "tags": ["tag1", "tag2"],
    "note": "Brief customer note here",
    "sentiment": "positive",
    "purchase_intent": "high"
}

Only include tags that are clearly relevant to the conversation. Don't repeat existing tags.
PROMPT;
    }

    /**
     * Parse AI response into structured insights
     */
    protected function parseInsights(string $content): array
    {
        // Try to extract JSON from the response
        $jsonMatch = preg_match('/\{[^{}]*\}/s', $content, $matches);

        if (!$jsonMatch) {
            // Try to find JSON with nested structure
            $jsonMatch = preg_match('/\{.*\}/s', $content, $matches);
        }

        if ($jsonMatch) {
            $parsed = json_decode($matches[0], true);
            if ($parsed && is_array($parsed)) {
                return [
                    'tags' => $parsed['tags'] ?? [],
                    'note' => $parsed['note'] ?? null,
                    'sentiment' => $parsed['sentiment'] ?? null,
                    'purchase_intent' => $parsed['purchase_intent'] ?? null,
                ];
            }
        }

        return [];
    }

    /**
     * Apply insights to customer record
     */
    protected function applyInsights(Customer $customer, array $insights): void
    {
        $updates = [];

        // Merge new tags with existing ones (avoid duplicates)
        if (!empty($insights['tags'])) {
            $existingTags = $customer->tags ?? [];

            // Normalize existing tags to string array
            $existingTagStrings = array_map(function ($tag) {
                return is_array($tag) ? $tag['tag'] : $tag;
            }, $existingTags);

            // Add new tags
            foreach ($insights['tags'] as $newTag) {
                $newTag = strtolower(trim($newTag));
                if (!empty($newTag) && !in_array($newTag, array_map('strtolower', $existingTagStrings))) {
                    $existingTags[] = ['tag' => $newTag, 'auto_generated' => true];
                }
            }

            $updates['tags'] = $existingTags;
        }

        // Append to notes if there's a new note
        if (!empty($insights['note'])) {
            $existingNotes = $customer->notes ?? '';
            $date = now()->format('Y-m-d');
            $newNote = "[AI {$date}] {$insights['note']}";

            // Avoid duplicate notes
            if (strpos($existingNotes, $insights['note']) === false) {
                $updates['notes'] = trim($existingNotes . "\n\n" . $newNote);
            }
        }

        // Store sentiment and purchase intent in profile data
        if (!empty($insights['sentiment']) || !empty($insights['purchase_intent'])) {
            $profileData = $customer->profile_data ?? [];
            if (!empty($insights['sentiment'])) {
                $profileData['last_sentiment'] = $insights['sentiment'];
            }
            if (!empty($insights['purchase_intent'])) {
                $profileData['purchase_intent'] = $insights['purchase_intent'];
            }
            $profileData['insight_updated_at'] = now()->toIso8601String();
            $updates['profile_data'] = $profileData;
        }

        if (!empty($updates)) {
            $customer->update($updates);
        }
    }

    /**
     * Analyze all recent conversations for a customer
     */
    public function analyzeCustomerFromAllConversations(Customer $customer, int $limit = 5): array
    {
        $conversations = Conversation::where('customer_id', $customer->id)
            ->orderBy('last_message_at', 'desc')
            ->limit($limit)
            ->get();

        $results = [];

        foreach ($conversations as $conversation) {
            $result = $this->analyzeAndTagCustomer($conversation);
            $results[] = [
                'conversation_id' => $conversation->id,
                'result' => $result,
            ];
        }

        return $results;
    }
}
