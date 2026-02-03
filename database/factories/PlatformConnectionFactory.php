<?php

namespace Database\Factories;

use App\Models\PlatformConnection;
use App\Models\Company;
use App\Models\MessagingPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PlatformConnectionFactory extends Factory
{
    protected $model = PlatformConnection::class;

    public function definition(): array
    {
        $webhookToken = Str::random(32);
        $platform = MessagingPlatform::first();
        $platformId = $platform?->id ?? 1;
        $platformSlug = $platform?->slug ?? 'facebook';

        return [
            'company_id' => Company::factory(),
            'messaging_platform_id' => $platformId,
            'platform_account_id' => fake()->uuid(),
            'platform_account_name' => fake()->company() . ' Bot',
            'credentials' => [
                'webhook_token' => $webhookToken,
            ],
            'webhook_config' => [
                'url' => url("/api/webhooks/{$platformSlug}/{$webhookToken}"),
            ],
            'is_active' => true,
            'connected_at' => now(),
            'last_sync_at' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function forPlatform(MessagingPlatform $platform): static
    {
        $webhookToken = Str::random(32);

        return $this->state(fn (array $attributes) => [
            'messaging_platform_id' => $platform->id,
            'webhook_config' => [
                'url' => url("/api/webhooks/{$platform->slug}/{$webhookToken}"),
            ],
        ]);
    }
}
