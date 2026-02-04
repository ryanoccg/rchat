<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Workflow;
use App\Services\Workflow\WorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RunAutoFollowWorkflows extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workflows:run-auto-follow
                            {--company= : Specific company ID to process}
                            {--dry-run : Show what would be processed without actually executing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run auto-follow-up workflows for inactive conversations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $companyId = $this->option('company');
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('[DRY RUN] No actual changes will be made.');
        }

        // Find all active auto-follow workflows
        $workflowsQuery = Workflow::where('trigger_type', 'auto_follow_up')
            ->where('status', 'active');

        if ($companyId) {
            $workflowsQuery->where('company_id', $companyId);
        }

        $workflows = $workflowsQuery->get();

        if ($workflows->isEmpty()) {
            $this->info('No active auto-follow workflows found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$workflows->count()} active auto-follow workflow(s).");

        foreach ($workflows as $workflow) {
            $this->processWorkflow($workflow, $dryRun);
        }

        return Command::SUCCESS;
    }

    /**
     * Process a single auto-follow workflow.
     */
    protected function processWorkflow(Workflow $workflow, bool $dryRun): void
    {
        $config = $workflow->trigger_config ?? [];
        $inactiveDays = $config['inactive_days'] ?? 7;
        $maxFollowUps = $config['max_follow_ups'] ?? 3;
        $excludeStatuses = $config['exclude_statuses'] ?? ['closed'];

        $this->info("Processing workflow: {$workflow->name} (Company: {$workflow->company_id})");
        $this->info("  - Inactive days threshold: {$inactiveDays}");
        $this->info("  - Max follow-ups: {$maxFollowUps}");

        // Find inactive conversations for this company
        $cutoffDate = now()->subDays($inactiveDays);

        $conversations = Conversation::where('company_id', $workflow->company_id)
            ->where('is_ai_handling', true)
            ->whereNotIn('status', $excludeStatuses)
            ->where('last_message_at', '<', $cutoffDate)
            ->whereNull('active_workflow_execution_id')
            ->get();

        $this->info("  Found {$conversations->count()} inactive conversation(s).");

        $processed = 0;
        $skipped = 0;

        foreach ($conversations as $conversation) {
            // Check follow-up count
            $metadata = $conversation->workflow_metadata ?? [];
            $followUpCount = $metadata['auto_follow_up_count'] ?? 0;

            if ($followUpCount >= $maxFollowUps) {
                $this->line("    - Conversation {$conversation->id}: Skipped (max follow-ups reached: {$followUpCount}/{$maxFollowUps})");
                $skipped++;
                continue;
            }

            if ($dryRun) {
                $this->line("    - Conversation {$conversation->id}: Would trigger workflow (follow-up #{$followUpCount} + 1)");
                $processed++;
                continue;
            }

            // Trigger the workflow
            try {
                $workflowService = app(WorkflowService::class);

                // Check if workflow can start
                if (!$workflow->canStartFor([
                    'conversation' => $conversation,
                    'trigger_source' => 'auto_follow_up',
                ])) {
                    $this->line("    - Conversation {$conversation->id}: Workflow cannot start for this conversation");
                    $skipped++;
                    continue;
                }

                $execution = $workflowService->startWorkflow($workflow, [
                    'conversation' => $conversation,
                    'customer' => $conversation->customer,
                    'trigger_source' => 'auto_follow_up',
                ]);

                // Update follow-up count
                $metadata['auto_follow_up_count'] = $followUpCount + 1;
                $metadata['last_auto_follow_up_at'] = now()->toIso8601String();
                $conversation->update(['workflow_metadata' => $metadata]);

                $this->line("    - Conversation {$conversation->id}: Workflow started (execution: {$execution->id})");
                $processed++;

                Log::channel('workflow')->info('Auto-follow workflow triggered', [
                    'workflow_id' => $workflow->id,
                    'conversation_id' => $conversation->id,
                    'execution_id' => $execution->id,
                    'follow_up_count' => $followUpCount + 1,
                ]);
            } catch (\Exception $e) {
                $this->error("    - Conversation {$conversation->id}: Error - {$e->getMessage()}");
                Log::channel('workflow')->error('Auto-follow workflow error', [
                    'workflow_id' => $workflow->id,
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("  Processed: {$processed}, Skipped: {$skipped}");
    }
}
