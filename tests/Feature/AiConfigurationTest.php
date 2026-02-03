<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'AiProviderSeeder']);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->user->companies()->attach($this->company->id);
    }

    public function test_can_list_ai_providers(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ai-providers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'display_name', 'available_models', 'capabilities'],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_get_models_for_provider(): void
    {
        $provider = AiProvider::where('slug', 'openai')->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/ai-providers/{$provider->id}/models");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);

        $this->assertContains('gpt-4', $response->json('data'));
    }

    public function test_can_create_ai_configuration(): void
    {
        $provider = AiProvider::where('slug', 'openai')->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ai-configuration', [
                'primary_provider_id' => $provider->id,
                'primary_model' => 'gpt-4',
                'system_prompt' => 'You are a helpful assistant.',
                'personality_tone' => 'friendly',
                'auto_respond' => true,
                'max_tokens' => 1024,
                'temperature' => 0.7,
                'confidence_threshold' => 0.8,
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'company_id',
                    'primary_provider_id',
                    'primary_model',
                    'system_prompt',
                ],
            ]);

        $this->assertDatabaseHas('ai_configurations', [
            'company_id' => $this->company->id,
            'primary_provider_id' => $provider->id,
            'primary_model' => 'gpt-4',
        ]);
    }

    public function test_can_get_ai_configuration(): void
    {
        $provider = AiProvider::where('slug', 'gemini')->first();

        // Delete any auto-created config from CompanyObserver first
        AiConfiguration::where('company_id', $this->company->id)->delete();

        AiConfiguration::create([
            'company_id' => $this->company->id,
            'primary_provider_id' => $provider->id,
            'primary_model' => 'gemini-pro',
            'system_prompt' => 'Be helpful.',
            'auto_respond' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ai-configuration');

        $response->assertStatus(200)
            ->assertJson([
                'data' => [
                    'company_id' => $this->company->id,
                    'primary_model' => 'gemini-pro',
                ],
            ]);
    }

    public function test_can_update_ai_configuration(): void
    {
        $provider = AiProvider::where('slug', 'openai')->first();

        AiConfiguration::create([
            'company_id' => $this->company->id,
            'primary_provider_id' => $provider->id,
            'primary_model' => 'gpt-3.5-turbo',
            'system_prompt' => 'Old prompt.',
            'auto_respond' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson('/api/ai-configuration', [
                'primary_model' => 'gpt-4',
                'system_prompt' => 'New improved prompt.',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('ai_configurations', [
            'company_id' => $this->company->id,
            'primary_model' => 'gpt-4',
            'system_prompt' => 'New improved prompt.',
        ]);
    }

    public function test_can_toggle_auto_respond(): void
    {
        $provider = AiProvider::where('slug', 'claude')->first();

        AiConfiguration::create([
            'company_id' => $this->company->id,
            'primary_provider_id' => $provider->id,
            'primary_model' => 'claude-3-sonnet',
            'auto_respond' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ai-configuration/toggle-auto-respond');

        $response->assertStatus(200)
            ->assertJson(['auto_respond' => false]);

        // Toggle back
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ai-configuration/toggle-auto-respond');

        $response->assertStatus(200)
            ->assertJson(['auto_respond' => true]);
    }

    public function test_cannot_access_other_company_configuration(): void
    {
        $otherCompany = Company::factory()->create();
        $provider = AiProvider::where('slug', 'openai')->first();

        // Other company has a custom config
        AiConfiguration::where('company_id', $otherCompany->id)->delete();
        AiConfiguration::create([
            'company_id' => $otherCompany->id,
            'primary_provider_id' => $provider->id,
            'primary_model' => 'gpt-4',
            'auto_respond' => true,
        ]);

        // Delete our company's auto-created config to test null response
        AiConfiguration::where('company_id', $this->company->id)->delete();

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/ai-configuration');

        // Should return null for our company (no config), not the other company's config
        $response->assertStatus(200)
            ->assertJson(['data' => null]);
    }

    public function test_validation_requires_provider_and_model(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ai-configuration', [
                'system_prompt' => 'Some prompt',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['primary_provider_id', 'primary_model']);
    }

    public function test_can_set_fallback_provider(): void
    {
        $openai = AiProvider::where('slug', 'openai')->first();
        $claude = AiProvider::where('slug', 'claude')->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/ai-configuration', [
                'primary_provider_id' => $openai->id,
                'fallback_provider_id' => $claude->id,
                'primary_model' => 'gpt-4',
                'auto_respond' => true,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('ai_configurations', [
            'company_id' => $this->company->id,
            'primary_provider_id' => $openai->id,
            'fallback_provider_id' => $claude->id,
        ]);
    }
}
