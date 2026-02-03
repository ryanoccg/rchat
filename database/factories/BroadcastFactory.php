<?php

namespace Database\Factories;

use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Models\PlatformConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Broadcast>
 */
class BroadcastFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = Broadcast::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'platform_connection_id' => null,
            'name' => fake()->sentence(3),
            'message' => fake()->paragraph(),
            'message_type' => 'text',
            'media_urls' => null,
            'status' => fake()->randomElement(['draft', 'scheduled', 'sending', 'completed', 'failed', 'cancelled']),
            'filters' => [
                'tags' => [fake()->word()],
                'date_from' => fake()->date(),
                'date_to' => fake()->date(),
            ],
            'scheduled_at' => fake()->dateTimeBetween('+1 day', '+7 days'),
            'started_at' => null,
            'completed_at' => null,
            'total_recipients' => fake()->numberBetween(0, 1000),
            'sent_count' => fake()->numberBetween(0, 500),
            'failed_count' => fake()->numberBetween(0, 100),
            'delivered_count' => fake()->numberBetween(0, 500),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BroadcastRecipient>
 */
class BroadcastRecipientFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = BroadcastRecipient::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'broadcast_id' => Broadcast::factory(),
            'customer_id' => Customer::factory(),
            'status' => fake()->randomElement(['pending', 'sent', 'failed', 'delivered']),
            'error_message' => fake()->optional()->sentence(),
            'sent_at' => fake()->optional()->dateTime(),
        ];
    }
}
