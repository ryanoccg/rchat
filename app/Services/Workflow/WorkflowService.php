<?php

namespace App\Services\Workflow;

use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStep;
use App\Models\WorkflowExecutionLog;
use Illuminate\Support\Facades\DB;
use Exception;

class WorkflowService
{
    protected WorkflowStepExecutor $stepExecutor;
    protected WorkflowActionService $actionService;

    public function __construct(
        WorkflowStepExecutor $stepExecutor,
        WorkflowActionService $actionService
    ) {
        $this->stepExecutor = $stepExecutor;
        $this->actionService = $actionService;
    }

    /**
     * Execute a workflow.
     */
    public function execute(WorkflowExecution $execution): void
    {
        try {
            $execution->start();

            $workflow = $execution->workflow;
            $context = $execution->execution_context;

            // Get the first step (trigger step)
            $firstStep = $workflow->steps()->where('step_type', 'trigger')->first();

            if (!$firstStep) {
                // No trigger step, find the first step by position
                $firstStep = $workflow->steps()->orderBy('id')->first();
            }

            if ($firstStep) {
                $this->executeStep($execution, $firstStep, $context);
            } else {
                // No steps defined, complete immediately
                $execution->complete();
            }
        } catch (Exception $e) {
            $execution->fail($e->getMessage());
            throw $e;
        }
    }

    /**
     * Execute a single workflow step.
     */
    public function executeStep(WorkflowExecution $execution, WorkflowStep $step, array $context): void
    {
        // Update current step
        $execution->update(['current_step_id' => $step->id]);

        // Create execution log
        $log = WorkflowExecutionLog::start($execution, $step, $context);

        try {
            // Execute based on step type
            $result = match ($step->step_type) {
                'trigger' => $this->stepExecutor->executeTrigger($step, $context),
                'action' => $this->stepExecutor->executeAction($step, $context, $execution),
                'condition' => $this->stepExecutor->executeCondition($step, $context),
                'delay' => $this->stepExecutor->executeDelay($step, $context, $execution),
                'parallel' => $this->stepExecutor->executeParallel($step, $context, $execution),
                'loop' => $this->stepExecutor->executeLoop($step, $context, $execution),
                'ai_response' => $this->stepExecutor->executeAIResponse($step, $context, $execution),
                'merge' => $this->stepExecutor->executeMerge($step, $context),
                default => ['status' => 'skipped'],
            };

            // Complete the log
            $log->complete($result);

            // Determine next steps
            $nextStepIds = $this->getNextStepIds($step, $result, $context);

            if (empty($nextStepIds)) {
                // No more steps, complete the workflow
                $execution->complete();
            } elseif (count($nextStepIds) === 1) {
                // Single next step, execute it
                $nextStep = WorkflowStep::find($nextStepIds[0]);
                if ($nextStep) {
                    // Merge result into context
                    $newContext = array_merge($context, $result['context'] ?? []);
                    $this->executeStep($execution, $nextStep, $newContext);
                } else {
                    $execution->complete();
                }
            } else {
                // Multiple next steps (parallel branches)
                $this->executeParallelBranches($execution, $nextStepIds, array_merge($context, $result['context'] ?? []));
            }
        } catch (Exception $e) {
            $log->fail($e->getMessage());
            $execution->fail($e->getMessage());
            throw $e;
        }
    }

    /**
     * Execute parallel branches.
     */
    protected function executeParallelBranches(WorkflowExecution $execution, array $stepIds, array $context): void
    {
        foreach ($stepIds as $stepId) {
            $step = WorkflowStep::find($stepId);
            if ($step) {
                // Create a child execution for each branch
                $childExecution = WorkflowExecution::create([
                    'company_id' => $execution->company_id,
                    'workflow_id' => $execution->workflow_id,
                    'customer_id' => $execution->customer_id,
                    'conversation_id' => $execution->conversation_id,
                    'status' => 'pending',
                    'execution_context' => $context,
                ]);

                \App\Jobs\Workflow\ExecuteWorkflow::dispatch($childExecution);
            }
        }

        $execution->complete();
    }

    /**
     * Get next step IDs based on step type and result.
     */
    protected function getNextStepIds(WorkflowStep $step, array $result, array $context): array
    {
        if ($step->step_type === 'condition') {
            $conditionResult = $result['condition_result'] ?? false;
            return $step->getNextSteps($conditionResult);
        }

        if ($step->step_type === 'delay' || $step->step_type === 'loop') {
            // Delay/loop steps handle their own scheduling
            return [];
        }

        return $step->getNextSteps();
    }

    /**
     * Resume a paused execution.
     */
    public function resumeExecution(WorkflowExecution $execution): void
    {
        if ($execution->status !== 'paused') {
            return;
        }

        $execution->resume();
        $this->execute($execution);
    }

    /**
     * Evaluate a condition for a workflow step.
     */
    public function evaluateCondition(WorkflowStep $step, array $context): bool
    {
        return $this->stepExecutor->evaluateCondition($step, $context);
    }

    /**
     * Test a workflow execution.
     */
    public function testWorkflow(Workflow $workflow, array $testData): array
    {
        $results = [
            'workflow' => $workflow->name,
            'test_data' => $testData,
            'steps' => [],
            'success' => true,
            'errors' => [],
        ];

        try {
            DB::beginTransaction();

            // Create test execution
            $execution = WorkflowExecution::create([
                'company_id' => $workflow->company_id,
                'workflow_id' => $workflow->id,
                'customer_id' => $testData['customer_id'] ?? null,
                'conversation_id' => $testData['conversation_id'] ?? null,
                'status' => 'running',
                'execution_context' => $testData,
            ]);

            // Execute first step
            $firstStep = $workflow->steps()->orderBy('id')->first();
            if ($firstStep) {
                $this->executeStep($execution, $firstStep, $testData);
            }

            $results['execution_id'] = $execution->id;
            $results['steps'] = $execution->logs()->get(['step_type', 'status', 'executed_at'])->toArray();

            // Rollback to not actually persist test data
            DB::rollBack();
        } catch (Exception $e) {
            DB::rollBack();
            $results['success'] = false;
            $results['errors'][] = $e->getMessage();
        }

        return $results;
    }
}
