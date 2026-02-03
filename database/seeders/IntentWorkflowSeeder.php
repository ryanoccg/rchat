<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Message;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;

class IntentWorkflowSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create for all existing companies
        Company::all()->each(function ($company) {
            $this->createWorkflow($company);
        });
    }

    /**
     * Create the default intent-based routing workflow for a company.
     */
    public function createWorkflow(Company $company): ?Workflow
    {
        // Check if workflow already exists
        $existing = Workflow::where('company_id', $company->id)
            ->where('name', 'Intent-Based Routing')
            ->first();

        if ($existing) {
            Log::info('IntentWorkflowSeeder: Workflow already exists', [
                'company_id' => $company->id,
                'workflow_id' => $existing->id,
            ]);
            return $existing;
        }

        $workflow = Workflow::create([
            'company_id' => $company->id,
            'name' => 'Intent-Based Routing',
            'description' => 'Automatically classifies customer intent and routes to optimized AI response.',
            'trigger_type' => 'message_received',
            'is_active' => true,
            'workflow_definition' => [
                'nodes' => [],
                'connections' => [],
            ],
        ]);

        Log::info('IntentWorkflowSeeder: Created workflow', [
            'company_id' => $company->id,
            'workflow_id' => $workflow->id,
        ]);

        // Create workflow steps
        $this->createSteps($workflow);

        return $workflow;
    }

    /**
     * Create the workflow steps for intent classification and routing.
     */
    protected function createSteps(Workflow $workflow): void
    {
        // Step 1: AI Condition - Classify Intent
        $classifyStep = WorkflowStep::create([
            'workflow_id' => $workflow->id,
            'step_type' => 'condition',
            'name' => 'Classify Customer Intent',
            'position' => ['x' => 250, 'y' => 100],
            'config' => [
                'condition_type' => 'ai_condition',
                'prompt' => 'Classify this customer message into ONE of these intents:
- general_inquiry: greetings, thank you, casual chat, how are you
- ask_for_service: appointment booking, scheduling, reservation requests
- customer_service: complaints, issues, problems, refund requests, technical support
- company_information: questions about hours, location, contact info, policies
- product_inquiry: questions about products, prices, availability, features

Return ONLY JSON format: {"intent": "category_name", "confidence": 0.95}

Message: {message}',
                'return_result' => true, // Return structured result instead of boolean
            ],
        ]);

        // Define intent configurations with optimized settings
        $intents = [
            'general_inquiry' => [
                'label' => 'General Inquiry',
                'rag_top_k' => 1,
                'enable_product_search' => false,
                'additional_context' => 'Friendly conversation mode.',
            ],
            'ask_for_service' => [
                'label' => 'Ask for Service',
                'rag_top_k' => 3,
                'enable_product_search' => false,
                'additional_context' => 'Booking assistance. Help customers schedule appointments.',
            ],
            'customer_service' => [
                'label' => 'Customer Service',
                'rag_top_k' => 4,
                'enable_product_search' => false,
                'additional_context' => 'Support mode. Show empathy for their issue.',
            ],
            'company_information' => [
                'label' => 'Company Information',
                'rag_top_k' => 5,
                'enable_product_search' => false,
                'additional_context' => 'Company information. Use KB for hours, location, policies.',
            ],
            'product_inquiry' => [
                'label' => 'Product Inquiry',
                'rag_top_k' => 2,
                'enable_product_search' => true,
                'additional_context' => 'Product recommendation. Suggest 2-3 relevant options.',
            ],
        ];

        $yOffset = 300;
        foreach ($intents as $intentKey => $settings) {
            // Condition step to check intent value
            $conditionStep = WorkflowStep::create([
                'workflow_id' => $workflow->id,
                'step_type' => 'condition',
                'name' => "Is {$settings['label']}?",
                'position' => ['x' => 150, 'y' => $yOffset],
                'config' => [
                    'condition_type' => 'intent_value',
                    'intent' => $intentKey,
                ],
            ]);

            // AI Response action for this intent
            $responseStep = WorkflowStep::create([
                'workflow_id' => $workflow->id,
                'step_type' => 'action',
                'name' => "Send {$settings['label']} Response",
                'position' => ['x' => 400, 'y' => $yOffset],
                'config' => [
                    'action_type' => 'send_ai_response',
                    'additional_context' => $settings['additional_context'],
                    'enable_product_search' => $settings['enable_product_search'],
                    'rag_top_k' => $settings['rag_top_k'],
                ],
            ]);

            $yOffset += 150;
        }

        Log::info('IntentWorkflowSeeder: Created workflow steps', [
            'workflow_id' => $workflow->id,
            'steps_created' => count($intents) * 2 + 1,
        ]);
    }
}
