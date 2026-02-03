<?php

namespace App\Services\AI;

use App\Models\AiConfiguration;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\KnowledgeBaseEmbedding;
use App\Models\Message;
use App\Models\MediaProcessingResult;
use App\Models\Product;
use App\Services\AI\AgentSelectorService;
use App\Services\AI\Contracts\AiResponseInterface;
use App\Services\Media\MediaProcessingService;
use App\Services\Products\ProductRagService;
use App\Services\Calendar\AppointmentService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiService
{
    protected ?AiConfiguration $config = null;
    protected ?array $agentConfig = null;
    protected ?string $agentType = null;

    public function __construct(
        protected Company $company
    ) {
        $this->config = $company->aiConfiguration;
    }

    /**
     * Set the AI agent to use for this session (auto-select based on context)
     * @deprecated Use setAgentById() with explicit agent ID from workflow
     */
    public function setAgent(Conversation $conversation): void
    {
        $agentSelector = new AgentSelectorService();
        $agent = $agentSelector->selectAgent($this->company->id, $conversation);

        if ($agent instanceof \App\Models\AiAgent) {
            $this->agentConfig = $agent->toConfigurationArray();
            $this->agentType = $agent->agent_type;
        } elseif (is_array($agent)) {
            $this->agentConfig = $agent;
            $this->agentType = $agent['agent_type'] ?? 'general';
        } else {
            // Fallback to legacy config
            $this->agentConfig = null;
            $this->agentType = null;
        }
    }

    /**
     * Set a specific AI agent by ID (for workflow integration)
     */
    public function setAgentById(int $agentId): bool
    {
        $agent = \App\Models\AiAgent::where('id', $agentId)
            ->where('company_id', $this->company->id)
            ->where('is_active', true)
            ->first();

        if (!$agent) {
            Log::warning('AiService::setAgentById - Agent not found or inactive', [
                'agent_id' => $agentId,
                'company_id' => $this->company->id,
            ]);
            return false;
        }

        $this->agentConfig = $agent->toConfigurationArray();
        $this->agentType = $agent->agent_type;

        Log::info('AiService::setAgentById - Agent loaded', [
            'agent_id' => $agentId,
            'agent_name' => $agent->name,
            'agent_type' => $this->agentType,
        ]);

        return true;
    }

    /**
     * Get the current agent configuration
     */
    public function getAgentConfig(): ?array
    {
        return $this->agentConfig;
    }

    /**
     * Get the current agent type
     */
    public function getAgentType(): ?string
    {
        return $this->agentType;
    }

    /**
     * Generate a response for a customer message
     *
     * @param Conversation $conversation
     * @param string $customerMessage
     * @param Message|null $latestMessage
     * @param array $options Optional settings: ['ai_agent_id' => int] to use a specific agent
     */
    public function respondToMessage(Conversation $conversation, string $customerMessage, ?Message $latestMessage = null, array $options = []): AiResponseInterface
    {
        // Phase 1.4: Single clear path for agent selection
        // - If ai_agent_id provided (from workflow) → use that specific personality
        // - Otherwise → use AiConfiguration company defaults (NOT AgentSelectorService)
        if (!empty($options['ai_agent_id'])) {
            $agentLoaded = $this->setAgentById($options['ai_agent_id']);
            if (!$agentLoaded) {
                Log::warning('AiService: Specified agent not found, using company defaults', [
                    'ai_agent_id' => $options['ai_agent_id'],
                    'company_id' => $this->company->id,
                ]);
                // Don't call setAgent() - just use company defaults below
            }
        }
        // Note: We no longer call setAgent() (which uses AgentSelectorService)
        // Workflows now control which personality is used via ai_agent_id

        // Check if we have a valid configuration (either agent or legacy)
        if (!$this->agentConfig && !$this->config) {
            return AiResponse::error('AI is not configured for this company');
        }

        // Use agent config or fall back to company defaults
        $activeConfig = $this->agentConfig;
        if (!$activeConfig && $this->config) {
            if (!$this->config->auto_respond) {
                return AiResponse::error('Auto-respond is disabled');
            }
            // Use AiConfiguration defaults directly
            $activeConfig = [
                'primary_provider_id' => $this->config->primary_provider_id,
                'primary_model' => $this->config->primary_model,
                'max_tokens' => $this->config->max_tokens,
                'temperature' => (float) $this->config->temperature,
                'system_prompt' => $this->config->system_prompt,
                'personality_tone' => $this->config->personality_tone,
                'prohibited_topics' => $this->config->prohibited_topics ?? [],
                'custom_instructions' => $this->config->custom_instructions ?? [],
                'enable_product_search' => true,
                'rag_top_k' => 3,
                'knowledge_base_ids' => null,
            ];
        }

        // Phase 2.2: Apply workflow-level overrides (these take precedence)
        if (isset($options['enable_product_search'])) {
            $activeConfig['enable_product_search'] = $options['enable_product_search'];
        }
        if (isset($options['rag_top_k'])) {
            $activeConfig['rag_top_k'] = $options['rag_top_k'];
        }

        // Build context from conversation history and knowledge base using RAG
        $context = $this->buildContext($conversation, $customerMessage);

        // Check for recent image messages in the conversation
        $imageContext = $this->getRecentImageContext($conversation);
        if ($imageContext) {
            $context['image'] = $imageContext;
            Log::info('AiService: Image context added for vision', [
                'conversation_id' => $conversation->id,
                'image_count' => count($imageContext),
            ]);
        }

        // Add media context if available
        $mediaContext = $this->getMediaContext($latestMessage);
        if ($mediaContext) {
            $context = array_merge($context, $mediaContext);
            // Enhance customer message with media descriptions
            $customerMessage = $this->enhanceMessageWithMediaContext($customerMessage, $mediaContext);
        }

        // Use RAG service to get relevant knowledge base chunks
        $ragService = new RagService();

        // If this is a product image search, search for products based on image description
        $searchQuery = $customerMessage;
        if (!empty($mediaContext['image_description']) && !empty($mediaContext['product_search'])) {
            $searchQuery = $mediaContext['image_description'];
        }

        // Phase 2.1: Get agent-level RAG settings
        $ragTopK = $activeConfig['rag_top_k'] ?? 3;
        $knowledgeBaseIds = $activeConfig['knowledge_base_ids'] ?? null;

        $relevantContext = $ragService->getRelevantContext($this->company, $searchQuery, $ragTopK, $knowledgeBaseIds);

        // Phase 1.1: Filter out low-relevance KB chunks (similarity < 0.5)
        // This prevents "hello" from getting irrelevant context injected
        $relevantContext = array_filter($relevantContext, function ($chunk) {
            // Keep chunks without similarity score (keyword fallback) or with score >= 0.5
            return !isset($chunk['similarity']) || $chunk['similarity'] === null || $chunk['similarity'] >= 0.5;
        });
        $relevantContext = array_values($relevantContext); // Re-index array

        Log::info('AiService: RAG context retrieved', [
            'company_id' => $this->company->id,
            'conversation_id' => $conversation->id,
            'query' => $searchQuery,
            'relevant_chunks' => count($relevantContext),
            'context_data' => $relevantContext,
            'has_media' => !empty($mediaContext),
            'rag_top_k' => $ragTopK,
            'kb_scoped' => $knowledgeBaseIds !== null,
        ]);

        // Phase 1.2 + 2.1: Product search - respect agent-level enable_product_search setting
        $enableProductSearch = $activeConfig['enable_product_search'] ?? true;
        $relevantProducts = [];
        if ($enableProductSearch && $this->shouldSearchProducts($customerMessage, $mediaContext ?? [], $conversation)) {
            // Search for relevant products using ProductRagService
            $productRagService = new ProductRagService();

            // Extract price/stock filters from query
            $filters = $productRagService->extractFiltersFromQuery($customerMessage);

            // If image context indicates product search, use image description
            $productSearchQuery = $customerMessage;
            if (!empty($mediaContext['product_search']) && !empty($mediaContext['image_description'])) {
                $productSearchQuery = $mediaContext['image_description'];
            }

            // For short/vague queries like "How much?", include recent context to find products
            if (strlen($productSearchQuery) < 30) {
                $recentMessages = $conversation->messages()
                    ->orderBy('created_at', 'desc')
                    ->take(4)
                    ->pluck('content')
                    ->filter()
                    ->reverse()
                    ->implode(' ');
                if ($recentMessages) {
                    $productSearchQuery = $recentMessages . ' ' . $productSearchQuery;
                }
            }

            // Search products (limit to 5 to provide good options)
            $relevantProducts = $productRagService->searchProducts($this->company, $productSearchQuery, $filters, 5);

            Log::info('AiService: Product RAG results', [
                'company_id' => $this->company->id,
                'query' => $productSearchQuery,
                'filters' => $filters,
                'products_found' => count($relevantProducts),
            ]);
        } else {
            Log::info('AiService: Product search skipped', [
                'company_id' => $this->company->id,
                'reason' => !$enableProductSearch ? 'disabled_by_personality' : 'no_product_intent',
                'message' => substr($customerMessage, 0, 100),
            ]);
        }

        // Build system prompt with RAG context and products
        // Use agent's system prompt if available, otherwise use default
        $systemPrompt = $this->buildSystemPromptWithRag($conversation->customer, $relevantContext, $relevantProducts, $activeConfig);

        // Store relevant products in AiResponse for later use
        // (This is needed for fallback image sending in ProcessDelayedAiResponse)

        // Add media handling instructions if media is present
        if (!empty($mediaContext)) {
            $systemPrompt .= $this->buildMediaInstructions($mediaContext);
        }

        $context['system'] = $systemPrompt;

        Log::info('AiService: System prompt built', [
            'prompt_length' => strlen($systemPrompt),
            'full_prompt' => $systemPrompt,
            'customer_message' => $customerMessage,
            'has_media_context' => !empty($mediaContext),
        ]);

        // Extract knowledge IDs for cache key
        $knowledgeIds = array_column($relevantContext, 'knowledge_base_id');

        // Skip cache if message has media (each image is unique)
        $cachedResponse = null;
        if (empty($mediaContext)) {
            $cachedResponse = AiResponseCache::get($this->company->id, $customerMessage, $knowledgeIds, $conversation->id);
            if ($cachedResponse) {
                Log::info('Using cached AI response', [
                    'company_id' => $this->company->id,
                    'conversation_id' => $conversation->id,
                ]);
                return $cachedResponse;
            }
        }

        // Get the primary provider from active config
        $provider = AiServiceFactory::fromProviderId(
            $activeConfig['primary_provider_id'],
            $this->company
        );

        // Try primary provider - use vision API if images are present
        Log::info('AiService: Sending to AI provider', [
            'agent_type' => $this->agentType ?? 'legacy',
            'model' => $activeConfig['primary_model'],
            'max_tokens' => $activeConfig['max_tokens'],
            'temperature' => $activeConfig['temperature'],
            'customer_message' => $customerMessage,
            'has_media' => !empty($mediaContext),
            'has_images' => !empty($imageContext),
        ]);

        // Use vision API if images are in context
        if (!empty($imageContext) && method_exists($provider, 'sendMessageWithVision')) {
            $response = $provider->sendMessageWithVision($customerMessage, $context, [
                'model' => $activeConfig['primary_model'],
                'max_tokens' => $activeConfig['max_tokens'],
                'temperature' => $activeConfig['temperature'],
            ]);
        } else {
            $response = $provider->sendMessage($customerMessage, $context, [
                'model' => $activeConfig['primary_model'],
                'max_tokens' => $activeConfig['max_tokens'],
                'temperature' => $activeConfig['temperature'],
            ]);
        }

        // Log AI response for debugging
        Log::info('AiService: AI Response received', [
            'success' => $response->isSuccessful(),
            'ai_reply' => $response->isSuccessful() ? $response->getContent() : null,
            'error' => $response->isSuccessful() ? null : $response->getError(),
            'model_used' => $response->getModel(),
            'usage' => $response->getUsage(),
        ]);

        // If failed and fallback is configured, try fallback
        if (!$response->isSuccessful() && $this->config && $this->config->fallback_provider_id) {
            Log::warning("Primary AI provider failed, trying fallback", [
                'company_id' => $this->company->id,
                'error' => $response->getError(),
            ]);

            $fallbackProvider = AiServiceFactory::makeFallback($this->config);
            if ($fallbackProvider) {
                $response = $fallbackProvider->sendMessage($customerMessage, $context);
            }
        }

        // Cache successful responses (but not media responses)
        if ($response->isSuccessful() && empty($mediaContext)) {
            AiResponseCache::put($this->company->id, $customerMessage, $response, $knowledgeIds, null, $conversation->id);
        }

        return $response;
    }

    /**
     * Get media context from a message's processing results
     */
    protected function getMediaContext(?Message $message): ?array
    {
        if (!$message) {
            return null;
        }

        // Check if media has been processed
        $results = MediaProcessingResult::where('message_id', $message->id)
            ->completed()
            ->get();

        if ($results->isEmpty()) {
            return null;
        }

        $context = [];

        foreach ($results as $result) {
            if ($result->media_type === 'audio' && $result->text_content) {
                $context['audio_transcription'] = $result->text_content;
                $detectedLanguage = $result->analysis_data['language'] ?? null;
                $context['audio_language'] = $detectedLanguage;

                // Update customer's language preference if detected from audio
                if ($detectedLanguage && $message->conversation) {
                    $this->updateCustomerLanguage($message->conversation, $detectedLanguage);
                }
            }

            if ($result->media_type === 'image' && $result->text_content) {
                $context['image_description'] = $result->text_content;
                $context['product_search'] = $result->analysis_data['product_search'] ?? false;
            }
        }

        return empty($context) ? null : $context;
    }

    /**
     * Update customer's preferred language based on detected language
     */
    protected function updateCustomerLanguage(Conversation $conversation, string $languageCode): void
    {
        try {
            $customer = $conversation->customer;
            if ($customer && $customer->language !== $languageCode) {
                $customer->update(['language' => $languageCode]);
                Log::info('Customer language updated from audio detection', [
                    'customer_id' => $customer->id,
                    'old_language' => $customer->language,
                    'new_language' => $languageCode,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to update customer language', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Enhance the customer message with media context
     */
    protected function enhanceMessageWithMediaContext(string $message, array $mediaContext): string
    {
        $enhancedMessage = $message;

        // If there's an audio transcription, prepend it to the message
        if (!empty($mediaContext['audio_transcription'])) {
            $transcription = $mediaContext['audio_transcription'];
            $enhancedMessage = "[Customer sent voice message]: \"{$transcription}\"\n\n{$message}";
        }

        // If there's an image description, add context about what the image shows
        if (!empty($mediaContext['image_description'])) {
            $description = $mediaContext['image_description'];
            if (str_contains($message, '[Image]')) {
                $enhancedMessage = str_replace('[Image]', "[Customer sent an image showing: {$description}]", $enhancedMessage);
            } else {
                $enhancedMessage = "[Customer sent an image showing: {$description}]\n\n{$message}";
            }
        }

        return $enhancedMessage;
    }

    /**
     * Build additional instructions for handling media in responses
     */
    protected function buildMediaInstructions(array $mediaContext): string
    {
        $instructions = "\n\n# MEDIA CONTEXT\n";

        if (!empty($mediaContext['audio_transcription'])) {
            $instructions .= "The customer sent a voice message. The transcription is included in their message. ";
            $instructions .= "Respond naturally as if they had typed the message.\n";

            // Add explicit language instruction based on detected audio language
            if (!empty($mediaContext['audio_language'])) {
                $languageCode = $mediaContext['audio_language'];
                $languageName = $this->getLanguageName($languageCode);
                $instructions .= "\n**IMPORTANT - LANGUAGE REQUIREMENT:**\n";
                $instructions .= "The voice message was detected to be in {$languageName} (language code: {$languageCode}).\n";
                $instructions .= "You MUST respond in {$languageName}. Do NOT respond in a different language.\n";
            }
        }

        if (!empty($mediaContext['image_description'])) {
            $instructions .= "The customer sent an image. A description of the image is included. ";

            if (!empty($mediaContext['product_search'])) {
                $instructions .= "If this appears to be a product, help the customer find similar products or provide information about it based on your knowledge base.\n";
            } else {
                $instructions .= "Acknowledge what you see in the image and respond helpfully.\n";
            }
        }

        return $instructions;
    }

    /**
     * Get human-readable language name from ISO 639-1 code
     */
    protected function getLanguageName(string $code): string
    {
        $languages = [
            'en' => 'English',
            'ms' => 'Malay (Bahasa Malaysia)',
            'zh' => 'Chinese (Mandarin)',
            'ta' => 'Tamil',
            'hi' => 'Hindi',
            'id' => 'Indonesian (Bahasa Indonesia)',
            'th' => 'Thai',
            'vi' => 'Vietnamese',
            'ja' => 'Japanese',
            'ko' => 'Korean',
            'es' => 'Spanish',
            'fr' => 'French',
            'de' => 'German',
            'ar' => 'Arabic',
            'pt' => 'Portuguese',
            'ru' => 'Russian',
            'it' => 'Italian',
            'nl' => 'Dutch',
            'pl' => 'Polish',
            'tr' => 'Turkish',
        ];

        return $languages[$code] ?? ucfirst($code);
    }

    /**
     * Build context for the AI response
     */
    protected function buildContext(Conversation $conversation, string $customerMessage): array
    {
        $context = [];

        // Get recent conversation history (optimized - last 5 messages only)
        $context['history'] = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get()
            ->reverse()
            ->map(fn($msg) => [
                'is_from_customer' => $msg->is_from_customer,
                'content' => $msg->content,
            ])
            ->values()
            ->toArray();

        // Add customer context
        $customer = $conversation->customer;
        if ($customer) {
            $context['customer'] = [
                'name' => $customer->name,
                'email' => $customer->email,
            ];
        }

        return $context;
    }

    /**
     * Build comprehensive system prompt with RAG context (used by all platforms)
     */
    protected function buildSystemPromptWithRag($customer, array $ragContext, array $products = [], ?array $activeConfig = null): string
    {
        $company = $this->company;
        $aiConfig = $activeConfig ?? ($this->config ? [
            'system_prompt' => $this->config->system_prompt,
            'personality_tone' => $this->config->personality_tone,
            'prohibited_topics' => $this->config->prohibited_topics ?? [],
            'custom_instructions' => $this->config->custom_instructions ?? [],
        ] : null);

        // Base system identity - Be warm and personable
        $prompt = "You are {$company->name}'s friendly customer service representative. You chat naturally like a real person would - warm, helpful, and conversational.\n\n";

        // Core capabilities - Focus on being human-like
        $prompt .= "# HOW TO COMMUNICATE (VERY IMPORTANT)\n";
        $prompt .= "- Talk like a friendly coworker, not a robot or formal assistant\n";
        $prompt .= "- Use casual, warm language: 'Sure!', 'No problem!', 'Happy to help!', 'Got it!'\n";
        $prompt .= "- Keep it short and sweet - 1-3 sentences is often enough\n";
        $prompt .= "- Use contractions naturally: 'I'm', 'you're', 'we've', 'that's'\n";
        $prompt .= "- Add personality: light humor when appropriate, empathy when needed\n";
        $prompt .= "- Match the customer's energy and formality level\n";
        $prompt .= "- Respond in the SAME language the customer uses (English, Malay, Chinese, etc.)\n\n";

        $prompt .= "# WHAT TO AVOID\n";
        $prompt .= "- Robotic phrases: 'I understand your concern', 'I apologize for any inconvenience'\n";
        $prompt .= "- Overly formal: 'Dear valued customer', 'Please be advised', 'Kindly note'\n";
        $prompt .= "- Generic endings: 'Is there anything else I can help you with?'\n";
        $prompt .= "- Unnecessary filler: 'Certainly!', 'Absolutely!', 'Of course!'\n";
        $prompt .= "- not confirm ending like: 'If you... need help, more information, any questions?`'\n";
        $prompt .= "- keep using the same emoji repeatedly\n";
        $prompt .= "- Long explanations when a quick answer works\n\n";

        // Human routing rules
        $prompt .= "# WHEN TO ROUTE TO A HUMAN\n";
        $prompt .= "ONLY route to a human agent when:\n";
        $prompt .= "1. The customer explicitly asks for a human or agent\n";
        $prompt .= "2. You must perform actions (refunds, account changes, technical fixes)\n";
        $prompt .= "3. The customer is upset or emotional and needs human empathy\n";
        $prompt .= "4. You have tried to help 2–3 times and still cannot resolve the issue\n";
        $prompt .= "Do NOT route to human just because information is missing. Try to help first.\n\n";

        // Custom system instructions
        if ($aiConfig && !empty($aiConfig['system_prompt'])) {
            $prompt .= "# CUSTOM INSTRUCTIONS\n";
            $prompt .= trim($aiConfig['system_prompt']) . "\n\n";
        }

        // Personality
        if ($aiConfig && !empty($aiConfig['personality_tone'])) {
            $prompt .= "# PERSONALITY\n";
            $prompt .= "Tone: {$aiConfig['personality_tone']}\n";
            $prompt .= "Style: Friendly, calm, supportive, never pushy\n\n";
        }

        // Company info
        $prompt .= "# COMPANY INFORMATION\n";
        $prompt .= "Company Name: {$company->name}\n";
        if (!empty($company->email)) {
            $prompt .= "Contact Email: {$company->email}\n";
        }
        if (!empty($company->phone)) {
            $prompt .= "Contact Phone: {$company->phone}\n";
        }

        // Business hours (optional)
        if (!empty($company->business_hours)) {
            $hours = is_array($company->business_hours)
                ? $company->business_hours
                : json_decode($company->business_hours, true);

            if (!empty($hours)) {
                $openDays = [];
                foreach ($hours as $day) {
                    if (!empty($day['is_open'])) {
                        $openDays[] = ucfirst(substr($day['day'], 0, 3)) . " {$day['open']}-{$day['close']}";
                    }
                }
                if (!empty($openDays)) {
                    $prompt .= "Business Hours: " . implode(', ', $openDays) . "\n";
                }
            }
        }

        // Customer info (if known)
        if ($customer && !empty($customer->name) && $customer->name !== 'Website Visitor') {
            $prompt .= "\n# CUSTOMER INFO\n";
            $prompt .= "Name: {$customer->name}";
            if (!empty($customer->email)) {
                $prompt .= " | Email: {$customer->email}";
            }
            $prompt .= "\n";
        }

        // Appointment booking context
        $appointmentContext = $this->getAppointmentContext();
        if ($appointmentContext) {
            $prompt .= $appointmentContext;
        }

        // Knowledge base (RAG)
        if (!empty($ragContext)) {
            $prompt .= "\n# KNOWLEDGE BASE (USE AS PRIMARY REFERENCE)\n\n";

            $chunkCount = 0;
            foreach ($ragContext as $context) {
                if ($chunkCount >= 3) break;

                $chunkText = trim($context['chunk_text'] ?? $context['content'] ?? '');
                if ($chunkText) {
                    $title = $context['title'] ?? 'Information';
                    $chunkText = substr($chunkText, 0, 800);

                    $prompt .= "**{$title}:**\n{$chunkText}\n\n";
                    $chunkCount++;
                }
            }
        } else {
            $prompt .= "\n# NOTE\n";
            $prompt .= "No specific product or service data is available. Provide general assistance about {$company->name}.\n\n";
        }

        // Product catalog context
        if (!empty($products)) {
            $productRagService = new ProductRagService();
            $prompt .= $productRagService->formatProductsForContext($products);

            // Count products with images
            $productsWithImages = array_filter($products, fn($p) => !empty($p['image']));

            $prompt .= "# PRODUCT RECOMMENDATIONS\n";
            $prompt .= "When discussing or recommending products:\n";
            $prompt .= "- If multiple relevant products exist, mention 2-3 options to give the customer choices\n";
            $prompt .= "- Include prices when recommending products (always with currency)\n";
            $prompt .= "- Briefly describe each product's key feature or benefit\n";
            $prompt .= "- If customer asks about a specific type (e.g., toys), show all matching products from the list\n";
            $prompt .= "- Format product recommendations clearly: name, price, and a short description\n\n";

            if (!empty($productsWithImages)) {
                $prompt .= "# PRODUCT IMAGES - MANDATORY WHEN RECOMMENDING\n";
                $prompt .= "When you recommend a product that has an Image URL, you MUST include the image.\n";
                $prompt .= "Format: Place the image tag on its own line AFTER mentioning the product:\n";
                $prompt .= "[PRODUCT_IMAGE: paste_the_exact_image_url_from_above]\n\n";
                $prompt .= "Example:\n";
                $prompt .= "Customer: 'What toys do you have?'\n";
                $prompt .= "You: 'We have the Blue Teddy Bear - RM 35! Super soft and cuddly.\n";
                $prompt .= "[PRODUCT_IMAGE: https://example.com/storage/media/1/products/teddy.jpg]\n";
                $prompt .= "Also the Robot Car - RM 49, great for ages 5+!\n";
                $prompt .= "[PRODUCT_IMAGE: https://example.com/storage/media/1/products/car.jpg]'\n\n";
                $prompt .= "Rules:\n";
                $prompt .= "- ONLY use exact Image URLs from the product list above, never make up URLs\n";
                $prompt .= "- Maximum 3 images per response\n";
                $prompt .= "- Always include image when customer asks 'show me', 'what does it look like', 'can you show', etc.\n";
                $prompt .= "- NEVER paste raw URLs in your text response. ONLY use the [PRODUCT_IMAGE: url] tag format for images\n";
                $prompt .= "- The image will be sent as a native attachment on the customer's platform, so do NOT include any URL in your text\n\n";
            }
        }

        // Response examples - Show the AI what good looks like
        $prompt .= "# EXAMPLE RESPONSES (BE LIKE THIS)\n";
        $prompt .= "Customer: 'What time do you close?'\n";
        $prompt .= "Good: 'We're open until 6pm today! 😊'\n";
        $prompt .= "Bad: 'Thank you for your inquiry. Our business hours are from 9:00 AM to 6:00 PM. Is there anything else I can assist you with today?'\n\n";

        $prompt .= "Customer: 'Do you have this in blue?'\n";
        $prompt .= "Good: 'Let me check! Yes, we have blue in stock. Would you like me to set one aside for you?'\n";
        $prompt .= "Bad: 'I understand you are inquiring about product availability. Yes, we currently have the blue variant in stock. Please let me know if you need further assistance.'\n\n";

        // Final response rules - Keep it simple
        $prompt .= "# QUICK RULES\n";
        $prompt .= "- Answer directly, then stop (no generic closings)\n";
        $prompt .= "- Keep responses under 100 words when possible\n";
        $prompt .= "- Be honest if you don't know something\n";
        $prompt .= "- Never make up prices, policies, or promises\n";
        $prompt .= "- Use emojis sparingly (1-2 per message max) if it fits the tone\n";
        $prompt .= "- Route to human only when truly needed\n\n";

        // Prohibited topics
        if ($aiConfig && !empty($aiConfig['prohibited_topics'])) {
            $prompt .= "# PROHIBITED TOPICS\n";
            $prompt .= "Avoid discussing: " . implode(', ', $aiConfig['prohibited_topics']) . "\n";
        }

        return $prompt;
    }


    /**
     * Find relevant knowledge base entries for the message
     * DEPRECATED - Use RagService instead
     */
    protected function findRelevantKnowledge(string $message): array
    {
        // For now, do a simple keyword search
        // In production, this should use vector similarity search
        $keywords = array_filter(explode(' ', strtolower($message)), fn($w) => strlen($w) > 3);

        if (empty($keywords)) {
            return [];
        }

        $query = KnowledgeBaseEmbedding::whereHas('knowledgeBase', function ($q) {
            $q->where('company_id', $this->company->id)
                ->where('is_active', true);
        });

        foreach ($keywords as $keyword) {
            $query->orWhere('chunk_text', 'LIKE', "%{$keyword}%");
        }

        return $query->take(5)
            ->get()
            ->map(fn($embedding) => [
                'content' => $embedding->chunk_text,
                'category' => $embedding->knowledgeBase->category ?? null,
            ])
            ->toArray();
    }

    /**
     * Check if AI should respond based on confidence threshold
     */
    public function shouldAutoRespond(AiResponseInterface $response): bool
    {
        if (!$this->config?->auto_respond) {
            return false;
        }

        $confidence = $response->getConfidence();
        $threshold = $this->config->confidence_threshold ?? 0.7;

        // If no confidence score, assume we should respond
        if ($confidence === null) {
            return true;
        }

        return $confidence >= $threshold;
    }

    /**
     * Generate a simple response without conversation context
     */
    public function generateSimpleResponse(string $systemPrompt, string $userMessage): AiResponseInterface
    {
        if (!$this->config) {
            return AiResponse::error('AI is not configured for this company');
        }

        $provider = AiServiceFactory::fromConfiguration($this->config);

        return $provider->generateResponse($systemPrompt, $userMessage, [
            'model' => $this->config->primary_model,
            'max_tokens' => $this->config->max_tokens,
            'temperature' => (float) $this->config->temperature,
        ]);
    }

    /**
     * Get the current AI configuration
     */
    public function getConfiguration(): ?AiConfiguration
    {
        return $this->config;
    }

    /**
     * Check if AI is configured for the company
     */
    public function isConfigured(): bool
    {
        return $this->config !== null && $this->config->primary_provider_id !== null;
    }

    /**
     * Get recent image messages from the conversation for vision AI
     */
    protected function getRecentImageContext(Conversation $conversation): ?array
    {
        // Get last 3 image messages within the last 10 messages
        $recentImageMessages = $conversation->messages()
            ->where('message_type', 'image')
            ->where('is_from_customer', true)
            ->where('created_at', '>', now()->subMinutes(5))
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get();

        if ($recentImageMessages->isEmpty()) {
            return null;
        }

        $images = [];
        foreach ($recentImageMessages as $message) {
            if (!empty($message->media_urls)) {
                foreach ($message->media_urls as $media) {
                    if ($media['type'] === 'image' && isset($media['url'])) {
                        // Download image and convert to base64 (Facebook URLs are temporary)
                        $imageData = $this->downloadImageAsBase64($media['url']);
                        if ($imageData) {
                            $images[] = [
                                'base64' => $imageData['base64'],
                                'mime_type' => $imageData['mime_type'],
                                'detail' => 'auto',
                            ];
                            Log::info('AiService: Downloaded image for vision', [
                                'url' => substr($media['url'], 0, 100) . '...',
                                'mime_type' => $imageData['mime_type'],
                                'size' => strlen($imageData['base64']),
                            ]);
                        }
                    }
                }
            }
        }

        return empty($images) ? null : $images;
    }

    /**
     * Download image from URL and convert to base64
     */
    protected function downloadImageAsBase64(string $url): ?array
    {
        try {
            $response = Http::timeout(30)->get($url);

            if ($response->successful()) {
                $contentType = $response->header('Content-Type') ?? 'image/jpeg';
                $base64 = base64_encode($response->body());

                return [
                    'base64' => $base64,
                    'mime_type' => $contentType,
                ];
            }

            Log::warning('AiService: Failed to download image', [
                'url' => substr($url, 0, 100) . '...',
                'status' => $response->status(),
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('AiService: Error downloading image', [
                'url' => substr($url, 0, 100) . '...',
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get appointment booking context for AI prompt
     */
    protected function getAppointmentContext(): ?string
    {
        // Check if calendar/appointment booking is enabled for this company
        if (!AppointmentService::isAvailable($this->company->id)) {
            return null;
        }

        $bookingContext = AppointmentService::getBookingContext($this->company->id);
        if (!$bookingContext) {
            return null;
        }

        $prompt = "\n# APPOINTMENT BOOKING\n";
        $prompt .= "You can help customers book appointments. Here's what you need to know:\n\n";

        $prompt .= "**Booking Details:**\n";
        $prompt .= "- Appointment duration: {$bookingContext['slot_duration']} minutes\n";
        $prompt .= "- Minimum notice required: {$bookingContext['min_notice_hours']} hours in advance\n";
        $prompt .= "- Can book up to {$bookingContext['advance_booking_days']} days in advance\n";
        $prompt .= "- Timezone: {$bookingContext['timezone']}\n\n";

        // Add custom booking instructions if set
        if (!empty($bookingContext['booking_instructions'])) {
            $prompt .= "**Special Instructions:**\n";
            $prompt .= $bookingContext['booking_instructions'] . "\n\n";
        }

        // Get available dates to show
        try {
            $appointmentService = new AppointmentService($this->company->id);
            $availableDatesText = $appointmentService->formatDatesForAI(7);
            $prompt .= "**Current Availability:**\n";
            $prompt .= $availableDatesText . "\n\n";
        } catch (\Exception $e) {
            Log::warning('AiService: Could not fetch appointment availability', [
                'company_id' => $this->company->id,
                'error' => $e->getMessage(),
            ]);
        }

        $prompt .= "**How to Handle Appointment Requests:**\n";
        $prompt .= "1. When a customer asks about booking, mention the available dates above\n";
        $prompt .= "2. Ask what day works best for them\n";
        $prompt .= "3. Once they pick a date, tell them available time slots for that day\n";
        $prompt .= "4. Collect their name and contact info (phone or email)\n";
        $prompt .= "5. Confirm the appointment details before finalizing\n";
        $prompt .= "6. When ready to book, format like: [BOOK_APPOINTMENT: date=YYYY-MM-DD, time=HH:MM, name=Customer Name, phone=XXX, email=XXX]\n\n";

        $prompt .= "**Important:**\n";
        $prompt .= "- Always check availability before confirming a time\n";
        $prompt .= "- Be helpful if their preferred time isn't available - suggest alternatives\n";
        $prompt .= "- Don't make promises about specific times until you've shown availability\n\n";

        return $prompt;
    }

    /**
     * Phase 1.2: Determine if we should search products based on message intent
     * Saves ~200-500ms latency + ~500 tokens for non-product messages
     */
    protected function shouldSearchProducts(string $message, array $mediaContext, Conversation $conversation): bool
    {
        // Skip if company has no products (cache check for 5 minutes)
        $hasProducts = Cache::remember(
            "company_{$this->company->id}_has_products",
            300,
            fn() => Product::where('company_id', $this->company->id)->where('is_active', true)->exists()
        );

        if (!$hasProducts) {
            return false;
        }

        // If media context indicates product search (customer sent product image)
        if (!empty($mediaContext['product_search'])) {
            return true;
        }

        // Product intent keywords (English + common Malay/Chinese terms)
        $keywords = [
            // English
            'price', 'buy', 'cost', 'product', 'show', 'recommend', 'stock', 'order',
            'purchase', 'available', 'catalog', 'how much', 'item', 'sell', 'shop',
            'checkout', 'cart', 'delivery', 'shipping', 'discount', 'sale', 'promo',
            'offer', 'deal', 'cheap', 'expensive', 'budget', 'compare', 'option',
            // Malay
            'harga', 'beli', 'produk', 'ada', 'stok', 'jual', 'barang', 'murah', 'mahal',
            'berapa', 'diskaun', 'promosi', 'tawaran',
            // Chinese (common terms)
            '价格', '买', '产品', '多少钱', '有货', '便宜', '贵', '折扣', '促销',
        ];

        $messageLower = strtolower($message);
        foreach ($keywords as $keyword) {
            if (stripos($messageLower, strtolower($keyword)) !== false) {
                return true;
            }
        }

        // Check if recent conversation history was about products (last 3 messages)
        $recentMessages = $conversation->messages()
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->pluck('content')
            ->implode(' ');

        if ($recentMessages) {
            $recentLower = strtolower($recentMessages);
            // Only check core product keywords in history to avoid false positives
            $coreKeywords = ['product', 'price', 'buy', 'order', 'harga', 'beli', '价格', '买'];
            foreach ($coreKeywords as $keyword) {
                if (stripos($recentLower, strtolower($keyword)) !== false) {
                    return true;
                }
            }
        }

        return false;
    }
}
