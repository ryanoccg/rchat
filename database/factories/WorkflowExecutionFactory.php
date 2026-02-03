<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\Customer;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkflowExecutionFactory extends Factory
{
    protected $model = WorkflowExecution::class;

    public function definition(): array
    {
        return [
            'company_id' => 1,
            'workflow_id' => 1,
            'customer_id' => null,
            'conversation_id' => null,
            'status' => fake()->randomElement(['pending', 'running', 'completed', 'failed', 'cancelled']),
            'current_step_id' => null,
            'execution_context' => [],
            'started_at' => fake()->optional()->dateTime(),
            'completed_at' => fake()->optional()->dateTime(),
            'failed_at' => fake()->optional()->dateTime(),
            'error_message' => fake()->optional()->sentence(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
            'started_at' => null,
            'completed_at' => null,
            'failed_at' => null,
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running',
            'started_at' => now()->subMinutes(fake()->numberBetween(1, 30)),
            'completed_at' => null,
            'failed_at' => null,
        ]);
    }

    public function completed(): static
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 60));
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'started_at' => $startedAt,
            'completed_at' => $startedAt->copy()->addMinutes(fake()->numberBetween(1, 10)),
            'failed_at' => null,
            'error_message' => null,
        ]);
    }

    public function failed(): static
    {
        $startedAt = now()->subMinutes(fake()->numberBetween(5, 60));
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'started_at' => $startedAt,
            'completed_at' => null,
            'failed_at' => $startedAt->copy()->addMinutes(fake()->numberBetween(1, 10)),
            'error_message' => fake()->sentence(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'cancelled',
            'error_message' => null,
        ]);
    }

    public function forCompany(Company $company): static
    {
        return $this->state(fn (array $attributes) => [
            'company_id' => $company->id,
            'workflow_id' => Workflow::factory()->state(['company_id' => $company->id]),
        ]);
    }
}
