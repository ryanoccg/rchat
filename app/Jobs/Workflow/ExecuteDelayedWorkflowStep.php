<?php

namespace App\Jobs\Workflow;

use App\Jobs\Workflow\ExecuteWorkflow;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStep;
use App\Services\Workflow\WorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteDelayedWorkflowStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 300;

    protected int $executionId;
    protected int $stepId;

    public function __construct(int $executionId, int $stepId)
    {
        $this->executionId = $executionId;
        $this->stepId = $stepId;
    }

    public function handle(WorkflowService $workflowService): void
    {
        $execution = WorkflowExecution::find($this->executionId);

        if (!$execution) {
            Log::warning("Workflow execution not found for delayed step", ['execution_id' => $this->executionId]);
            return;
        }

        $step = WorkflowStep::find($this->stepId);

        if (!$step) {
            Log::warning("Workflow step not found", ['step_id' => $this->stepId]);
            $execution->fail('Step not found');
            return;
        }

        // Resume and execute the step
        $execution->resume();

        try {
            $context = $execution->execution_context;
            $workflowService->executeStep($execution, $step, $context);
        } catch (\Throwable $e) {
            Log::error("Delayed workflow step execution failed", [
                'execution_id' => $this->executionId,
                'step_id' => $this->stepId,
                'error' => $e->getMessage(),
            ]);
            $execution->fail($e->getMessage());
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $execution = WorkflowExecution::find($this->executionId);

        if ($execution && !$execution->isComplete()) {
            $execution->fail($exception->getMessage());
        }

        Log::error("Delayed workflow step job failed", [
            'execution_id' => $this->executionId,
            'step_id' => $this->stepId,
            'error' => $exception->getMessage(),
        ]);
    }
}
