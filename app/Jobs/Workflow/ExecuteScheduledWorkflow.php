<?php

namespace App\Jobs\Workflow;

use App\Models\ScheduledWorkflowRun;
use App\Services\Workflow\WorkflowScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteScheduledWorkflow implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $backoff = [30, 60, 120];
    public $timeout = 300;

    protected int $scheduledRunId;

    public function __construct(int $scheduledRunId)
    {
        $this->scheduledRunId = $scheduledRunId;
    }

    public function handle(WorkflowScheduleService $scheduleService): void
    {
        $scheduledRun = ScheduledWorkflowRun::find($this->scheduledRunId);

        if (!$scheduledRun) {
            Log::warning("Scheduled workflow run not found", ['scheduled_run_id' => $this->scheduledRunId]);
            return;
        }

        if ($scheduledRun->status !== 'pending') {
            Log::info("Scheduled workflow run already processed", ['scheduled_run_id' => $this->scheduledRunId]);
            return;
        }

        $scheduleService->executeScheduledRun($scheduledRun);
    }

    public function failed(\Throwable $exception): void
    {
        $scheduledRun = ScheduledWorkflowRun::find($this->scheduledRunId);

        if ($scheduledRun && $scheduledRun->status === 'pending') {
            $scheduledRun->markAsFailed($exception->getMessage());
        }

        Log::error("Scheduled workflow job failed", [
            'scheduled_run_id' => $this->scheduledRunId,
            'error' => $exception->getMessage(),
        ]);
    }
}
