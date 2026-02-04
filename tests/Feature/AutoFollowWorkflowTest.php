<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowExecution;
use Database\Seeders\MessagingPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AutoFollowWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected User $user;
    protected PlatformConnection $connection;
    protected Customer $customer;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MessagingPlatformSeeder::class);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id, [
            'role' => 'Company Owner',
            'joined_at' => now(),
        ]);

        $platform = MessagingPlatform::first();
        $this->connection = PlatformConnection::create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $platform->id,
            'name' => 'Test Connection',
            'is_active' => true,
            'credentials' => ['token' => 'test'],
            'connected_at' => now(),
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'platform_connection_id' => $this->connection->id,
            'messaging_platform_id' => $platform->id,
            'platform_user_id' => 'test_user_123',
            'name' => 'Test Customer',
        ]);

        $this->conversation = Conversation::create([
            'company_id' => $this->company->id,
            'platform_connection_id' => $this->connection->id,
            'customer_id' => $this->customer->id,
            'status' => 'open',
            'is_ai_handling' => true,
            'last_message_at' => now()->subDays(10),
        ]);
    }

    /** @test */
    public function can_create_auto_follow_up_workflow(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/workflows', [
                'company_id' => $this->company->id,
                'name' => 'Auto Follow Up Test',
                'trigger_type' => 'auto_follow_up',
                'trigger_config' => [
                    'inactive_days' => 7,
                    'max_follow_ups' => 3,
                ],
                'status' => 'active',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('workflows', [
            'name' => 'Auto Follow Up Test',
            'trigger_type' => 'auto_follow_up',
            'company_id' => $this->company->id,
        ]);
    }

    /** @test */
    public function auto_follow_workflow_validates_trigger_type(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/workflows', [
                'company_id' => $this->company->id,
                'name' => 'Invalid Trigger Test',
                'trigger_type' => 'invalid_trigger_type',
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['trigger_type']);
    }

    /** @test */
    public function auto_follow_command_finds_inactive_conversations(): void
    {
        Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 7],
            'status' => 'active',
            'workflow_definition' => [],
        ]);

        Artisan::call('workflows:run-auto-follow', ['--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('Found 1 inactive conversation', $output);
    }

    /** @test */
    public function auto_follow_command_skips_closed_conversations(): void
    {
        $this->conversation->update(['status' => 'closed']);

        Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 7],
            'status' => 'active',
            'workflow_definition' => [],
        ]);

        Artisan::call('workflows:run-auto-follow', ['--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('Found 0 inactive conversation', $output);
    }

    /** @test */
    public function auto_follow_command_respects_inactive_days_config(): void
    {
        // Conversation is 10 days old, config requires 14 days
        Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 14],
            'status' => 'active',
            'workflow_definition' => [],
        ]);

        Artisan::call('workflows:run-auto-follow', ['--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('Found 0 inactive conversation', $output);
    }

    /** @test */
    public function auto_follow_command_respects_max_follow_ups(): void
    {
        $this->conversation->update([
            'workflow_metadata' => ['auto_follow_up_count' => 3],
        ]);

        Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 7, 'max_follow_ups' => 3],
            'status' => 'active',
            'workflow_definition' => [],
        ]);

        Artisan::call('workflows:run-auto-follow', ['--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('max follow-ups reached', $output);
    }

    /** @test */
    public function auto_follow_command_filters_by_company(): void
    {
        $otherCompany = Company::factory()->create();

        Workflow::create([
            'company_id' => $otherCompany->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 7],
            'status' => 'active',
            'workflow_definition' => [],
        ]);

        Artisan::call('workflows:run-auto-follow', ['--company' => $otherCompany->id, '--dry-run' => true]);

        $output = Artisan::output();
        // Should find 0 because the workflow belongs to other company, and conversation belongs to $this->company
        $this->assertStringContainsString('Found 0 inactive conversation', $output);
    }

    /** @test */
    public function auto_follow_command_skips_conversations_with_active_workflow(): void
    {
        // Create a real workflow and execution for foreign key constraint
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 7],
            'status' => 'active',
            'workflow_definition' => [],
        ]);

        $execution = WorkflowExecution::create([
            'workflow_id' => $workflow->id,
            'conversation_id' => $this->conversation->id,
            'company_id' => $this->company->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        // Set active workflow execution on conversation
        $this->conversation->update(['active_workflow_execution_id' => $execution->id]);

        Artisan::call('workflows:run-auto-follow', ['--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('Found 0 inactive conversation', $output);
    }

    /** @test */
    public function auto_follow_workflow_can_start_for_correct_trigger_source(): void
    {
        $workflow = Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 7],
            'status' => 'active',
            'workflow_definition' => [],
        ]);

        // Should start when trigger_source is 'auto_follow_up'
        $canStart = $workflow->canStartFor([
            'conversation' => $this->conversation,
            'trigger_source' => 'auto_follow_up',
        ]);

        $this->assertTrue($canStart);

        // Should not start without trigger_source
        $cannotStart = $workflow->canStartFor([
            'conversation' => $this->conversation,
        ]);

        $this->assertFalse($cannotStart);
    }

    /** @test */
    public function auto_follow_command_skips_inactive_workflows(): void
    {
        Workflow::create([
            'company_id' => $this->company->id,
            'name' => 'Auto Follow Up',
            'trigger_type' => 'auto_follow_up',
            'trigger_config' => ['inactive_days' => 7],
            'status' => 'inactive', // Not active
            'workflow_definition' => [],
        ]);

        Artisan::call('workflows:run-auto-follow', ['--dry-run' => true]);

        $output = Artisan::output();
        $this->assertStringContainsString('No active auto-follow workflows found', $output);
    }
}
