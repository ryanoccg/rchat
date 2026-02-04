<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\WorkflowResource;
use App\Http\Resources\WorkflowStepResource;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Models\WorkflowExecution;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Services\ActivityLogService;
use App\Services\Workflow\WorkflowService;
use App\Services\Workflow\WorkflowTriggerService;

class WorkflowController extends Controller
{
    protected WorkflowService $workflowService;
    protected WorkflowTriggerService $triggerService;

    public function __construct(
        WorkflowService $workflowService,
        WorkflowTriggerService $triggerService
    ) {
        $this->workflowService = $workflowService;
        $this->triggerService = $triggerService;
    }

    /**
     * List all workflows for the current company.
     */
    public function index(Request $request)
    {
        $query = Workflow::where('company_id', $request->get('company_id'));

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by trigger type
        if ($request->has('trigger_type')) {
            $query->where('trigger_type', $request->trigger_type);
        }

        // Search by name
        if ($request->has('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // With relationships and counts
        $query->with(['steps' => function ($q) {
            $q->orderBy('id');
        }])
            ->withCount([
                'executions as total_executions',
                'executions as successful_executions' => function ($q) {
                    $q->where('status', 'completed');
                },
                'executions as failed_executions' => function ($q) {
                    $q->where('status', 'failed');
                },
                'steps as step_count',
            ]);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $workflows = $query->orderByDesc('created_at')->paginate($perPage);

        // Map counts to stats object
        $workflows->getCollection()->transform(function ($workflow) {
            $workflow->stats = [
                'total_executions' => $workflow->total_executions,
                'successful_executions' => $workflow->successful_executions,
                'failed_executions' => $workflow->failed_executions,
                'step_count' => $workflow->step_count,
            ];
            return $workflow;
        });

        return response()->json([
            'data' => WorkflowResource::collection($workflows->getCollection()),
            'meta' => [
                'current_page' => $workflows->currentPage(),
                'per_page' => $workflows->perPage(),
                'total' => $workflows->total(),
                'last_page' => $workflows->lastPage(),
            ],
        ]);
    }

    /**
     * Get a single workflow with details.
     */
    public function show(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->with(['steps' => function ($q) {
                $q->orderBy('id');
            }])
            ->findOrFail($id);

        // Add execution stats (single query for show is acceptable)
        $workflow->loadCount([
            'executions as total_executions',
            'executions as successful_executions' => fn ($q) => $q->where('status', 'completed'),
            'executions as failed_executions' => fn ($q) => $q->where('status', 'failed'),
        ]);

        $workflow->stats = [
            'total_executions' => $workflow->total_executions,
            'successful_executions' => $workflow->successful_executions,
            'failed_executions' => $workflow->failed_executions,
            'recent_executions' => $workflow->executions()
                ->orderByDesc('created_at')
                ->limit(10)
                ->get(['id', 'status', 'created_at', 'completed_at']),
        ];

        return response()->json([
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    /**
     * Create a new workflow.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'in:draft,inactive,active',
            'trigger_type' => 'required|in:customer_created,customer_returning,first_message,conversation_created,conversation_closed,message_received,no_response,scheduled,auto_follow_up',
            'trigger_config' => 'nullable|array',
            'execution_mode' => 'in:sequential,parallel,mixed',
            'workflow_definition' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $workflow = Workflow::create([
            'company_id' => $request->get('company_id'),
            'name' => $request->name,
            'description' => $request->description,
            'status' => $request->status ?? 'draft',
            'trigger_type' => $request->trigger_type,
            'trigger_config' => $request->trigger_config ?? [],
            'execution_mode' => $request->execution_mode ?? 'sequential',
            'workflow_definition' => $request->workflow_definition ?? [],
        ]);

        ActivityLogService::log(
            'workflow_created',
            "Created workflow: {$workflow->name}",
            $workflow
        );

        return response()->json([
            'message' => 'Workflow created successfully',
            'workflow' => new WorkflowResource($workflow),
        ], 201);
    }

    /**
     * Update a workflow.
     */
    public function update(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'status' => 'in:draft,inactive,active',
            'trigger_type' => 'sometimes|required|in:customer_created,customer_returning,first_message,conversation_created,conversation_closed,message_received,no_response,scheduled,auto_follow_up',
            'trigger_config' => 'nullable|array',
            'execution_mode' => 'in:sequential,parallel,mixed',
            'workflow_definition' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $workflow->update([
            'name' => $request->name ?? $workflow->name,
            'description' => $request->description ?? $workflow->description,
            'status' => $request->status ?? $workflow->status,
            'trigger_type' => $request->trigger_type ?? $workflow->trigger_type,
            'trigger_config' => $request->trigger_config ?? $workflow->trigger_config,
            'execution_mode' => $request->execution_mode ?? $workflow->execution_mode,
            'workflow_definition' => $request->workflow_definition ?? $workflow->workflow_definition,
        ]);

        ActivityLogService::log(
            'workflow_updated',
            "Updated workflow: {$workflow->name}",
            $workflow
        );

        return response()->json([
            'message' => 'Workflow updated successfully',
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    /**
     * Delete a workflow.
     */
    public function destroy(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        $workflowName = $workflow->name;
        $workflowId = $workflow->id;

        // Soft delete
        $workflow->delete();

        ActivityLogService::log(
            'workflow_deleted',
            "Deleted workflow: {$workflowName}",
            null,
            ['workflow_id' => $workflowId]
        );

        return response()->json([
            'message' => 'Workflow deleted successfully',
        ]);
    }

    /**
     * Activate a workflow.
     */
    public function activate(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        $workflow->activate();

        ActivityLogService::log(
            'workflow_activated',
            "Activated workflow: {$workflow->name}",
            $workflow
        );

        return response()->json([
            'message' => 'Workflow activated successfully',
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    /**
     * Deactivate a workflow.
     */
    public function deactivate(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        $workflow->deactivate();

        ActivityLogService::log(
            'workflow_deactivated',
            "Deactivated workflow: {$workflow->name}",
            $workflow
        );

        return response()->json([
            'message' => 'Workflow deactivated successfully',
            'workflow' => new WorkflowResource($workflow),
        ]);
    }

    /**
     * Duplicate a workflow.
     */
    public function duplicate(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->with('steps')
            ->findOrFail($id);

        $newWorkflow = Workflow::create([
            'company_id' => $workflow->company_id,
            'name' => $workflow->name . ' (Copy)',
            'description' => $workflow->description,
            'status' => 'draft',
            'trigger_type' => $workflow->trigger_type,
            'trigger_config' => $workflow->trigger_config,
            'execution_mode' => $workflow->execution_mode,
            'workflow_definition' => $workflow->workflow_definition,
        ]);

        // Duplicate steps
        foreach ($workflow->steps as $step) {
            WorkflowStep::create([
                'company_id' => $step->company_id,
                'workflow_id' => $newWorkflow->id,
                'step_type' => $step->step_type,
                'name' => $step->name,
                'description' => $step->description,
                'position' => $step->position,
                'config' => $step->config,
                'next_steps' => $step->next_steps,
            ]);
        }

        ActivityLogService::log(
            'workflow_duplicated',
            "Duplicated workflow: {$workflow->name}",
            $newWorkflow,
            ['original_workflow_id' => $workflow->id]
        );

        return response()->json([
            'message' => 'Workflow duplicated successfully',
            'workflow' => new WorkflowResource($newWorkflow->load('steps')),
        ], 201);
    }

    /**
     * Test a workflow execution.
     */
    public function test(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'customer_id' => 'nullable|exists:customers,id',
            'conversation_id' => 'nullable|exists:conversations,id',
            'test_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $testData = array_merge($request->test_data ?? [], [
            'customer_id' => $request->customer_id,
            'conversation_id' => $request->conversation_id,
        ]);

        try {
            $result = $this->workflowService->testWorkflow($workflow, $testData);

            ActivityLogService::log(
                'workflow_tested',
                "Tested workflow: {$workflow->name}",
                $workflow,
                ['success' => $result['success']]
            );

            return response()->json([
                'message' => 'Workflow test completed',
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Workflow test failed',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Add a step to a workflow.
     */
    public function addStep(Request $request, $id)
    {
        $workflow = Workflow::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        $validator = Validator::make($request->all(), [
            'step_type' => 'required|in:trigger,action,condition,delay,parallel,loop,ai_response,merge',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'position' => 'nullable|array',
            'position.x' => 'nullable|numeric',
            'position.y' => 'nullable|numeric',
            'config' => 'nullable|array',
            'next_steps' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $step = WorkflowStep::create([
            'company_id' => $workflow->company_id,
            'workflow_id' => $workflow->id,
            'step_type' => $request->step_type,
            'name' => $request->name,
            'description' => $request->description,
            'position' => $request->position ?? ['x' => 0, 'y' => 0],
            'config' => $request->config ?? [],
            'next_steps' => $request->next_steps ?? [],
        ]);

        ActivityLogService::log(
            'workflow_step_created',
            "Created step: {$step->name} in workflow: {$workflow->name}",
            $step,
            ['workflow_id' => $workflow->id]
        );

        return response()->json([
            'message' => 'Step added successfully',
            'step' => new WorkflowStepResource($step),
        ], 201);
    }

    /**
     * Update a workflow step.
     */
    public function updateStep(Request $request, $stepId)
    {
        $step = WorkflowStep::findOrFail($stepId);

        // Verify company access
        if ($step->company_id != $request->get('company_id')) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'position' => 'nullable|array',
            'position.x' => 'nullable|numeric',
            'position.y' => 'nullable|numeric',
            'config' => 'nullable|array',
            'next_steps' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $step->update([
            'name' => $request->name ?? $step->name,
            'description' => $request->description ?? $step->description,
            'position' => $request->position ?? $step->position,
            'config' => $request->config ?? $step->config,
            'next_steps' => $request->next_steps ?? $step->next_steps,
        ]);

        ActivityLogService::log(
            'workflow_step_updated',
            "Updated step: {$step->name}",
            $step,
            ['workflow_id' => $step->workflow_id]
        );

        return response()->json([
            'message' => 'Step updated successfully',
            'step' => new WorkflowStepResource($step),
        ]);
    }

    /**
     * Delete a workflow step.
     */
    public function deleteStep(Request $request, $stepId)
    {
        $step = WorkflowStep::findOrFail($stepId);

        // Verify company access
        if ($step->company_id != $request->get('company_id')) {
            return response()->json([
                'message' => 'Unauthorized',
            ], 403);
        }

        $workflowId = $step->workflow_id;
        $stepId = $step->id;
        $stepType = $step->step_type;

        $step->delete();

        ActivityLogService::log(
            'workflow_step_deleted',
            "Deleted workflow step",
            null,
            ['workflow_id' => $workflowId, 'step_id' => $stepId, 'step_type' => $stepType]
        );

        return response()->json([
            'message' => 'Step deleted successfully',
        ]);
    }

    /**
     * Get workflow execution history.
     */
    public function executions(Request $request)
    {
        $query = WorkflowExecution::where('company_id', $request->get('company_id'))
            ->with(['workflow', 'customer', 'conversation', 'currentStep']);

        // Filter by workflow
        if ($request->has('workflow_id')) {
            $query->where('workflow_id', $request->workflow_id);
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by customer
        if ($request->has('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        // Filter by conversation
        if ($request->has('conversation_id')) {
            $query->where('conversation_id', $request->conversation_id);
        }

        // Date range
        if ($request->has('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->has('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $perPage = $request->get('per_page', 15);
        $executions = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'data' => $executions->items(),
            'meta' => [
                'current_page' => $executions->currentPage(),
                'per_page' => $executions->perPage(),
                'total' => $executions->total(),
                'last_page' => $executions->lastPage(),
            ],
        ]);
    }

    /**
     * Get execution details with logs.
     */
    public function executionDetails(Request $request, $id)
    {
        $execution = WorkflowExecution::where('company_id', $request->get('company_id'))
            ->with(['workflow', 'customer', 'conversation', 'logs' => function ($q) {
                $q->orderBy('executed_at');
            }])
            ->findOrFail($id);

        return response()->json([
            'execution' => $execution,
        ]);
    }

    /**
     * Cancel a workflow execution.
     */
    public function cancelExecution(Request $request, $id)
    {
        $execution = WorkflowExecution::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        if ($execution->isComplete()) {
            return response()->json([
                'message' => 'Cannot cancel completed execution',
            ], 422);
        }

        $execution->cancel();

        ActivityLogService::log(
            'workflow_execution_cancelled',
            "Cancelled workflow execution",
            null,
            ['execution_id' => $execution->id, 'workflow_id' => $execution->workflow_id]
        );

        return response()->json([
            'message' => 'Execution cancelled successfully',
        ]);
    }

    /**
     * Retry a failed workflow execution.
     */
    public function retryExecution(Request $request, $id)
    {
        $execution = WorkflowExecution::where('company_id', $request->get('company_id'))
            ->findOrFail($id);

        if ($execution->status !== 'failed') {
            return response()->json([
                'message' => 'Can only retry failed executions',
            ], 422);
        }

        // Create new execution based on the failed one
        $newExecution = WorkflowExecution::create([
            'company_id' => $execution->company_id,
            'workflow_id' => $execution->workflow_id,
            'customer_id' => $execution->customer_id,
            'conversation_id' => $execution->conversation_id,
            'status' => 'pending',
            'execution_context' => $execution->execution_context,
        ]);

        // Dispatch execution
        \App\Jobs\Workflow\ExecuteWorkflow::dispatch($newExecution);

        ActivityLogService::log(
            'workflow_execution_retried',
            "Retried workflow execution",
            null,
            ['original_execution_id' => $execution->id, 'new_execution_id' => $newExecution->id]
        );

        return response()->json([
            'message' => 'Execution retry started',
            'execution' => $newExecution,
        ], 201);
    }

    /**
     * Get workflow statistics.
     */
    public function statistics(Request $request)
    {
        $companyId = $request->get('company_id');

        $stats = [
            'total_workflows' => Workflow::where('company_id', $companyId)->count(),
            'active_workflows' => Workflow::where('company_id', $companyId)->where('status', 'active')->count(),
            'draft_workflows' => Workflow::where('company_id', $companyId)->where('status', 'draft')->count(),
            'total_executions' => WorkflowExecution::where('company_id', $companyId)->count(),
            'successful_executions' => WorkflowExecution::where('company_id', $companyId)->where('status', 'completed')->count(),
            'failed_executions' => WorkflowExecution::where('company_id', $companyId)->where('status', 'failed')->count(),
            'running_executions' => WorkflowExecution::where('company_id', $companyId)->where('status', 'running')->count(),
        ];

        // Executions by trigger type
        $triggerBreakdown = Workflow::where('company_id', $companyId)
            ->selectRaw('trigger_type, COUNT(*) as count')
            ->groupBy('trigger_type')
            ->pluck('count', 'trigger_type')
            ->toArray();

        $stats['by_trigger_type'] = $triggerBreakdown;

        return response()->json([
            'statistics' => $stats,
        ]);
    }
}
