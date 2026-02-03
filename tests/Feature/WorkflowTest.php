<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Workflow;
use App\Models\WorkflowStep;
use App\Services\Workflow\WorkflowService;
use App\Services\Workflow\WorkflowTriggerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Laravel\Sanctum\Sanctum;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Seed required data
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);
        
        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id, ['role' => 'Company Admin']);
        
        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_can_list_workflows()
    {
        Workflow::factory()->count(3)->create(['company_id' => $this->company->id]);

        $response = $this->getJson('/api/workflows', [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_create_workflow()
    {
        $workflowData = [
            'name' => 'Test Workflow',
            'description' => 'Test Description',
            'trigger_type' => 'customer_created',
            'status' => 'draft'
        ];

        $response = $this->postJson('/api/workflows', array_merge($workflowData, [
            'company_id' => $this->company->id
        ]));

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Workflow created successfully'
            ]);

        $this->assertDatabaseHas('workflows', [
            'name' => 'Test Workflow',
            'company_id' => $this->company->id,
            'trigger_type' => 'customer_created'
        ]);
    }

    /** @test */
    public function it_can_show_workflow()
    {
        $workflow = Workflow::factory()->create(['company_id' => $this->company->id]);

        $response = $this->getJson("/api/workflows/{$workflow->id}", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'workflow' => [
                    'id' => $workflow->id,
                    'name' => $workflow->name
                ]
            ]);
    }

    /** @test */
    public function it_can_update_workflow()
    {
        $workflow = Workflow::factory()->create(['company_id' => $this->company->id]);

        $response = $this->putJson("/api/workflows/{$workflow->id}", [
            'company_id' => $this->company->id,
            'name' => 'Updated Workflow',
            'description' => 'Updated Description',
            'status' => 'active'
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Workflow updated successfully'
            ]);

        $this->assertDatabaseHas('workflows', [
            'id' => $workflow->id,
            'name' => 'Updated Workflow'
        ]);
    }

    /** @test */
    public function it_can_delete_workflow()
    {
        $workflow = Workflow::factory()->create(['company_id' => $this->company->id]);

        $response = $this->deleteJson("/api/workflows/{$workflow->id}", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Workflow deleted successfully'
            ]);

        $this->assertSoftDeleted('workflows', [
            'id' => $workflow->id
        ]);
    }

    /** @test */
    public function it_can_activate_workflow()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'draft'
        ]);

        $response = $this->postJson("/api/workflows/{$workflow->id}/activate", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('workflows', [
            'id' => $workflow->id,
            'status' => 'active'
        ]);
    }

    /** @test */
    public function it_can_deactivate_workflow()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
            'status' => 'active'
        ]);

        $response = $this->postJson("/api/workflows/{$workflow->id}/deactivate", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('workflows', [
            'id' => $workflow->id,
            'status' => 'inactive'
        ]);
    }

    /** @test */
    public function it_can_duplicate_workflow()
    {
        $workflow = Workflow::factory()
            ->has(WorkflowStep::factory()->count(2), 'steps')
            ->create(['company_id' => $this->company->id]);

        $response = $this->postJson("/api/workflows/{$workflow->id}/duplicate", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('workflows', [
            'company_id' => $this->company->id,
            'name' => $workflow->name . ' (Copy)'
        ]);
    }

    /** @test */
    public function it_can_test_workflow()
    {
        Queue::fake();

        $workflow = Workflow::factory()->create(['company_id' => $this->company->id]);

        $response = $this->postJson("/api/workflows/{$workflow->id}/test", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function it_prevents_accessing_other_company_workflows()
    {
        $otherCompany = Company::factory()->create();
        $workflow = Workflow::factory()->create(['company_id' => $otherCompany->id]);

        $response = $this->getJson("/api/workflows/{$workflow->id}", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_workflow()
    {
        $response = $this->postJson('/api/workflows', [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'trigger_type']);
    }

    /** @test */
    public function it_can_add_step_to_workflow()
    {
        $workflow = Workflow::factory()->create(['company_id' => $this->company->id]);

        $stepData = [
            'name' => 'Test Step',
            'step_type' => 'action',
            'config' => ['action_type' => 'send_message', 'message' => 'Hello'],
            'position' => ['x' => 100, 'y' => 100]
        ];

        $response = $this->postJson("/api/workflows/{$workflow->id}/steps", array_merge($stepData, [
            'company_id' => $this->company->id
        ]));

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Step added successfully'
            ]);

        $this->assertDatabaseHas('workflow_steps', [
            'workflow_id' => $workflow->id,
            'name' => 'Test Step'
        ]);
    }

    /** @test */
    public function it_can_update_workflow_step()
    {
        $step = WorkflowStep::factory()
            ->for(Workflow::factory()->create(['company_id' => $this->company->id]), 'workflow')
            ->create();

        $response = $this->putJson("/api/workflows/steps/{$step->id}", [
            'company_id' => $this->company->id,
            'name' => 'Updated Step',
            'config' => ['action_type' => 'send_message', 'message' => 'Updated']
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('workflow_steps', [
            'id' => $step->id,
            'name' => 'Updated Step'
        ]);
    }

    /** @test */
    public function it_can_delete_workflow_step()
    {
        $step = WorkflowStep::factory()
            ->for(Workflow::factory()->create(['company_id' => $this->company->id]), 'workflow')
            ->create();

        $response = $this->deleteJson("/api/workflows/steps/{$step->id}", [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('workflow_steps', [
            'id' => $step->id
        ]);
    }

    /** @test */
    public function it_can_get_workflow_statistics()
    {
        Workflow::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'status' => 'active'
        ]);
        Workflow::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'status' => 'draft'
        ]);

        $response = $this->getJson('/api/workflows/statistics', [
            'company_id' => $this->company->id
        ]);

        $response->assertStatus(200)
            ->assertJsonFragment([
                'total_workflows' => 8,
                'active_workflows' => 5
            ]);
    }
}