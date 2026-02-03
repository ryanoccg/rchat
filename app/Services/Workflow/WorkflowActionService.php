<?php

namespace App\Services\Workflow;

use App\Models\WorkflowExecution;
use App\Services\AI\AiService;
use App\Services\Messaging\MessageHandlerFactory;
use Illuminate\Support\Facades\Log;

class WorkflowActionService
{
    /**
     * Execute a workflow action.
     */
    public function execute(string $actionType, array $config, array $context, WorkflowExecution $execution): array
    {
        // Normalize context to ensure we have model objects instead of arrays
        $context = $this->normalizeContext($context, $execution);

        return match ($actionType) {
            'send_message' => $this->sendMessage($config, $context, $execution),
            'send_ai_response' => $this->generateAndSendAIResponse($config, $context, $execution),
            'add_tag' => $this->addTags($config, $context, $execution),
            'remove_tag' => $this->removeTags($config, $context, $execution),
            'assign_agent' => $this->assignAgent($config, $context, $execution),
            'assign_team' => $this->assignTeam($config, $context, $execution),
            'human_handoff' => $this->humanHandoff($config, $context, $execution),
            'set_status' => $this->setConversationStatus($config, $context, $execution),
            'set_priority' => $this->setConversationPriority($config, $context, $execution),
            'add_note' => $this->addNote($config, $context, $execution),
            default => throw new \Exception("Unknown action type: {$actionType}"),
        };
    }

    /**
     * Normalize context to ensure conversation and customer are model objects.
     * When workflows are executed from a job, the context may contain serialized arrays.
     */
    protected function normalizeContext(array $context, WorkflowExecution $execution): array
    {
        // Always use the execution's conversation relationship (actual model)
        $context['conversation'] = $execution->conversation;

        // If context customer is an array or null, load from conversation
        $customer = $context['customer'] ?? null;
        if (!$customer || is_array($customer)) {
            $context['customer'] = $context['conversation']?->customer;
        }

        return $context;
    }

