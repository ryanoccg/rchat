<?php

namespace App\Console\Commands;

use App\Models\AiAgent;
use App\Models\Company;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MigrateAiAgentsToPersonalities extends Command
{
    protected $signature = 'agents:migrate-to-personalities
                            {--dry-run : Show what would be done without making changes}
                            {--company= : Only migrate a specific company ID}';

    protected $description = 'Migrate existing AI agents to personality-only mode and create default workflows';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $companyId = $this->option('company');

        if ($dryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }

        $query = Company::query();
        if ($companyId) {
            $query->where('id', $companyId);
        }

        $companies = $query->get();
        $this->info("Processing {$companies->count()} companies...\n");

        $stats = [
            'agents_converted' => 0,
            'workflows_created' => 0,
            'companies_processed' => 0,
            'errors' => 0,
        ];

        foreach ($companies as $company) {
            $this->info("Processing company: {$company->name} (ID: {$company->id})");

            try {
                if (!$dryRun) {
                    DB::beginTransaction();
                }

                // Step 1: Convert legacy agents to personality-only
                $legacyAgents = AiAgent::where('company_id', $company->id)
                    ->where('is_personality_only', false)
                    ->get();

                if ($legacyAgents->count() > 0) {
                    $this->line("  - Found {$legacyAgents->count()} legacy agents to convert");

                    if (!$dryRun) {
                        foreach ($legacyAgents as $agent) {
                            $agent->update([
                                'is_personality_only' => true,
                                'trigger_conditions' => null, // Clear trigger conditions
                            ]);
                            $stats['agents_converted']++;
                        }
                    } else {
                        $stats['agents_converted'] += $legacyAgents->count();
                    }
                    $this->info("  - Converted {$legacyAgents->count()} agents to personality-only");
                } else {
                    $this->line("  - No legacy agents to convert");
                }

                // Step 2: Create default workflow if none exists with message_received trigger
                $hasMessageWorkflow = Workflow::where('company_id', $company->id)
                    ->where('trigger_type', 'message_received')
                    ->where('status', 'active')
                    ->exists();

                if (!$hasMessageWorkflow) {
                    $this->line("  - No active message_received workflow found, creating default...");

                    if (!$dryRun) {
                        $this->createDefaultWorkflow($company);
                        $stats['workflows_created']++;
                    } else {
                        $stats['workflows_created']++;
                    }
                    $this->info("  - Created default AI Auto-Response workflow");
                } else {
                    $this->line("  - Active message_received workflow already exists, skipping");
                }

                if (!$dryRun) {
                    DB::commit();
                }

                $stats['companies_processed']++;
                $this->info("  - Company processed successfully\n");

            } catch (\Exception $e) {
                if (!$dryRun) {
                    DB::rollBack();
                }
                $stats['errors']++;
                $this->error("  - Error processing company {$company->id}: {$e->getMessage()}\n");
                Log::error("Migration error for company {$company->id}", [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Summary
        $this->newLine();
        $this->info('=== Migration Summary ===');
        $this->line("Companies processed: {$stats['companies_processed']}");
        $this->line("Agents converted: {$stats['agents_converted']}");
        $this->line("Workflows created: {$stats['workflows_created']}");

        if ($stats['errors'] > 0) {
            $this->error("Errors: {$stats['errors']}");
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('This was a dry run. Run without --dry-run to apply changes.');
        }

        return $stats['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function createDefaultWorkflow(Company $company): void
    {
        // Get existing AI personalities
        $generalAgent = AiAgent::where('company_id', $company->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('slug', 'general-assistant')
                    ->orWhere('agent_type', 'general');
            })
            ->first();

        $welcomeAgent = AiAgent::where('company_id', $company->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('slug', 'welcome-agent')
                    ->orWhere('agent_type', 'new_customer');
            })
            ->first();

        $vipAgent = AiAgent::where('company_id', $company->id)
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('slug', 'vip-concierge')
                    ->orWhere('agent_type', 'vip');
            })
            ->first();

        // Fall back to any active agent if none found
        if (!$generalAgent) {
            $generalAgent = AiAgent::where('company_id', $company->id)
                ->where('is_active', true)
                ->first();
        }

        if (!$generalAgent) {
            $this->warn("  - No active AI agents found for company {$company->id}, skipping workflow creation");
            return;
        }

        // Create the workflow
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

        // Create steps based on available agents
        if ($welcomeAgent && $vipAgent) {
            // Full workflow with welcome and VIP handling
            $this->createFullWorkflowSteps($workflow, $company, $generalAgent, $welcomeAgent, $vipAgent);
        } else {
            // Simple workflow with just general response
            $this->createSimpleWorkflowSteps($workflow, $company, $generalAgent);
        }
    }

    protected function createFullWorkflowSteps(Workflow $workflow, Company $company, AiAgent $generalAgent, AiAgent $welcomeAgent, AiAgent $vipAgent): void
    {
        // Step 1: Check if new customer
        $checkNewCustomer = WorkflowStep::create([
            'company_id' => $company->id,
            'workflow_id' => $workflow->id,
            'step_type' => 'condition',
            'name' => 'Check New Customer',
            'description' => 'Check if customer has 2 or fewer messages',
            'position' => ['x' => 250, 'y' => 100],
            'config' => [
                'condition_type' => 'conversation_attribute',
                'field' => 'message_count',
                'operator' => 'less_equal',
                'value' => 2,
            ],
            'next_steps' => [],
        ]);

        // Step 2a: Send Welcome Response
        $sendWelcome = WorkflowStep::create([
            'company_id' => $company->id,
            'workflow_id' => $workflow->id,
            'step_type' => 'action',
            'name' => 'Send Welcome Response',
            'description' => 'Send AI response using Welcome Agent',
            'position' => ['x' => 100, 'y' => 250],
            'config' => [
                'action_type' => 'send_ai_response',
                'ai_agent_id' => $welcomeAgent->id,
            ],
            'next_steps' => [],
        ]);

        // Step 2b: Check VIP
        $checkVip = WorkflowStep::create([
            'company_id' => $company->id,
            'workflow_id' => $workflow->id,
            'step_type' => 'condition',
            'name' => 'Check VIP Customer',
            'description' => 'Check if customer has 10 or more messages',
            'position' => ['x' => 400, 'y' => 250],
            'config' => [
                'condition_type' => 'conversation_attribute',
                'field' => 'message_count',
                'operator' => 'greater_equal',
                'value' => 10,
            ],
            'next_steps' => [],
        ]);

        // Step 3a: Send VIP Response
        $sendVip = WorkflowStep::create([
            'company_id' => $company->id,
            'workflow_id' => $workflow->id,
            'step_type' => 'action',
            'name' => 'Send VIP Response',
            'description' => 'Send AI response using VIP Concierge',
            'position' => ['x' => 300, 'y' => 400],
            'config' => [
                'action_type' => 'send_ai_response',
                'ai_agent_id' => $vipAgent->id,
            ],
            'next_steps' => [],
        ]);

        // Step 3b: Send General Response
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

        // Link steps
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
    }

    protected function createSimpleWorkflowSteps(Workflow $workflow, Company $company, AiAgent $generalAgent): void
    {
        // Single action step
        $sendResponse = WorkflowStep::create([
            'company_id' => $company->id,
            'workflow_id' => $workflow->id,
            'step_type' => 'action',
            'name' => 'Send AI Response',
            'description' => 'Send AI response using default personality',
            'position' => ['x' => 250, 'y' => 100],
            'config' => [
                'action_type' => 'send_ai_response',
                'ai_agent_id' => $generalAgent->id,
            ],
            'next_steps' => [],
        ]);

        $workflow->update([
            'workflow_definition' => [
                'entry_step_id' => $sendResponse->id,
                'steps' => [$sendResponse->id],
            ],
        ]);
    }
}
