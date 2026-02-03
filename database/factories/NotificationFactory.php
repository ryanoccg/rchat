<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'company_id' => Company::factory(),
            'type' => fake()->randomElement(['info', 'success', 'warning', 'error']),
            'title' => fake()->sentence(),
            'message' => fake()->paragraph(),
            'data' => [],
            'action_url' => fake()->optional()->url(),
            'is_read' => fake()->boolean(30), // 30% chance of being read
            'read_at' => fake()->optional(30, now())->dateTime(), // 30% chance of having read_at
        ];
    }

    public function read(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    public function unread(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_read' => false,
            'read_at' => null,
        ]);
    }

    public function forConversation(int $conversationId): static
    {
        return $this->state(fn (array $attributes) => [
            'data' => ['conversation_id' => $conversationId],
            'action_url' => "/conversations?id={$conversationId}",
        ]);
    }
}
