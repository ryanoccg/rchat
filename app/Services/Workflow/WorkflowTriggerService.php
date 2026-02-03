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
     */
    public function onMessageReceived(Message $message): void
    {
        $conversation = $message->conversation;
        $customer = $conversation->customer;
        $company = $conversation->company;

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

        // FALLBACK: If no workflows were executed and AI handling is enabled,
        // use legacy direct AI response (for companies without workflows configured)
        if (!$workflowsExecuted && $conversation->is_ai_handling) {
            $this->triggerFallbackAiResponse($message, $conversation, $company);
        }
    }

    /**
     * Fallback AI response for companies without message_received workflows.
     * This maintains backward compatibility during the transition period.
     */
    protected function triggerFallbackAiResponse(Message $message, Conversation $conversation, Company $company): void
    {
        $aiConfig = AiConfiguration::where('company_id', $company->id)->first();

        if (!$aiConfig || !$aiConfig->auto_respond) {
            Log::info('WorkflowTriggerService: Fallback skipped - auto-respond disabled', [
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
            return;
        }

        Log::info('WorkflowTriggerService: Using fallback AI response (no workflows)', [
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

        // Dispatch job to execute workflow
        ExecuteWorkflow::dispatch($execution);

        return $execution;
    }
}
