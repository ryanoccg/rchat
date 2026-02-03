<?php

namespace Database\Factories;

use App\Models\AiAgent;
use App\Models\AiProvider;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AiAgent>
 */
class AiAgentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = AiAgent::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->sentence(3);
        $aiProvider = AiProvider::first();

        return [
            'company_id' => Company::factory(),
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::random(6),
            'agent_type' => fake()->randomElement([
                'new_customer', 'returning_customer', 'follow_up', 'vip', 'general', 'custom'
            ]),
            'description' => fake()->optional()->sentence(),
            'ai_provider_id' => $aiProvider?->id ?? 1,
            'system_prompt' => fake()->paragraph(),
            'model' => 'gpt-5-mini',
            'temperature' => fake()->randomFloat(2, 0, 1),
            'max_tokens' => fake()->numberBetween(100, 4096),
            'confidence_threshold' => fake()->numberBetween(30, 90),
            'trigger_conditions' => [
                'customer_type' => fake()->randomElement(['new', 'returning', 'vip', 'general']),
                'min_message_count' => fake()->numberBetween(1, 10),
                'max_message_count' => fake()->numberBetween(10, 100),
                'requires_follow_up' => fake()->boolean(),
                'time_since_last_message_hours' => fake()->numberBetween(24, 168),
                'tags' => [fake()->word()],
            ],
            'priority' => fake()->numberBetween(1, 10),
            'is_active' => true,
            'is_personality_only' => false, // Default to legacy mode for tests
        ];
    }

    /**
     * Create a personality-only agent (no trigger conditions)
     */
    public function personalityOnly(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_personality_only' => true,
            'trigger_conditions' => null,
            'priority' => null,
        ]);
    }
}
