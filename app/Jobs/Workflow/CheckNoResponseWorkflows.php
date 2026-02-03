<?php

namespace App\Jobs\Workflow;

use App\Services\Workflow\WorkflowScheduleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckNoResponseWorkflows implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 600;

    public function handle(WorkflowScheduleService $scheduleService): void
    {
        Log::info('Checking for no-response workflow triggers');

        try {
            $scheduleService->checkNoResponseTriggers();
            Log::info('No-response workflow triggers checked successfully');
        } catch (\Throwable $e) {
            Log::error('Failed to check no-response workflows', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
