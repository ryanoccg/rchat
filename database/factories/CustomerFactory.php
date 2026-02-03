<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Company;
use App\Models\MessagingPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        $platform = MessagingPlatform::first();

        $avatar = fake()->imageUrl();

        return [
            'company_id' => Company::factory(),
            'platform_user_id' => fake()->uuid(),
            'messaging_platform_id' => $platform?->id ?? 1,
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'profile_photo_url' => $avatar,
            'profile_data' => [
                'avatar' => $avatar,
                'profile_pic' => $avatar,
                'timezone' => fake()->timezone(),
            ],
            'language' => fake()->languageCode(),
            'metadata' => [],
        ];
    }
}
