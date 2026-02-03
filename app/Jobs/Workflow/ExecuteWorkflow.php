<?php

namespace App\Jobs\Workflow;

use App\Jobs\Workflow\ExecuteWorkflow;
use App\Models\WorkflowExecution;
use App\Services\Workflow\WorkflowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 300;

    protected int $executionId;

    public function __construct(int|WorkflowExecution $execution)
    {
        $this->executionId = $execution instanceof WorkflowExecution ? $execution->id : $execution;
    }

    public function handle(WorkflowService $workflowService): void
    {
        $execution = WorkflowExecution::find($this->executionId);

        if (!$execution) {
            Log::warning("Workflow execution not found", ['execution_id' => $this->executionId]);
            return;
        }

        if ($execution->isComplete()) {
            Log::info("Workflow execution already complete", ['execution_id' => $this->executionId]);
            return;
        }

        $workflowService->execute($execution);
    }

    public function failed(\Throwable $exception): void
    {
        $execution = WorkflowExecution::find($this->executionId);

        if ($execution) {
            $execution->fail($exception->getMessage());
        }

        Log::error("Workflow job failed", [
            'execution_id' => $this->executionId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
