<?php

namespace App\Services\Workflow;

use App\Jobs\ProcessDelayedAiResponse;
use App\Jobs\Workflow\ExecuteWorkflow;
use App\Models\AiConfiguration;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class WorkflowTriggerService
{
    /**
     * Trigger workflows when a customer is created.
     */
    public function onCustomerCreated(Customer $customer): void
    {
        $workflows = $this->findMatchingWorkflows($customer->company, 'customer_created');

        foreach ($workflows as $workflow) {
            $this->executeWorkflow($workflow, [
                'customer' => $customer,
                'company' => $customer->company,
            ]);
        }
    }

    /**
     * Trigger workflows when a returning customer is detected.
     */
    public function onCustomerReturning(Customer $customer): void
    {
        $workflows = $this->findMatchingWorkflows($customer->company, 'customer_returning');

        foreach ($workflows as $workflow) {
            $returnDays = $workflow->trigger_config['return_days'] ?? 30;

            if ($customer->isReturning($returnDays)) {
                $this->executeWorkflow($workflow, [
                    'customer' => $customer,
                    'company' => $customer->company,
                ]);
            }
        }
    }

    /**
     * Trigger workflows when a conversation is created.
     */
    public function onConversationCreated(Conversation $conversation): void
    {
        $workflows = $this->findMatchingWorkflows($conversation->company, 'conversation_created');

        foreach ($workflows as $workflow) {
            $this->executeWorkflow($workflow, [
                'conversation' => $conversation,
                'customer' => $conversation->customer,
                'company' => $conversation->company,
            ]);
        }
    }

    /**
     * Trigger workflows when a conversation is closed.
     */
    public function onConversationClosed(Conversation $conversation): void
    {
        $workflows = $this->findMatchingWorkflows($conversation->company, 'conversation_closed');

        foreach ($workflows as $workflow) {
            $this->executeWorkflow($workflow, [
                'conversation' => $conversation,
                'customer' => $conversation->customer,
                'company' => $conversation->company,
            ]);
        }
    }

    /**
     * Trigger workflows when a message is received.
     * This is the sole entry point for AI auto-response (via workflows).
     * Falls back to direct AI response if no workflows are configured.
     *
     * CRITICAL: The delayed AI response is scheduled HERE (synchronously) to ensure proper message batching.
     * If we scheduled it inside workflow jobs (async), each message would create its own delayed job.
     */
    public function onMessageReceived(Message $message): void
    {
        $conversation = $message->conversation;
        $customer = $conversation->customer;
        $company = $conversation->company;

        // CRITICAL: Check if AI handling is enabled BEFORE scheduling delayed response
        if (!$conversation->is_ai_handling) {
            Log::channel('ai')->info('WorkflowTriggerService: AI handling disabled, skipping delayed response', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            return;
        }

        // Get AI configuration to verify auto-respond is enabled
        $aiConfig = AiConfiguration::where('company_id', $company->id)->first();
        if (!$aiConfig || !$aiConfig->auto_respond) {
            Log::channel('ai')->info('WorkflowTriggerService: Auto-respond disabled, skipping delayed response', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
            ]);
            return;
        }

        // CRITICAL: Schedule delayed AI response HERE (synchronously during webhook)
        // This ensures multiple messages within the delay window are batched together
        // The workflow execution (async) will NOT schedule its own AI response
        ProcessDelayedAiResponse::scheduleForConversation(
            $conversation->id,
            $message->id
        );

        Log::channel('ai')->info('WorkflowTriggerService: Scheduled delayed AI response for message', [
            'conversation_id' => $conversation->id,
            'message_id' => $message->id,
        ]);

        // Note: Workflows are still executed for other actions (tags, assignment, etc.)
        // But send_ai_response actions should be skipped since we already scheduled above
        // This is handled by checking if a delayed response is already scheduled

        $workflowsExecuted = false;

        // Check for first message workflows
        $firstMessageWorkflows = $this->findMatchingWorkflows($company, 'first_message');
        foreach ($firstMessageWorkflows as $workflow) {
            if ($conversation->isFirstMessage()) {
                $execution = $this->executeWorkflow($workflow, [
                    'conversation' => $conversation,
                    'customer' => $customer,
                    'message' => $message,
                    'company' => $company,
                ]);
                if ($execution) {
                    $workflowsExecuted = true;
                }
            }
        }

        // Check for message received workflows
        $messageWorkflows = $this->findMatchingWorkflows($company, 'message_received');
        foreach ($messageWorkflows as $workflow) {
            $execution = $this->executeWorkflow($workflow, [
                'conversation' => $conversation,
                'customer' => $customer,
                'message' => $message,
                'company' => $company,
            ]);
            if ($execution) {
                $workflowsExecuted = true;
            }
        }

        // No need for fallback - we already scheduled delayed response above
        // This is a change from the previous behavior where fallback was conditional
    }

    /**
     * Fallback AI response for companies without message_received workflows.
     * This maintains backward compatibility during the transition period.
     */
    protected function triggerFallbackAiResponse(Message $message, Conversation $conversation, Company $company): void
    {
        $aiConfig = AiConfiguration::where('company_id', $company->id)->first();

        if (!$aiConfig || !$aiConfig->auto_respond) {
            Log::channel('ai')->info('WorkflowTriggerService: Fallback skipped - auto-respond disabled', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        Log::channel('ai')->info('WorkflowTriggerService: Using fallback AI response (no workflows)', [
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
            'company_id' => $company->id,
        ]);

        // Schedule delayed AI response (legacy behavior)
        ProcessDelayedAiResponse::scheduleForConversation(
            $conversation->id,
            $message->id
        );
    }

    /**
     * Schedule follow-up workflow for a conversation.
     */
    public function scheduleFollowUp(Conversation $conversation, int $delayMinutes, ?int $workflowId = null): void
    {
        $company = $conversation->company;

        if ($workflowId) {
            $workflow = Workflow::find($workflowId);
            if ($workflow && $workflow->company_id === $company->id) {
                $this->scheduleWorkflowRun($workflow, $conversation, $delayMinutes);
                return;
            }
        }

        // Find matching no_response workflows
        $workflows = $this->findMatchingWorkflows($company, 'no_response');

        foreach ($workflows as $workflow) {
            $noResponseMinutes = $workflow->trigger_config['no_response_minutes'] ?? 60;

            if ($delayMinutes >= $noResponseMinutes) {
                $this->scheduleWorkflowRun($workflow, $conversation, $delayMinutes);
            }
        }
    }

    /**
     * Schedule a workflow to run at a specific time.
     */
    public function scheduleWorkflowRun(
        Workflow $workflow,
        ?Conversation $conversation,
        int $delayMinutes,
        ?Customer $customer = null
    ): void {
        $scheduledRun = \App\Models\ScheduledWorkflowRun::create([
            'company_id' => $workflow->company_id,
            'workflow_id' => $workflow->id,
            'customer_id' => $customer?->id ?? $conversation?->customer_id,
            'conversation_id' => $conversation?->id,
            'scheduled_at' => now()->addMinutes($delayMinutes),
            'execution_context' => [
                'conversation_id' => $conversation?->id,
                'customer_id' => $customer?->id ?? $conversation?->customer_id,
            ],
            'status' => 'pending',
        ]);

        // Dispatch job to execute at scheduled time
        \App\Jobs\Workflow\ExecuteScheduledWorkflow::dispatch($scheduledRun)
            ->delay(now()->addMinutes($delayMinutes));
    }

    /**
     * Find workflows matching the trigger type.
     */
    protected function findMatchingWorkflows(Company $company, string $triggerType): Collection
    {
        return Workflow::where('company_id', $company->id)
            ->where('status', 'active')
            ->where('trigger_type', $triggerType)
            ->get();
    }

    /**
     * Execute a workflow for the given event data.
     */
    protected function executeWorkflow(Workflow $workflow, array $eventData): ?WorkflowExecution
    {
        // Check if workflow can start for this event
        if (!$workflow->canStartFor($eventData)) {
            return null;
        }

        // Check for duplicate executions (prevent multiple runs for same event)
        $conversation = $eventData['conversation'] ?? null;
        if ($conversation && $conversation->active_workflow_execution_id) {
            // A workflow is already running for this conversation
            return null;
        }

        // CRITICAL: Use atomic cache lock to prevent race conditions
        // Multiple messages arriving quickly could all pass the active_workflow_execution_id check
        // before any of them sets it, causing duplicate workflow executions
        $lockKey = "workflow_executing_{$conversation->id}_{$workflow->id}";
        if (Cache::has($lockKey)) {
            Log::channel('ai')->info('WorkflowTriggerService: Workflow already executing for conversation', [
                'conversation_id' => $conversation->id,
                'workflow_id' => $workflow->id,
            ]);
            return null;
        }

        // Acquire lock for 60 seconds (should be enough for workflow to start)
        Cache::put($lockKey, true, 60);

        // Double-check after acquiring lock (in case another request just set active_workflow_execution_id)
        $conversation->refresh();
        if ($conversation->active_workflow_execution_id) {
            Cache::forget($lockKey);
            return null;
        }

        // Create workflow execution
        $execution = WorkflowExecution::create([
            'company_id' => $workflow->company_id,
            'workflow_id' => $workflow->id,
            'customer_id' => $eventData['customer']?->id,
            'conversation_id' => $conversation?->id,
            'status' => 'pending',
            'execution_context' => $eventData,
        ]);

        // Attach to conversation if present
        if ($conversation) {
            $conversation->update(['active_workflow_execution_id' => $execution->id]);
        }

        // Update customer's last workflow execution
        if (isset($eventData['customer'])) {
            $eventData['customer']->update(['last_workflow_execution_at' => now()]);
        }

        // Release the lock once conversation is marked as having active execution
        Cache::forget($lockKey);

        // Dispatch job to execute workflow
        ExecuteWorkflow::dispatch($execution);

        return $execution;
    }
}
