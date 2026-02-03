<?php

namespace App\Services\Workflow;

use App\Jobs\Workflow\ExecuteWorkflow;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\ScheduledWorkflowRun;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class WorkflowScheduleService
{
    /**
     * Process all due scheduled workflow runs.
     */
    public function processScheduledRuns(): void
    {
        $dueRuns = ScheduledWorkflowRun::due()->get();

        foreach ($dueRuns as $run) {
            try {
                $this->executeScheduledRun($run);
            } catch (\Exception $e) {
                Log::error("Failed to execute scheduled workflow run: {$e->getMessage()}", [
                    'scheduled_run_id' => $run->id,
                ]);
                $run->markAsFailed($e->getMessage());
            }
        }
    }

    /**
     * Execute a scheduled workflow run.
     */
    public function executeScheduledRun(ScheduledWorkflowRun $run): void
    {
        $run->update(['status' => 'running']);

        // Create workflow execution
        $execution = WorkflowExecution::create([
            'company_id' => $run->company_id,
            'workflow_id' => $run->workflow_id,
            'customer_id' => $run->customer_id,
            'conversation_id' => $run->conversation_id,
            'status' => 'pending',
            'execution_context' => $run->execution_context,
        ]);

        // Attach to conversation if present
        if ($run->conversation_id) {
            $conversation = Conversation::find($run->conversation_id);
            if ($conversation && !$conversation->active_workflow_execution_id) {
                $conversation->update(['active_workflow_execution_id' => $execution->id]);
            }
        }

        // Mark scheduled run as completed
        $run->markAsExecuted();

        // Execute workflow
        ExecuteWorkflow::dispatch($execution);
    }

    /**
     * Check for conversations with no response and trigger workflows.
     */
    public function checkNoResponseTriggers(): void
    {
        $companies = Company::all();

        foreach ($companies as $company) {
            $this->checkCompanyNoResponseTriggers($company);
        }
    }

    /**
     * Check no-response triggers for a specific company.
     */
    protected function checkCompanyNoResponseTriggers(Company $company): void
    {
        // Get all active no-response workflows for the company
        $workflows = Workflow::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('trigger_type', 'no_response')
            ->get();

        if ($workflows->isEmpty()) {
            return;
        }

        foreach ($workflows as $workflow) {
            $noResponseMinutes = $workflow->trigger_config['no_response_minutes'] ?? 60;
            $cutoffTime = now()->subMinutes($noResponseMinutes);

            // Find idle conversations that haven't triggered this workflow recently
            $conversations = Conversation::where('company_id', $company->id)
                ->where('status', 'open')
                ->where('last_message_at', '<', $cutoffTime)
                ->whereDoesntHave('scheduledWorkflowRuns', function ($query) use ($workflow, $cutoffTime) {
                    $query->where('workflow_id', $workflow->id)
                        ->where('created_at', '>', $cutoffTime);
                })
                ->get();

            foreach ($conversations as $conversation) {
                // Check if last message was from customer
                $lastMessage = $conversation->messages()->latest()->first();
                if ($lastMessage && $lastMessage->sender_type === 'customer') {
                    // Schedule follow-up workflow
                    $triggerService = new WorkflowTriggerService();
                    $triggerService->scheduleWorkflowRun($workflow, $conversation, 0);
                }
            }
        }
    }

    /**
     * Schedule daily workflow runs.
     */
    public function scheduleDailyRuns(Workflow $workflow): void
    {
        if ($workflow->trigger_type !== 'scheduled') {
            return;
        }

        $schedule = $workflow->trigger_config['schedule'] ?? [];

        if (($schedule['type'] ?? '') === 'daily') {
            // Schedule for next day
            $this->createScheduledRun($workflow, now()->addDay(), [
                'scheduled_type' => 'daily',
            ]);
        }
    }

    /**
     * Create a scheduled workflow run.
     */
    protected function createScheduledRun(Workflow $workflow, Carbon $scheduledAt, array $context = []): ScheduledWorkflowRun
    {
        return ScheduledWorkflowRun::create([
            'company_id' => $workflow->company_id,
            'workflow_id' => $workflow->id,
            'scheduled_at' => $scheduledAt,
            'execution_context' => $context,
            'status' => 'pending',
        ]);
    }

    /**
     * Schedule a delayed workflow step execution.
     */
    public function scheduleStep(WorkflowExecution $execution, int $stepId, Carbon $when): void
    {
        \App\Jobs\Workflow\ExecuteDelayedWorkflowStep::dispatch($execution->id, $stepId)
            ->delay($when);
    }
}
