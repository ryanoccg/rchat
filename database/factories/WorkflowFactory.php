<?php

namespace Database\Factories;

use App\Models\Workflow;
use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Workflow>
 */
class WorkflowFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Workflow::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->paragraph(),
            'status' => fake()->randomElement(['active', 'inactive', 'draft']),
            'trigger_type' => fake()->randomElement([
                'customer_created',
                'customer_returning',
                'first_message',
                'conversation_created',
                'conversation_closed',
                'message_received',
                'no_response',
                'scheduled'
            ]),
            'trigger_config' => [],
            'workflow_definition' => [
                'steps' => [],
                'connections' => []
            ],
            'execution_mode' => fake()->randomElement(['sequential', 'parallel', 'mixed']),
        ];
    }
}