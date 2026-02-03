<?php

namespace App\Observers;

use App\Models\AiAgent;
use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CompanyObserver
{
    /**
     * Handle the Company "created" event.
     */
    public function created(Company $company): void
    {
        $this->ensureAiConfigurationExists($company);
        $this->initializeDefaultAiPersonalities($company);
        $this->initializeDefaultWorkflows($company);
        $this->initializeIntentWorkflow($company);
    }

    /**
     * Ensure AI configuration exists for the company
     */
    protected function ensureAiConfigurationExists(Company $company): void
    {
        // Check if AI configuration already exists (might have been created in AuthController)
        if (AiConfiguration::where('company_id', $company->id)->exists()) {
            return;
        }

        // Get OpenAI provider (most commonly used)
        $openAiProvider = AiProvider::where('slug', 'openai')->first();

        if (!$openAiProvider) {
            // Fallback to first available provider
            $openAiProvider = AiProvider::where('is_active', true)->first();
        }

        if (!$openAiProvider) {
            if (!app()->environment('testing')) {
                Log::warning('Cannot create AI configuration for company - no AI providers available', [
                    'company_id' => $company->id,
                ]);
            }
            return;
        }

        try {
            AiConfiguration::create([
                'company_id' => $company->id,
                'primary_provider_id' => $openAiProvider->id,
                'primary_model' => 'gpt-4o-mini',
                'system_prompt' => "You are a helpful and professional customer service assistant for {$company->name}. Your goal is to assist customers with their inquiries in a friendly, professional, and efficient manner.

Key guidelines:
- Be polite, patient, and empathetic
- Provide clear and accurate information
- If you don't know something, be honest about it
- Keep responses concise but helpful
- Ask clarifying questions when needed",
                'personality_tone' => 'professional',
                'prohibited_topics' => [],
                'custom_instructions' => [],
                'confidence_threshold' => 0.7,
                'auto_respond' => true,
                'max_tokens' => 500,
                'temperature' => 0.7,
            ]);

            Log::info('Created default AI configuration for company', [
                'company_id' => $company->id,
                'provider' => $openAiProvider->slug,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create AI configuration for company', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initialize default AI personalities for the company.
     * These are simplified agents (no trigger logic, just personality config).
     */
    protected function initializeDefaultAiPersonalities(Company $company): void
    {
        if (AiAgent::where('company_id', $company->id)->exists()) {
            return;
        }

        $aiProvider = AiProvider::where('slug', 'openai')->first()
            ?? AiProvider::where('is_active', true)->first();

        if (!$aiProvider) {
            if (!app()->environment('testing')) {
                Log::warning('Cannot create default AI personalities - no AI providers available', [
                    'company_id' => $company->id,
                ]);
            }
            return;
        }

        try {
            $personalities = $this->getDefaultPersonalities($company, $aiProvider->id);

            DB::transaction(function () use ($personalities) {
                foreach ($personalities as $personalityData) {
                    AiAgent::create($personalityData);
                }
            });

            Log::info('Created default AI personalities for company', [
                'company_id' => $company->id,
                'count' => count($personalities),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create default AI personalities for company', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get default AI personality configurations.
     */
    protected function getDefaultPersonalities(Company $company, int $aiProviderId): array
    {
        $companyName = $company->name;

        return [
            [
                'company_id' => $company->id,
                'ai_provider_id' => $aiProviderId,
                'name' => 'General Assistant',
                'slug' => 'general-assistant',
                'description' => 'Default personality for general customer inquiries',
                'agent_type' => 'general',
                'system_prompt' => "You are a helpful customer service assistant for {$companyName}. Be professional, friendly, and concise. Help customers with their questions and guide them to the right solutions.",
                'model' => 'gpt-4o-mini',
                'temperature' => 0.7,
                'max_tokens' => 300,
                'confidence_threshold' => 0.7,
                'trigger_conditions' => null,
                'is_personality_only' => true,
                'is_active' => true,
                'priority' => 10,
            ],
            [
                'company_id' => $company->id,
                'ai_provider_id' => $aiProviderId,
                'name' => 'Welcome Agent',
                'slug' => 'welcome-agent',
                'description' => 'Warm and welcoming personality for new customers',
                'agent_type' => 'new_customer',
                'system_prompt' => "You are a warm and welcoming assistant for {$companyName}. This is a new customer's first interaction - make them feel valued! Introduce yourself, explain how you can help, and ask about their needs. Be extra friendly and patient.",
                'model' => 'gpt-4o-mini',
                'temperature' => 0.8,
                'max_tokens' => 350,
                'confidence_threshold' => 0.6,
                'trigger_conditions' => null,
                'is_personality_only' => true,
                'is_active' => true,
                'priority' => 20,
            ],
            [
                'company_id' => $company->id,
                'ai_provider_id' => $aiProviderId,
                'name' => 'VIP Concierge',
                'slug' => 'vip-concierge',
                'description' => 'Premium service personality for VIP customers',
                'agent_type' => 'vip',
                'system_prompt' => "You are a premium concierge for {$companyName}'s valued VIP customers. Provide exceptional, personalized service. Acknowledge their loyalty, offer exclusive assistance, and go above and beyond to help. Use a sophisticated but warm tone.",
                'model' => 'gpt-4o-mini',
                'temperature' => 0.6,
                'max_tokens' => 400,
                'confidence_threshold' => 0.8,
                'trigger_conditions' => null,
                'is_personality_only' => true,
                'is_active' => true,
                'priority' => 30,
            ],
        ];
    }

    /**
     * Initialize default workflows for the company.
     * Creates an "AI Auto-Response" workflow that routes to different AI personalities.
     */
    protected function initializeDefaultWorkflows(Company $company): void
    {
        if (Workflow::where('company_id', $company->id)->exists()) {
            return;
        }

        // Get the AI personalities we just created
        $generalAgent = AiAgent::where('company_id', $company->id)
            ->where('slug', 'general-assistant')
            ->first();
        $welcomeAgent = AiAgent::where('company_id', $company->id)
            ->where('slug', 'welcome-agent')
            ->first();
        $vipAgent = AiAgent::where('company_id', $company->id)
            ->where('slug', 'vip-concierge')
            ->first();

        if (!$generalAgent) {
            if (!app()->environment('testing')) {
                Log::warning('Cannot create default workflow - no AI personalities available', [
                    'company_id' => $company->id,
                ]);
            }
            return;
        }

        try {
            DB::transaction(function () use ($company, $generalAgent, $welcomeAgent, $vipAgent) {
                // Create the main AI Auto-Response workflow
                $workflow = Workflow::create([
                    'company_id' => $company->id,
                    'name' => 'AI Auto-Response',
                    'description' => 'Automatically responds to customer messages using AI personalities based on customer context',
                    'status' => 'active',
                    'trigger_type' => 'message_received',
                    'trigger_config' => [
                        'message_types' => ['text', 'image', 'audio'],
                    ],
                    'workflow_definition' => [],
                    'execution_mode' => 'sequential',
                ]);

                // Step 1: Check if new customer (total messages <= 2)
                $checkNewCustomer = WorkflowStep::create([
                    'company_id' => $company->id,
                    'workflow_id' => $workflow->id,
                    'step_type' => 'condition',
                    'name' => 'Check New Customer',
                    'description' => 'Check if customer is new (2 or fewer total messages)',
                    'position' => ['x' => 250, 'y' => 100],
                    'config' => [
                        'condition_type' => 'customer_attribute',
                        'field' => 'customer_type',
                        'operator' => 'equals',
                        'value' => 'new',
                    ],
                    'next_steps' => [],
                ]);

                // Step 2a: Send Welcome AI Response (for new customers)
                $sendWelcome = WorkflowStep::create([
                    'company_id' => $company->id,
                    'workflow_id' => $workflow->id,
                    'step_type' => 'action',
                    'name' => 'Send Welcome Response',
                    'description' => 'Send AI response using Welcome Agent',
                    'position' => ['x' => 100, 'y' => 250],
                    'config' => [
                        'action_type' => 'send_ai_response',
                        'ai_agent_id' => $welcomeAgent?->id ?? $generalAgent->id,
                    ],
                    'next_steps' => [],
                ]);

                // Step 2b: Check if VIP customer (total messages >= 11)
                $checkVip = WorkflowStep::create([
                    'company_id' => $company->id,
                    'workflow_id' => $workflow->id,
                    'step_type' => 'condition',
                    'name' => 'Check VIP Customer',
                    'description' => 'Check if customer is VIP (11+ total messages)',
                    'position' => ['x' => 400, 'y' => 250],
                    'config' => [
                        'condition_type' => 'customer_attribute',
                        'field' => 'customer_type',
                        'operator' => 'equals',
                        'value' => 'vip',
                    ],
                    'next_steps' => [],
                ]);

                // Step 3a: Send VIP AI Response
                $sendVip = WorkflowStep::create([
                    'company_id' => $company->id,
                    'workflow_id' => $workflow->id,
                    'step_type' => 'action',
                    'name' => 'Send VIP Response',
                    'description' => 'Send AI response using VIP Concierge',
                    'position' => ['x' => 300, 'y' => 400],
                    'config' => [
                        'action_type' => 'send_ai_response',
                        'ai_agent_id' => $vipAgent?->id ?? $generalAgent->id,
                    ],
                    'next_steps' => [],
                ]);

                // Step 3b: Send General AI Response (default)
                $sendGeneral = WorkflowStep::create([
                    'company_id' => $company->id,
                    'workflow_id' => $workflow->id,
                    'step_type' => 'action',
                    'name' => 'Send General Response',
                    'description' => 'Send AI response using General Assistant',
                    'position' => ['x' => 500, 'y' => 400],
                    'config' => [
                        'action_type' => 'send_ai_response',
                        'ai_agent_id' => $generalAgent->id,
                    ],
                    'next_steps' => [],
                ]);

                // Update next_steps to link the workflow
                $checkNewCustomer->update([
                    'next_steps' => [
                        ['step_id' => $sendWelcome->id, 'condition' => 'true'],
                        ['step_id' => $checkVip->id, 'condition' => 'false'],
                    ],
                ]);

                $checkVip->update([
                    'next_steps' => [
                        ['step_id' => $sendVip->id, 'condition' => 'true'],
                        ['step_id' => $sendGeneral->id, 'condition' => 'false'],
                    ],
                ]);

                // Update workflow definition with step IDs for visual builder
                $workflow->update([
                    'workflow_definition' => [
                        'entry_step_id' => $checkNewCustomer->id,
                        'steps' => [
                            $checkNewCustomer->id,
                            $sendWelcome->id,
                            $checkVip->id,
                            $sendVip->id,
                            $sendGeneral->id,
                        ],
                    ],
                ]);
            });

            Log::info('Created default AI Auto-Response workflow for company', [
                'company_id' => $company->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create default workflow for company', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Initialize intent-based routing workflow for the company.
     * This creates a workflow that classifies customer intent and routes to optimized AI responses.
     */
    protected function initializeIntentWorkflow(Company $company): void
    {
        // Check if intent workflow already exists
        $existing = Workflow::where('company_id', $company->id)
            ->where('name', 'Intent-Based Routing')
            ->first();

        if ($existing) {
            return;
        }

        try {
            $seeder = new \Database\Seeders\IntentWorkflowSeeder();
            $seeder->createWorkflow($company);

            Log::info('Created intent-based routing workflow for company', [
                'company_id' => $company->id,
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to create intent workflow for company', [
                'company_id' => $company->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
