<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\AiAgent;
use App\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiAgentTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected AiProvider $aiProvider;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required data
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);
        $this->seed(\Database\Seeders\AiProviderSeeder::class);

        $this->aiProvider = AiProvider::first();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id, ['role' => 'Company Admin']);

        // Remove any default agents created by CompanyObserver
        AiAgent::where('company_id', $this->company->id)->forceDelete();

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_can_list_ai_agents()
    {
        AiAgent::factory()->count(3)->create(['company_id' => $this->company->id]);

        $response = $this->getJson('/api/ai-agents');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_create_ai_agent()
    {
        $agentData = [
            'name' => 'Test Agent',
            'agent_type' => 'general',
            'system_prompt' => 'You are a helpful assistant',
            'ai_provider_id' => $this->aiProvider->id,
            'model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 300,
            'trigger_conditions' => [
                'customer_type' => 'new',
            ],
            'is_active' => true,
        ];

        $response = $this->postJson('/api/ai-agents', $agentData);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'AI agent created successfully',
            ]);

        $this->assertDatabaseHas('ai_agents', [
            'name' => 'Test Agent',
            'company_id' => $this->company->id,
            'agent_type' => 'general',
        ]);
    }

    /** @test */
    public function it_can_show_ai_agent()
    {
        $agent = AiAgent::factory()->create(['company_id' => $this->company->id]);

        $response = $this->getJson("/api/ai-agents/{$agent->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $agent->id)
            ->assertJsonPath('data.name', $agent->name);
    }

    /** @test */
    public function it_can_update_ai_agent()
    {
        $agent = AiAgent::factory()->create(['company_id' => $this->company->id]);

        $response = $this->putJson("/api/ai-agents/{$agent->id}", [
            'name' => 'Updated Agent',
            'system_prompt' => 'Updated prompt',
            'temperature' => 0.5,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'AI agent updated successfully',
            ]);

        $this->assertDatabaseHas('ai_agents', [
            'id' => $agent->id,
            'name' => 'Updated Agent',
        ]);
    }

    /** @test */
    public function it_can_delete_ai_agent()
    {
        $agent = AiAgent::factory()->create(['company_id' => $this->company->id]);

        $response = $this->deleteJson("/api/ai-agents/{$agent->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'AI agent deleted successfully',
            ]);

        // Soft delete - should not be found via normal query
        $this->assertNull(AiAgent::find($agent->id));
    }

    /** @test */
    public function it_can_duplicate_ai_agent()
    {
        $agent = AiAgent::factory()->create(['company_id' => $this->company->id]);

        $response = $this->postJson("/api/ai-agents/{$agent->id}/duplicate");

        $response->assertSuccessful();

        $this->assertDatabaseHas('ai_agents', [
            'company_id' => $this->company->id,
            'name' => $agent->name . ' (Copy)',
        ]);
    }

    /** @test */
    public function it_can_reorder_ai_agents()
    {
        $agent1 = AiAgent::factory()->create([
            'company_id' => $this->company->id,
            'priority' => 1,
        ]);
        $agent2 = AiAgent::factory()->create([
            'company_id' => $this->company->id,
            'priority' => 2,
        ]);

        $response = $this->postJson('/api/ai-agents/reorder', [
            'agents' => [
                ['id' => $agent2->id, 'priority' => 1],
                ['id' => $agent1->id, 'priority' => 2],
            ],
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('ai_agents', [
            'id' => $agent2->id,
            'priority' => 1,
        ]);
        $this->assertDatabaseHas('ai_agents', [
            'id' => $agent1->id,
            'priority' => 2,
        ]);
    }

    /** @test */
    public function it_can_initialize_default_agents()
    {
        $response = $this->postJson('/api/ai-agents/initialize-defaults');

        $response->assertSuccessful();

        $this->assertDatabaseHas('ai_agents', [
            'company_id' => $this->company->id,
            'agent_type' => 'new_customer',
        ]);
        $this->assertDatabaseHas('ai_agents', [
            'company_id' => $this->company->id,
            'agent_type' => 'general',
        ]);
    }

    /** @test */
    public function it_can_get_agent_types()
    {
        $response = $this->getJson('/api/ai-agents/types');

        $response->assertStatus(200)
            ->assertJsonFragment([
                'value' => 'new_customer',
                'label' => 'New Customer',
            ])
            ->assertJsonFragment([
                'value' => 'returning_customer',
                'label' => 'Returning Customer',
            ]);
    }

    /** @test */
    public function it_prevents_accessing_other_company_agents()
    {
        $otherCompany = Company::factory()->create();
        AiAgent::where('company_id', $otherCompany->id)->forceDelete();
        $agent = AiAgent::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->getJson("/api/ai-agents/{$agent->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_agent()
    {
        $response = $this->postJson('/api/ai-agents', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'agent_type']);
    }

    /** @test */
    public function it_validates_agent_type()
    {
        $response = $this->postJson('/api/ai-agents', [
            'name' => 'Test Agent',
            'agent_type' => 'invalid_type',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['agent_type']);
    }

    /** @test */
    public function it_validates_max_tokens()
    {
        $response = $this->postJson('/api/ai-agents', [
            'name' => 'Test Agent',
            'agent_type' => 'general',
            'ai_provider_id' => $this->aiProvider->id,
            'model' => 'gpt-4o-mini',
            'max_tokens' => 5000,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['max_tokens']);
    }
}
