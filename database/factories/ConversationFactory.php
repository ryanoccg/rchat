<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Company;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'customer_id' => Customer::factory(),
            'platform_connection_id' => null,
            'status' => fake()->randomElement(['open', 'in_progress', 'escalated', 'closed']),
            'priority' => fake()->randomElement(['low', 'normal', 'high', 'urgent']),
            'is_ai_handling' => fake()->boolean(),
            'ai_confidence_score' => fake()->numberBetween(0, 100),
            'assigned_to' => null,
            'last_message_at' => now(),
            'closed_at' => null,
        ];
    }
}
