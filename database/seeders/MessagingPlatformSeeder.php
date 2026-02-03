<?php

namespace Database\Seeders;

use App\Models\MessagingPlatform;
use Illuminate\Database\Seeder;

class MessagingPlatformSeeder extends Seeder
{
    public function run(): void
    {
        MessagingPlatform::updateOrCreate(
            ['slug' => 'facebook'],
            [
                'name' => 'facebook',
                'display_name' => 'Facebook Messenger',
                'description' => 'Connect with customers through Facebook Messenger',
                'icon' => 'facebook',
                'config_fields' => [
                    'page_id' => ['type' => 'text', 'label' => 'Page ID', 'required' => true],
                    'page_access_token' => ['type' => 'text', 'label' => 'Page Access Token', 'required' => true],
                    'app_secret' => ['type' => 'text', 'label' => 'App Secret', 'required' => true],
                    'verify_token' => ['type' => 'text', 'label' => 'Verify Token', 'required' => true],
                ],
                'is_active' => true,
            ]
        );

        MessagingPlatform::updateOrCreate(
            ['slug' => 'whatsapp'],
            [
                'name' => 'whatsapp',
                'display_name' => 'WhatsApp Business',
                'description' => 'Connect with customers through WhatsApp Business API',
                'icon' => 'whatsapp',
                'config_fields' => [
                    'phone_number_id' => ['type' => 'text', 'label' => 'Phone Number ID', 'required' => true],
                    'business_account_id' => ['type' => 'text', 'label' => 'Business Account ID', 'required' => true],
                    'access_token' => ['type' => 'text', 'label' => 'Access Token', 'required' => true],
                    'webhook_verify_token' => ['type' => 'text', 'label' => 'Webhook Verify Token', 'required' => true],
                ],
                'is_active' => true,
            ]
        );

        MessagingPlatform::updateOrCreate(
            ['slug' => 'telegram'],
            [
                'name' => 'telegram',
                'display_name' => 'Telegram',
                'description' => 'Connect with customers through Telegram Bot',
                'icon' => 'telegram',
                'config_fields' => [
                    'bot_token' => ['type' => 'text', 'label' => 'Bot Token', 'required' => true],
                    'bot_username' => ['type' => 'text', 'label' => 'Bot Username', 'required' => true],
                ],
                'is_active' => true,
            ]
        );

        MessagingPlatform::updateOrCreate(
            ['slug' => 'line'],
            [
                'name' => 'line',
                'display_name' => 'LINE',
                'description' => 'Connect with customers through LINE Messaging API',
                'icon' => 'line',
                'config_fields' => [
                    'channel_id' => ['type' => 'text', 'label' => 'Channel ID', 'required' => true],
                    'channel_secret' => ['type' => 'text', 'label' => 'Channel Secret', 'required' => true],
                    'channel_access_token' => ['type' => 'text', 'label' => 'Channel Access Token', 'required' => true],
                ],
                'is_active' => true,
            ]
        );

        MessagingPlatform::updateOrCreate(
            ['slug' => 'webchat'],
            [
                'name' => 'webchat',
                'display_name' => 'Web Chat Widget',
                'description' => 'Embeddable chat widget for your website',
                'icon' => 'globe',
                'config_fields' => [
                    'widget_title' => ['type' => 'text', 'label' => 'Widget Title', 'required' => false, 'default' => 'Chat with us'],
                    'welcome_message' => ['type' => 'textarea', 'label' => 'Welcome Message', 'required' => false, 'default' => 'Hello! How can we help you today?'],
                    'primary_color' => ['type' => 'color', 'label' => 'Primary Color', 'required' => false, 'default' => '#6366f1'],
                    'position' => ['type' => 'select', 'label' => 'Position', 'required' => false, 'default' => 'bottom-right', 'options' => ['bottom-right', 'bottom-left']],
                    'allowed_domains' => ['type' => 'textarea', 'label' => 'Allowed Domains (one per line)', 'required' => false, 'default' => '*'],
                ],
                'is_active' => true,
            ]
        );
    }
}
