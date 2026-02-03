<?php

namespace App\Jobs\Workflow;

use App\Services\Workflow\WorkflowScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessScheduledWorkflowRuns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function handle(WorkflowScheduleService $scheduleService): void
    {
        Log::info('Processing scheduled workflow runs');

        try {
            $scheduleService->processScheduledRuns();
            Log::info('Scheduled workflow runs processed successfully');
        } catch (\Throwable $e) {
            Log::error('Failed to process scheduled workflow runs', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
