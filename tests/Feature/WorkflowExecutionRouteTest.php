<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class WorkflowExecutionRouteTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles if they don't exist
        Role::firstOrCreate(['name' => 'Company Owner', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Company Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Agent', 'guard_name' => 'web']);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id, [
            'role' => 'Company Owner',
            'joined_at' => now(),
        ]);
        $this->user->assignRole('Company Owner');
    }

    /** @test */
    public function executions_route_lists_all_executions()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        WorkflowExecution::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/workflows/executions');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);
    }

    /** @test */
    public function executions_route_does_not_conflict_with_workflow_id_route()
    {
        // Create a workflow with ID that could potentially conflict
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        // Create some executions
        WorkflowExecution::factory()->count(2)->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
        ]);

        // The /executions route should return executions, not try to find a workflow with ID 'executions'
        $response = $this->actingAs($this->user)
            ->getJson('/api/workflows/executions');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'workflow_id', 'status', 'created_at'],
                ],
            ]);
    }

    /** @test */
    public function execution_details_route_works_correctly()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $execution = WorkflowExecution::factory()->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
            'status' => 'running',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/workflows/executions/{$execution->id}");

        $response->assertOk()
            ->assertJsonPath('execution.id', $execution->id)
            ->assertJsonPath('execution.status', 'running');
    }

    /** @test */
    public function can_cancel_workflow_execution()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $execution = WorkflowExecution::factory()->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
            'status' => 'running',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/workflows/executions/{$execution->id}/cancel");

        $response->assertOk();

        $execution->refresh();
        $this->assertEquals('cancelled', $execution->status);
    }

    /** @test */
    public function can_retry_failed_workflow_execution()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $execution = WorkflowExecution::factory()->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
            'status' => 'failed',
            'error_message' => 'Test error',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/workflows/executions/{$execution->id}/retry");

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Execution retry started',
            ]);
    }

    /** @test */
    public function workflow_show_route_still_works_with_numeric_id()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Test Workflow',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/workflows/{$workflow->id}");

        $response->assertOk()
            ->assertJson([
                'workflow' => [
                    'id' => $workflow->id,
                    'name' => 'Test Workflow',
                ],
            ]);
    }

    /** @test */
    public function cannot_access_other_company_executions()
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['current_company_id' => $otherCompany->id]);
        $otherUser->companies()->attach($otherCompany->id);

        $workflow = Workflow::factory()->create([
            'company_id' => $otherCompany->id,
        ]);

        WorkflowExecution::factory()->create([
            'company_id' => $otherCompany->id,
            'workflow_id' => $workflow->id,
        ]);

        // Try to access as different company user
        $response = $this->actingAs($this->user)
            ->getJson('/api/workflows/executions');

        $response->assertOk();
        $this->assertEmpty($response->json('data'));
    }

    /** @test */
    public function can_filter_executions_by_workflow_id()
    {
        $workflow1 = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $workflow2 = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        WorkflowExecution::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow1->id,
        ]);

        WorkflowExecution::factory()->count(2)->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow2->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/workflows/executions?workflow_id={$workflow1->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);
        foreach ($data as $execution) {
            $this->assertEquals($workflow1->id, $execution['workflow_id']);
        }
    }

    /** @test */
    public function can_filter_executions_by_status()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
        ]);

        WorkflowExecution::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
            'status' => 'completed',
        ]);

        WorkflowExecution::factory()->count(2)->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
            'status' => 'failed',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/workflows/executions?status=completed');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(3, $data);
        foreach ($data as $execution) {
            $this->assertEquals('completed', $execution['status']);
        }
    }

    /** @test */
    public function execution_includes_workflow_details()
    {
        $workflow = Workflow::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Test Workflow',
        ]);

        $execution = WorkflowExecution::factory()->create([
            'company_id' => $this->company->id,
            'workflow_id' => $workflow->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/workflows/executions/{$execution->id}");

        $response->assertOk()
            ->assertJson([
                'execution' => [
                    'id' => $execution->id,
                    'workflow' => [
                        'id' => $workflow->id,
                        'name' => 'Test Workflow',
                    ],
                ],
            ]);
    }
}