    /**
     * Send a message.
     * Supports [PRODUCT_IMAGE: url] tags - extracts images and sends them separately.
     */
    protected function sendMessage(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $context['conversation'] ?? null;

        if (!$conversation) {
            throw new \Exception('Conversation not found for this workflow execution');
        }

        $message = $this->parseMessageTemplate($config['message'] ?? '', $context);
        $imageUrl = $config['image_url'] ?? null;
        $attachmentUrl = $config['attachment_url'] ?? null;

        // Extract product images from [PRODUCT_IMAGE: url] tags
        $productImages = $this->extractProductImages($message);
        $textContent = $this->removeProductImageTags($message);

        // Combine explicit image_url with extracted product images
        $allImages = [];
        if ($imageUrl) {
            $allImages[] = $imageUrl;
        }
        $allImages = array_merge($allImages, array_slice($productImages, 0, 10)); // Limit to 10

        // Create message record (with clean text content)
        $messageModel = \App\Models\Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'system', // Workflow messages are system messages
            'content' => $textContent,
            'message_type' => !empty($allImages) ? 'text_with_images' : 'text',
            'media_urls' => !empty($allImages) ? array_map(fn($url) => ['type' => 'image', 'url' => $url], $allImages) : null,
            'metadata' => [
                'workflow_execution_id' => $execution->id,
                'is_automated' => true,
                'product_images_sent' => count($allImages),
            ],
        ]);

        // Send via platform handler
        try {
            $connection = $conversation->platformConnection;
            if ($connection && $connection->is_active) {
                $platform = $connection->messagingPlatform->slug ?? $connection->platform;
                $handler = MessageHandlerFactory::create($platform);

                // For Facebook, WhatsApp, Telegram, LINE: send images first, then text
                if (in_array($platform, ['facebook', 'whatsapp', 'telegram', 'line'])) {
                    foreach ($allImages as $imgUrl) {
                        try {
                            $handler->sendImage(
                                $connection,
                                $conversation->customer->platform_user_id,
                                $imgUrl
                            );
                            Log::info('WorkflowActionService: Product image sent', [
                                'conversation_id' => $conversation->id,
                                'image_url' => $imgUrl,
                            ]);
                        } catch (\Exception $e) {
                            Log::warning('WorkflowActionService: Failed to send product image', [
                                'conversation_id' => $conversation->id,
                                'image_url' => $imgUrl,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    if (!empty(trim($textContent))) {
                        $handler->sendMessage($connection, $conversation->customer->platform_user_id, $textContent);
                    }
                } else {
                    // Default: send text first, then images
                    if (!empty(trim($textContent))) {
                        $handler->sendMessage($connection, $conversation->customer->platform_user_id, $textContent);
                    }
                    foreach ($allImages as $imgUrl) {
                        try {
                            $handler->sendImage(
                                $connection,
                                $conversation->customer->platform_user_id,
                                $imgUrl
                            );
                        } catch (\Exception $e) {
                            Log::warning('WorkflowActionService: Failed to send product image', [
                                'conversation_id' => $conversation->id,
                                'image_url' => $imgUrl,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            return [
                'success' => true,
                'message_id' => $messageModel->id,
                'sent_at' => now()->toIso8601String(),
                'context' => ['message_sent' => true, 'images_sent' => count($allImages)],
            ];
        } catch (\Exception $e) {
            Log::error("Failed to send workflow message: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Extract product image URLs from AI response
     * Looks for pattern: [PRODUCT_IMAGE: url]
     */
    protected function extractProductImages(string $content): array
    {
        $images = [];

        if (preg_match_all('/\[PRODUCT_IMAGE:\s*([^\]]+)\]/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $url = trim($url);
                if (filter_var($url, FILTER_VALIDATE_URL)) {
                    $images[] = $url;
                }
            }
        }

        return $images;
    }

    /**
     * Remove product image tags from content
     */
    protected function removeProductImageTags(string $content): string
    {
        $cleaned = preg_replace('/\[PRODUCT_IMAGE:\s*[^\]]+\]\s*/i', '', $content);
        $cleaned = preg_replace('/\n{3,}/', "\n\n", $cleaned);
        return trim($cleaned);
    }

    /**
     * Generate and send AI response.
     *
     * Phase 2.2: Extended config options:
     * - system_prompt: Custom system prompt
     * - prompt_template: Prompt template with variables
     * - ai_agent_id: Specific AI personality to use
     * - additional_context: Extra instructions appended to prompt
     * - enable_product_search: Override agent's product search setting
     * - rag_top_k: Override agent's RAG chunk count
     */
    protected function generateAndSendAIResponse(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $context['conversation'] ?? null;

        if (!$conversation) {
            throw new \Exception('Conversation not found for this workflow execution');
        }

        $systemPrompt = $config['system_prompt'] ?? null;
        $promptTemplate = $config['prompt_template'] ?? null;
        $aiAgentId = $config['ai_agent_id'] ?? null;

        // Phase 2.2: Extended workflow step config
        $additionalContext = $config['additional_context'] ?? null;
        $enableProductSearch = $config['enable_product_search'] ?? null;
        $ragTopK = $config['rag_top_k'] ?? null;

        $result = $this->executeAIResponse(
            $systemPrompt,
            $promptTemplate,
            $context,
            $execution,
            $aiAgentId,
            $additionalContext,
            $enableProductSearch,
            $ragTopK
        );

        // Send the AI-generated message
        $sendMessageConfig = [
            'message' => $result['response'] ?? '',
            'image_url' => null,
            'attachment_url' => null,
        ];

        return $this->sendMessage($sendMessageConfig, $context, $execution);
    }

    /**
     * Execute AI response (without sending).
     *
     * @param string|null $systemPrompt Custom system prompt
     * @param string|null $promptTemplate Prompt template with variables
     * @param array $context Workflow context
     * @param WorkflowExecution $execution Current execution
     * @param int|null $aiAgentId Optional specific AI agent/personality to use
     * @param string|null $additionalContext Phase 2.2: Extra instructions for the AI
     * @param bool|null $enableProductSearch Phase 2.2: Override agent's product search setting
     * @param int|null $ragTopK Phase 2.2: Override agent's RAG chunk count
     */
    public function executeAIResponse(
        ?string $systemPrompt,
        ?string $promptTemplate,
        array $context,
        WorkflowExecution $execution,
        ?int $aiAgentId = null,
        ?string $additionalContext = null,
        ?bool $enableProductSearch = null,
        ?int $ragTopK = null
    ): array {
        $conversation = $context['conversation'] ?? null;

        if (!$conversation) {
            throw new \Exception('Conversation not found for this workflow execution');
        }

        // Get the actual customer message from context
        $customerMessage = $this->getCustomerMessage($context);

        $aiService = new AiService($conversation->company);

        // Build the prompt for additional instructions (optional)
        $additionalInstructions = '';
        if ($promptTemplate) {
            $additionalInstructions = $this->parseMessageTemplate($promptTemplate, $context);
        }

        // Phase 2.2: Append workflow-level additional context
        if ($additionalContext) {
            $parsedAdditionalContext = $this->parseMessageTemplate($additionalContext, $context);
            $additionalInstructions .= ($additionalInstructions ? "\n\n" : '') . $parsedAdditionalContext;
        }

        // Combine: actual customer message + any additional instructions from workflow
        $promptForAi = $customerMessage;
        if (!empty($additionalInstructions)) {
            $promptForAi = $customerMessage . "\n\n" . $additionalInstructions;
        }

        // Build options
        $options = [];
        if ($systemPrompt) {
            $options['system_prompt'] = $this->parseMessageTemplate($systemPrompt, $context);
        }
        if ($aiAgentId) {
            $options['ai_agent_id'] = $aiAgentId;
        }
        // Phase 2.2: Pass workflow-level overrides
        if ($enableProductSearch !== null) {
            $options['enable_product_search'] = $enableProductSearch;
        }
        if ($ragTopK !== null) {
            $options['rag_top_k'] = $ragTopK;
        }

        try {
            // Get the message object - it might be an array from serialized context
            $latestMessage = $context['message'] ?? null;
            if (is_array($latestMessage)) {
                $latestMessage = null; // Can't use array, fetch from conversation instead
            }

            $response = $aiService->respondToMessage(
                $conversation,
                $promptForAi,
                $latestMessage,
                $options
            );

            return [
                'success' => true,
                'response' => $response->getContent(),
                'confidence' => $response->getConfidence(),
                'context' => ['ai_generated' => true, 'ai_agent_id' => $aiAgentId],
            ];
        } catch (\Exception $e) {
            Log::error("AI response generation failed: {$e->getMessage()}");
            throw $e;
        }
    }

    /**
     * Add tags to customer or conversation.
     */
    protected function addTags(array $config, array $context, WorkflowExecution $execution): array
    {
        $tags = $config['tags'] ?? [];
        $target = $config['target'] ?? 'customer'; // customer or conversation

        $addedTags = [];

        if ($target === 'customer') {
            $customer = $context['customer'] ?? null;
            if ($customer && !empty($tags)) {
                $metadata = $customer->metadata ?? [];
                $existingTags = $metadata['tags'] ?? [];
                $newTags = array_diff($tags, $existingTags);

                foreach ($newTags as $tag) {
                    $metadata['tags'][] = $tag;
                    $addedTags[] = $tag;
                }

                $customer->update(['metadata' => $metadata]);
            }
        } elseif ($target === 'conversation') {
            $conversation = $context['conversation'] ?? null;
            if ($conversation && !empty($tags)) {
                foreach ($tags as $tag) {
                    $existingTag = \App\Models\ConversationTag::where('conversation_id', $conversation->id)
                        ->where('tag', $tag)
                        ->first();

                    if (!$existingTag) {
                        \App\Models\ConversationTag::create([
                            'conversation_id' => $conversation->id,
                            'tag' => $tag,
                            'added_by' => 'workflow',
                        ]);
                        $addedTags[] = $tag;
                    }
                }
            }
        }

        return [
            'success' => true,
            'added_tags' => $addedTags,
            'context' => ['tags_added' => $addedTags],
        ];
    }

    /**
     * Remove tags from customer or conversation.
     */
    protected function removeTags(array $config, array $context, WorkflowExecution $execution): array
    {
        $tags = $config['tags'] ?? [];
        $target = $config['target'] ?? 'customer';

        $removedTags = [];

        if ($target === 'customer') {
            $customer = $context['customer'] ?? null;
            if ($customer && !empty($tags)) {
                $metadata = $customer->metadata ?? [];
                $existingTags = $metadata['tags'] ?? [];

                foreach ($tags as $tag) {
                    $index = array_search($tag, $existingTags);
                    if ($index !== false) {
                        unset($existingTags[$index]);
                        $removedTags[] = $tag;
                    }
                }

                $metadata['tags'] = array_values($existingTags);
                $customer->update(['metadata' => $metadata]);
            }
        } elseif ($target === 'conversation') {
            $conversation = $context['conversation'] ?? null;
            if ($conversation && !empty($tags)) {
                \App\Models\ConversationTag::where('conversation_id', $conversation->id)
                    ->whereIn('tag', $tags)
                    ->delete();

                $removedTags = $tags;
            }
        }

        return [
            'success' => true,
            'removed_tags' => $removedTags,
            'context' => ['tags_removed' => $removedTags],
        ];
    }

    /**
     * Assign conversation to an agent.
     */
    protected function assignAgent(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $context['conversation'] ?? null;
        $agentId = $config['agent_id'] ?? null;

        if (!$conversation) {
            throw new \Exception('Conversation not found in context');
        }

        if (!$agentId) {
            throw new \Exception('Agent ID not provided');
        }

        $conversation->update([
            'assigned_to' => $agentId,
            'status' => 'in_progress',
        ]);

        return [
            'success' => true,
            'assigned_to' => $agentId,
            'context' => ['agent_assigned' => true],
        ];
    }

    /**
     * Assign conversation to a team (find available agent).
     */
    protected function assignTeam(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $context['conversation'] ?? null;
        $teamId = $config['team_id'] ?? null;

        if (!$conversation) {
            throw new \Exception('Conversation not found in context');
        }

        // Find agents in the team
        $teamAgents = \App\Models\User::whereJsonContains('metadata->team_ids', $teamId)
            ->where('current_company_id', $conversation->company_id)
            ->get();

        if ($teamAgents->isEmpty()) {
            // Assign to first available agent in company
            $agent = \App\Models\User::where('current_company_id', $conversation->company_id)
                ->whereNotNull('current_company_id')
                ->first();
        } else {
            // Simple round-robin or load balancing
            $agent = $teamAgents->sortBy(fn ($u) => $u->conversations()->where('status', 'open')->count())->first();
        }

        if ($agent) {
            $conversation->update([
                'assigned_to' => $agent->id,
                'status' => 'in_progress',
            ]);

            return [
                'success' => true,
                'assigned_to' => $agent->id,
                'context' => ['team_assigned' => true, 'team_id' => $teamId],
            ];
        }

        throw new \Exception('No agent available for assignment');
    }

    /**
     * Hand off to human agent.
     */
    protected function humanHandoff(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $execution->conversation;

        if (!$conversation) {
            throw new \Exception('Conversation not found for this workflow execution');
        }

        // Disable AI handling
        $conversation->update([
            'is_ai_handling' => false,
            'status' => 'in_progress',
        ]);

        // Optionally assign to a specific agent
        $agentId = $config['agent_id'] ?? null;
        if ($agentId) {
            $conversation->update(['assigned_to' => $agentId]);
        }

        // Store workflow handoff info
        $conversation->setWorkflowMetadata('human_handoff_from_workflow', $execution->workflow_id);

        return [
            'success' => true,
            'handed_off' => true,
            'context' => ['human_handoff' => true],
        ];
    }

    /**
     * Set conversation status.
     */
    protected function setConversationStatus(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $context['conversation'] ?? null;
        $status = $config['status'] ?? 'open';

        if (!$conversation) {
            throw new \Exception('Conversation not found in context');
        }

        $validStatuses = ['open', 'in_progress', 'closed', 'escalated'];
        if (!in_array($status, $validStatuses)) {
            throw new \Exception("Invalid status: {$status}");
        }

        $conversation->update(['status' => $status]);

        if ($status === 'closed') {
            $conversation->update([
                'closed_at' => now(),
                'closed_reason' => $config['reason'] ?? 'workflow_automation',
            ]);
        }

        return [
            'success' => true,
            'status' => $status,
            'context' => ['status_updated' => true],
        ];
    }

    /**
     * Set conversation priority.
     */
    protected function setConversationPriority(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $context['conversation'] ?? null;
        $priority = $config['priority'] ?? 'normal';

        if (!$conversation) {
            throw new \Exception('Conversation not found in context');
        }

        $validPriorities = ['low', 'normal', 'high', 'urgent'];
        if (!in_array($priority, $validPriorities)) {
            throw new \Exception("Invalid priority: {$priority}");
        }

        $conversation->update(['priority' => $priority]);

        return [
            'success' => true,
            'priority' => $priority,
            'context' => ['priority_updated' => true],
        ];
    }

    /**
     * Add a note to the conversation or customer.
     */
    protected function addNote(array $config, array $context, WorkflowExecution $execution): array
    {
        $conversation = $context['conversation'] ?? null;
        $note = $config['note'] ?? '';

        if (!$conversation) {
            throw new \Exception('Conversation not found in context');
        }

        $note = $this->parseMessageTemplate($note, $context);

        // Store in conversation metadata
        $metadata = $conversation->metadata ?? [];
        $notes = $metadata['notes'] ?? [];

        $notes[] = [
            'note' => $note,
            'added_at' => now()->toIso8601String(),
            'source' => 'workflow',
        ];

        $conversation->update(['metadata' => array_merge($metadata, ['notes' => $notes])]);

        return [
            'success' => true,
            'note' => $note,
            'context' => ['note_added' => true],
        ];
    }

    /**
     * Parse message template with context variables.
     */
    protected function parseMessageTemplate(string $template, array $context): string
    {
        $customer = $context['customer'] ?? null;
        $conversation = $context['conversation'] ?? null;

        $replacements = [
            '{{customer_name}}' => $customer?->name ?? 'Customer',
            '{{customer_first_name}}' => $this->getFirstName($customer?->name),
            '{{customer_email}}' => $customer?->email ?? '',
            '{{customer_phone}}' => $customer?->phone ?? '',
            '{{company_name}}' => $conversation?->company?->name ?? '',
            '{{current_date}}' => now()->format('F j, Y'),
            '{{current_time}}' => now()->format('g:i A'),
            '{{today}}' => now()->format('l, F j'),
        ];

        foreach ($replacements as $key => $value) {
            $template = str_replace($key, $value, $template);
        }

        return $template;
    }

    /**
     * Get first name from full name.
     */
    protected function getFirstName(?string $fullName): string
    {
        if (!$fullName) {
            return 'there';
        }

        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? 'there';
    }

    /**
     * Get the actual customer message from workflow context.
     * This extracts the real customer message content, not a generic prompt.
     */
    protected function getCustomerMessage(array $context): string
    {
        // Try to get the actual customer message from the message object in context
        $message = $context['message'] ?? null;

        if ($message && is_object($message) && method_exists($message, 'getAttribute')) {
            $content = $message->content;
            if (!empty($content)) {
                return $content;
            }
        }

        // Fallback: check if message is an array (serialized context)
        if (is_array($message)) {
            $content = $message['content'] ?? '';
            if (!empty($content)) {
                return $content;
            }
        }

        // Last resort: get recent customer message from conversation
        $conversation = $context['conversation'] ?? null;
        if ($conversation && is_object($conversation) && method_exists($conversation, 'messages')) {
            $latestCustomerMessage = $conversation->messages()
                ->where('sender_type', 'customer')
                ->latest()
                ->first();

            if ($latestCustomerMessage && !empty($latestCustomerMessage->content)) {
                return $latestCustomerMessage->content;
            }
        }

        // Ultimate fallback - shouldn't happen but prevents errors
        return 'Hello';
    }
}
