<?php

namespace App\Services\Workflow;

use App\Models\Conversation;
use App\Models\Customer;
use App\Models\WorkflowExecution;
use App\Models\WorkflowStep;
use App\Jobs\Workflow\ExecuteDelayedWorkflowStep;
use Illuminate\Support\Facades\Log;

class WorkflowStepExecutor
{
    protected WorkflowActionService $actionService;

    public function __construct(WorkflowActionService $actionService)
    {
        $this->actionService = $actionService;
    }

    /**
     * Execute a trigger step.
     */
    public function executeTrigger(WorkflowStep $step, array $context): array
    {
        // Trigger steps are just entry points, return success
        return [
            'status' => 'success',
            'message' => 'Trigger activated',
            'context' => ['triggered_at' => now()->toIso8601String()],
        ];
    }

    /**
     * Execute an action step.
     */
    public function executeAction(WorkflowStep $step, array $context, WorkflowExecution $execution): array
    {
        $actionType = $step->config['action_type'] ?? null;

        try {
            $result = $this->actionService->execute($actionType, $step->config, $context, $execution);

            return [
                'status' => 'success',
                'action_type' => $actionType,
                'result' => $result,
                'context' => $result['context'] ?? [],
            ];
        } catch (\Exception $e) {
            Log::error("Workflow action failed: {$e->getMessage()}", [
                'step_id' => $step->id,
                'action_type' => $actionType,
                'execution_id' => $execution->id,
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute a condition step.
     */
    public function executeCondition(WorkflowStep $step, array $context): array
    {
        $result = $this->evaluateCondition($step, $context);

        return [
            'status' => 'success',
            'condition_result' => $result,
            'condition_type' => $step->config['condition_type'] ?? 'unknown',
            'context' => ['condition_met' => $result],
        ];
    }

    /**
     * Execute a delay step.
     */
    public function executeDelay(WorkflowStep $step, array $context, WorkflowExecution $execution): array
    {
        $delayMinutes = $step->config['delay_minutes'] ?? 0;

        if ($delayMinutes <= 0) {
            return [
                'status' => 'success',
                'context' => [],
            ];
        }

        // Schedule the next step after delay
        $nextStepIds = $step->getNextSteps();
        if (!empty($nextStepIds)) {
            // Pause execution and schedule resume
            $execution->pause();

            ExecuteDelayedWorkflowStep::dispatch($execution->id, $nextStepIds[0])
                ->delay(now()->addMinutes($delayMinutes));
        }

        return [
            'status' => 'delayed',
            'delay_minutes' => $delayMinutes,
            'context' => ['scheduled_resume' => now()->addMinutes($delayMinutes)->toIso8601String()],
        ];
    }

    /**
     * Execute parallel steps.
     */
    public function executeParallel(WorkflowStep $step, array $context, WorkflowExecution $execution): array
    {
        $branchStepIds = $step->config['branches'] ?? [];
        $results = [];

        foreach ($branchStepIds as $stepId) {
            $childExecution = WorkflowExecution::create([
                'company_id' => $execution->company_id,
                'workflow_id' => $execution->workflow_id,
                'customer_id' => $execution->customer_id,
                'conversation_id' => $execution->conversation_id,
                'status' => 'pending',
                'execution_context' => $context,
            ]);

            \App\Jobs\Workflow\ExecuteWorkflow::dispatch($childExecution);

            $results[] = ['execution_id' => $childExecution->id];
        }

        return [
            'status' => 'success',
            'parallel_branches' => count($branchStepIds),
            'results' => $results,
            'context' => [],
        ];
    }

    /**
     * Execute a loop step.
     */
    public function executeLoop(WorkflowStep $step, array $context, WorkflowExecution $execution): array
    {
        $loopType = $step->config['loop_type'] ?? 'count';
        $iterations = $step->config['iterations'] ?? 1;
        $loopStepId = $step->config['loop_step_id'] ?? null;

        if (!$loopStepId) {
            return [
                'status' => 'error',
                'message' => 'Loop step ID not configured',
            ];
        }

        $results = [];

        for ($i = 0; $i < $iterations; $i++) {
            $loopContext = array_merge($context, ['loop_iteration' => $i + 1]);

            $childExecution = WorkflowExecution::create([
                'company_id' => $execution->company_id,
                'workflow_id' => $execution->workflow_id,
                'customer_id' => $execution->customer_id,
                'conversation_id' => $execution->conversation_id,
                'status' => 'pending',
                'execution_context' => $loopContext,
            ]);

            \App\Jobs\Workflow\ExecuteWorkflow::dispatch($childExecution);

            $results[] = ['iteration' => $i + 1, 'execution_id' => $childExecution->id];
        }

        return [
            'status' => 'success',
            'iterations' => $iterations,
            'results' => $results,
            'context' => [],
        ];
    }

    /**
     * Execute an AI response step.
     */
    public function executeAIResponse(WorkflowStep $step, array $context, WorkflowExecution $execution): array
    {
        $systemPrompt = $step->config['system_prompt'] ?? null;
        $promptTemplate = $step->config['prompt_template'] ?? null;

        try {
            $result = $this->actionService->executeAIResponse($systemPrompt, $promptTemplate, $context, $execution);

            return [
                'status' => 'success',
                'ai_response' => $result,
                'context' => ['ai_generated' => true],
            ];
        } catch (\Exception $e) {
            Log::error("Workflow AI response failed: {$e->getMessage()}", [
                'step_id' => $step->id,
                'execution_id' => $execution->id,
            ]);

            return [
                'status' => 'error',
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Execute a merge step (joins parallel branches).
     */
    public function executeMerge(WorkflowStep $step, array $context): array
    {
        // Merge step just passes through
        return [
            'status' => 'success',
            'context' => ['merged' => true],
        ];
    }

    /**
     * Evaluate a condition based on config.
     */
    public function evaluateCondition(WorkflowStep $step, array $context): bool
    {
        $conditionType = $step->config['condition_type'] ?? 'unknown';
        $field = $step->config['field'] ?? null;
        $operator = $step->config['operator'] ?? 'equals';
        $value = $step->config['value'] ?? null;

        return match ($conditionType) {
            'customer_attribute' => $this->evaluateCustomerAttribute($context, $field, $operator, $value),
            'conversation_attribute' => $this->evaluateConversationAttribute($context, $field, $operator, $value),
            'message_content' => $this->evaluateMessageContent($context, $operator, $value),
            'time_of_day' => $this->evaluateTimeOfDay($step->config),
            'day_of_week' => $this->evaluateDayOfWeek($step->config),
            'ai_condition' => $this->evaluateAICondition($context, $step->config),
            'intent_value' => $this->evaluateIntentValue($context, $step->config),
            default => false,
        };
    }

    /**
     * Evaluate customer attribute condition.
     */
    protected function evaluateCustomerAttribute(array $context, ?string $field, string $operator, mixed $value): bool
    {
        $customer = $context['customer'] ?? null;

        if (!$customer || !$field) {
            return false;
        }

        // Resolve customer to Eloquent model if it's an array
        $customerModel = $customer instanceof \App\Models\Customer
            ? $customer
            : \App\Models\Customer::find(is_array($customer) ? ($customer['id'] ?? null) : $customer);

        if (!$customerModel) {
            return false;
        }

        // Support computed customer fields
        $actualValue = match ($field) {
            'total_message_count' => \App\Models\Message::whereIn(
                'conversation_id',
                $customerModel->conversations()->pluck('id')
            )->where('is_from_customer', true)->count(),
            'customer_type' => $this->determineCustomerType($customerModel),
            'conversation_count' => $customerModel->conversations()->count(),
            default => is_array($customer) ? ($customer[$field] ?? null) : ($customer->$field ?? null),
        };

        return $this->compareValues($actualValue, $operator, $value);
    }

    /**
     * Determine customer type based on interaction history.
     */
    protected function determineCustomerType(\App\Models\Customer $customer): string
    {
        $totalMessages = \App\Models\Message::whereIn(
            'conversation_id',
            $customer->conversations()->pluck('id')
        )->where('is_from_customer', true)->count();

        if ($totalMessages <= 2) {
            return 'new';
        } elseif ($totalMessages <= 10) {
            return 'returning';
        }

        return 'vip';
    }

    /**
     * Evaluate conversation attribute condition.
     */
    protected function evaluateConversationAttribute(array $context, ?string $field, string $operator, mixed $value): bool
    {
        $conversation = $context['conversation'] ?? null;

        if (!$conversation || !$field) {
            return false;
        }

        $actualValue = $conversation->$field ?? null;

        return $this->compareValues($actualValue, $operator, $value);
    }

    /**
     * Evaluate message content condition.
     */
    protected function evaluateMessageContent(array $context, string $operator, mixed $value): bool
    {
        $message = $context['message'] ?? null;

        if (!$message) {
            return false;
        }

        $content = $message['content'] ?? $message->content ?? '';

        if ($operator === 'contains') {
            $keywords = is_array($value) ? $value : [$value];
            foreach ($keywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    return true;
                }
            }
            return false;
        }

        if ($operator === 'matches_regex') {
            return preg_match($value, $content) === 1;
        }

        if ($operator === 'equals') {
            return strtolower($content) === strtolower($value);
        }

        return false;
    }

    /**
     * Evaluate time of day condition.
     */
    protected function evaluateTimeOfDay(array $config): bool
    {
        $startTime = $config['start_time'] ?? '00:00';
        $endTime = $config['end_time'] ?? '23:59';
        $currentTime = now()->format('H:i');

        return $currentTime >= $startTime && $currentTime <= $endTime;
    }

    /**
     * Evaluate day of week condition.
     */
    protected function evaluateDayOfWeek(array $config): bool
    {
        $allowedDays = $config['days'] ?? [];
        $currentDay = now()->dayOfWeek; // 0 = Sunday, 6 = Saturday

        return in_array($currentDay, $allowedDays);
    }

    /**
     * Evaluate AI-based condition.
     * Supports both boolean return (default) and structured result (return_result=true).
     *
     * @return bool|array Returns boolean for normal conditions, array for structured output
     */
    protected function evaluateAICondition(array $context, array $config): bool|array
    {
        $aiPrompt = $config['ai_prompt'] ?? $config['prompt'] ?? null;
        $returnResult = $config['return_result'] ?? false;

        if (!$aiPrompt) {
            return $returnResult ? [] : false;
        }

        // Use AI to evaluate condition
        $conversation = $context['conversation'] ?? null;
        $message = $context['message'] ?? null;

        if (!$conversation) {
            return $returnResult ? [] : false;
        }

        // If conversation is an array (from serialized context), reload it from database
        if (is_array($conversation)) {
            $conversationId = $context['conversation_id'] ?? null;
            if ($conversationId) {
                $conversation = \App\Models\Conversation::find($conversationId);
            }
            if (!$conversation) {
                return $returnResult ? [] : false;
            }
        }

        // Resolve message to model if needed
        if ($message && is_array($message)) {
            $message = \App\Models\Message::find($message['id'] ?? null);
        }

        try {
            $aiService = new \App\Services\AI\AiService($conversation->company);

            // Build prompt for AI evaluation
            $evaluationPrompt = $aiPrompt . "\n\n";
            $evaluationPrompt .= "Recent conversation:\n";

            $messages = $conversation->messages()->latest()->limit(5)->get();
            foreach ($messages as $msg) {
                $evaluationPrompt .= "{$msg->sender_type}: {$msg->content}\n";
            }

            // If structured output is requested, parse JSON response
            if ($returnResult) {
                $evaluationPrompt .= "\nPlease respond with valid JSON only.";

                $response = $aiService->respondToMessage($conversation, $evaluationPrompt);

                $result = $this->parseJsonResponse($response->getContent());

                // Store intent classification on message if present
                if ($message && isset($result['intent'])) {
                    $this->storeIntentOnMessage($message, $result);
                }

                return $result;
            }

            // Default behavior: boolean response
            $evaluationPrompt .= "\nPlease respond with only 'true' or 'false'.";

            $response = $aiService->respondToMessage($conversation, $evaluationPrompt);

            return strtolower(trim($response->getContent())) === 'true';
        } catch (\Exception $e) {
            Log::channel('ai')->error("AI condition evaluation failed: {$e->getMessage()}", [
                'conversation_id' => $conversation->id ?? null,
                'config' => $config,
            ]);
            return $returnResult ? [] : false;
        }
    }

    /**
     * Evaluate intent value condition.
     * Checks if the message's classified intent matches the expected intent.
     */
    protected function evaluateIntentValue(array $context, array $config): bool
    {
        $message = $context['message'] ?? null;
        $expectedIntent = $config['intent'] ?? null;

        if (!$message || !$expectedIntent) {
            return false;
        }

        // Get intent from message (handle both object and array)
        $intent = null;
        if (is_object($message)) {
            $intent = $message->intent ?? null;
        } elseif (is_array($message)) {
            $intent = $message['intent'] ?? null;
            // If intent is not in array, try loading the message
            if (!$intent && isset($message['id'])) {
                $messageModel = \App\Models\Message::find($message['id']);
                if ($messageModel) {
                    $intent = $messageModel->intent;
                }
            }
        }

        // Check if intent matches
        if (is_array($expectedIntent)) {
            return in_array($intent, $expectedIntent);
        }

        return $intent === $expectedIntent;
    }

    /**
     * Store intent classification result on message.
     */
    protected function storeIntentOnMessage(\App\Models\Message $message, array $result): void
    {
        try {
            $confidence = $result['confidence'] ?? $result['confidence_score'] ?? null;

            $message->update([
                'intent' => $result['intent'] ?? null,
                'intent_confidence' => is_numeric($confidence) ? (float) $confidence : null,
                'intent_classified_at' => now(),
            ]);

            Log::channel('ai')->info('Intent stored on message', [
                'message_id' => $message->id,
                'intent' => $result['intent'] ?? null,
                'confidence' => $confidence,
            ]);
        } catch (\Throwable $e) {
            Log::channel('ai')->warning('Failed to store intent on message', [
                'message_id' => $message->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse JSON response from AI, handling various formats.
     */
    protected function parseJsonResponse(string $content): array
    {
        // Try to extract JSON from content
        $jsonMatch = null;
        if (preg_match('/\{[^}]*\}/', $content, $jsonMatch)) {
            $json = json_decode($jsonMatch[0], true);
            if (is_array($json)) {
                return $json;
            }
        }

        // Try parsing entire content as JSON
        $json = json_decode($content, true);
        if (is_array($json)) {
            return $json;
        }

        // Fallback: try to extract intent from text
        $lowerContent = strtolower($content);
        foreach (\App\Models\Message::allIntents() as $intent) {
            if (str_contains($lowerContent, $intent)) {
                return ['intent' => $intent, 'confidence' => 0.7];
            }
        }

        return [];
    }

    /**
     * Compare two values using the specified operator.
     */
    protected function compareValues(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'contains' => is_array($actual) ? in_array($expected, $actual) : str_contains($actual ?? '', $expected),
            'not_contains' => is_array($actual) ? !in_array($expected, $actual) : !str_contains($actual ?? '', $expected),
            'greater_than' => $actual > $expected,
            'less_than' => $actual < $expected,
            'greater_equal' => $actual >= $expected,
            'less_equal' => $actual <= $expected,
            'is_empty' => empty($actual),
            'is_not_empty' => !empty($actual),
            'in' => is_array($expected) && in_array($actual, $expected),
            'not_in' => is_array($expected) && !in_array($actual, $expected),
            default => false,
        };
    }
}
