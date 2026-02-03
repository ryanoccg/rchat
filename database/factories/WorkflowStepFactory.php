<?php

namespace Database\Factories;

use App\Models\WorkflowStep;
use App\Models\Workflow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WorkflowStep>
 */
class WorkflowStepFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = WorkflowStep::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_id' => Workflow::factory(),
            'name' => fake()->sentence(3),
            'step_type' => fake()->randomElement([
                'trigger', 'action', 'condition', 'delay', 'parallel', 'loop', 'merge'
            ]),
            'description' => fake()->sentence(),
            'position' => [
                'x' => fake()->numberBetween(0, 500),
                'y' => fake()->numberBetween(0, 500)
            ],
            'config' => [
                'action_type' => fake()->randomElement([
                    'send_message', 'send_ai_response', 'add_tag', 'remove_tag',
                    'assign_agent', 'assign_team', 'human_handoff', 'set_status',
                    'set_priority', 'add_note'
                ])
            ],
            'next_steps' => [],
        ];
    }
}