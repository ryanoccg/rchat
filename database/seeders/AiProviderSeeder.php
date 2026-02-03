<?php

namespace Database\Seeders;

use App\Models\AiProvider;
use Illuminate\Database\Seeder;

class AiProviderSeeder extends Seeder
{
    public function run(): void
    {
        AiProvider::create([
            'name' => 'openai',
            'slug' => 'openai',
            'display_name' => 'OpenAI',
            'description' => 'GPT models from OpenAI including GPT-4o and GPT-4',
            'available_models' => [
                'gpt-4o',
                'gpt-4o-mini',
                'gpt-4-turbo',
                'gpt-4',
                'gpt-3.5-turbo',
            ],
            'capabilities' => ['text', 'image', 'audio'],
            'is_active' => true,
        ]);

        AiProvider::create([
            'name' => 'gemini',
            'slug' => 'gemini',
            'display_name' => 'Google Gemini',
            'description' => 'Google\'s Gemini AI models',
            'available_models' => [
                'gemini-2.0-flash-exp',
                'gemini-1.5-pro',
                'gemini-1.5-flash',
                'gemini-pro',
            ],
            'capabilities' => ['text', 'image'],
            'is_active' => true,
        ]);

        AiProvider::create([
            'name' => 'claude',
            'slug' => 'claude',
            'display_name' => 'Anthropic Claude',
            'description' => 'Claude AI models from Anthropic',
            'available_models' => [
                'claude-3-opus',
                'claude-3-sonnet',
                'claude-3-haiku',
            ],
            'capabilities' => ['text', 'image'],
            'is_active' => true,
        ]);
    }
}
