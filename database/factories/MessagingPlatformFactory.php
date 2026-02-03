<?php

namespace Database\Factories;

use App\Models\MessagingPlatform;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessagingPlatformFactory extends Factory
{
    protected $model = MessagingPlatform::class;

    public function definition(): array
    {
        $platforms = [
            ['name' => 'WhatsApp', 'slug' => 'whatsapp', 'icon' => 'whatsapp'],
            ['name' => 'Messenger', 'slug' => 'facebook', 'icon' => 'facebook'],
            ['name' => 'Instagram', 'slug' => 'instagram', 'icon' => 'instagram'],
            ['name' => 'Telegram', 'slug' => 'telegram', 'icon' => 'telegram'],
            ['name' => 'WeChat', 'slug' => 'wechat', 'icon' => 'weixin'],
            ['name' => 'LINE', 'slug' => 'line', 'icon' => 'comment'],
        ];

        $platform = fake()->randomElement($platforms);

        return [
            'name' => $platform['name'],
            'slug' => $platform['slug'],
            'display_name' => $platform['name'],
            'icon' => $platform['icon'],
            'description' => "Connect your {$platform['name']} business account",
            'is_active' => true,
            'config_fields' => $this->getConfigurationSchema($platform['slug']),
        ];
    }

    protected function getConfigurationSchema(string $slug): array
    {
        $schemas = [
            'whatsapp' => [
                'api_key' => ['type' => 'string', 'required' => true],
                'phone_number_id' => ['type' => 'string', 'required' => true],
                'business_id' => ['type' => 'string', 'required' => true],
            ],
            'facebook' => [
                'page_id' => ['type' => 'string', 'required' => true],
                'access_token' => ['type' => 'string', 'required' => true],
            ],
            'instagram' => [
                'account_id' => ['type' => 'string', 'required' => true],
                'access_token' => ['type' => 'string', 'required' => true],
            ],
            'telegram' => [
                'bot_token' => ['type' => 'string', 'required' => true],
            ],
            'wechat' => [
                'app_id' => ['type' => 'string', 'required' => true],
                'app_secret' => ['type' => 'string', 'required' => true],
            ],
            'line' => [
                'channel_id' => ['type' => 'string', 'required' => true],
                'channel_secret' => ['type' => 'string', 'required' => true],
                'access_token' => ['type' => 'string', 'required' => true],
            ],
        ];

        return $schemas[$slug] ?? [];
    }

    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'WhatsApp',
            'slug' => 'whatsapp',
            'icon' => 'whatsapp',
            'description' => 'Connect your WhatsApp Business account',
            'config_fields' => $this->getConfigurationSchema('whatsapp'),
        ]);
    }

    public function telegram(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Telegram',
            'slug' => 'telegram',
            'icon' => 'telegram',
            'description' => 'Connect your Telegram bot',
            'config_fields' => $this->getConfigurationSchema('telegram'),
        ]);
    }

    public function messenger(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Messenger',
            'slug' => 'facebook',
            'icon' => 'facebook',
            'description' => 'Connect your Facebook Messenger',
            'config_fields' => $this->getConfigurationSchema('facebook'),
        ]);
    }
}
