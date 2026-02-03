<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Company;
use Illuminate\Database\Eloquent\Factories\Factory;

class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    public function definition(): array
    {
        $company = Company::first() ?? Company::factory()->create();
        $startTime = $this->faker->dateTimeBetween('+1 day', '+7 days');
        $endTime = (clone $startTime)->modify('+1 hour');

        return [
            'company_id' => $company->id,
            'customer_id' => null,
            'conversation_id' => null,
            'google_event_id' => 'google_event_' . $this->faker->unique()->randomNumber(),
            'title' => $this->faker->randomElement(['Consultation', 'Follow-up', 'Product Demo', 'Support Call']),
            'description' => $this->faker->optional()->sentence(),
            'start_time' => $startTime,
            'end_time' => $endTime,
            'customer_name' => $this->faker->name(),
            'customer_email' => $this->faker->optional()->email(),
            'customer_phone' => $this->faker->optional()->phoneNumber(),
            'status' => $this->faker->randomElement([Appointment::STATUS_PENDING, Appointment::STATUS_CONFIRMED, Appointment::STATUS_COMPLETED]),
            'notes' => $this->faker->optional()->sentence(),
            'metadata' => [
                'booked_via' => 'chat',
            ],
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Appointment::STATUS_PENDING,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => Appointment::STATUS_CANCELLED,
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn (array $attributes) => [
            'start_time' => now()->addDays(rand(1, 7)),
            'end_time' => now()->addDays(rand(1, 7))->addHour(),
            'status' => Appointment::STATUS_CONFIRMED,
        ]);
    }
}
