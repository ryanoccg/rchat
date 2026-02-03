<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Workflow\WorkflowActionService;
use App\Services\Workflow\WorkflowStepExecutor;
use Database\Seeders\MessagingPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class WorkflowConditionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected MessagingPlatform $platform;
    protected PlatformConnection $connection;
    protected WorkflowStepExecutor $executor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MessagingPlatformSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id, [
            'role' => 'Company Owner',
            'joined_at' => now(),
        ]);
        $this->user->assignRole('Company Owner');

        $this->platform = MessagingPlatform::first();
        $this->connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        // Mock the WorkflowActionService dependency
        $actionService = Mockery::mock(WorkflowActionService::class);
        $this->executor = new WorkflowStepExecutor($actionService);
    }

    /** @test */
    public function customer_type_is_determined_as_new_for_less_than_3_messages()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        // Create 2 customer messages (should be "new")
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Message 1',
            'message_type' => 'text',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Message 2',
            'message_type' => 'text',
        ]);

        $context = [
            'customer' => $customer,
            'conversation' => $conversation,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $context, 'customer_type', 'equals', 'new');

        $this->assertTrue($result);
    }

    /** @test */
    public function customer_type_is_determined_as_returning_for_3_to_10_messages()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        // Create 5 customer messages (should be "returning")
        foreach (range(1, 5) as $i) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'is_from_customer' => true,
                'content' => "Message {$i}",
                'message_type' => 'text',
            ]);
        }

        $context = [
            'customer' => $customer,
            'conversation' => $conversation,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $context, 'customer_type', 'equals', 'returning');

        $this->assertTrue($result);
    }

    /** @test */
    public function customer_type_is_determined_as_vip_for_more_than_10_messages()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        // Create 15 customer messages (should be "vip")
        foreach (range(1, 15) as $i) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'is_from_customer' => true,
                'content' => "Message {$i}",
                'message_type' => 'text',
            ]);
        }

        $context = [
            'customer' => $customer,
            'conversation' => $conversation,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $context, 'customer_type', 'equals', 'vip');

        $this->assertTrue($result);
    }

    /** @test */
    public function total_message_count_is_calculated_across_all_conversations()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation1 = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $conversation2 = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        // Create messages in both conversations
        foreach (range(1, 3) as $i) {
            Message::create([
                'conversation_id' => $conversation1->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'is_from_customer' => true,
                'content' => "Conv1 Msg {$i}",
                'message_type' => 'text',
            ]);
        }

        foreach (range(1, 4) as $i) {
            Message::create([
                'conversation_id' => $conversation2->id,
                'sender_type' => 'customer',
                'sender_id' => null,
                'is_from_customer' => true,
                'content' => "Conv2 Msg {$i}",
                'message_type' => 'text',
            ]);
        }

        // Add some agent messages that shouldn't count
        foreach (range(1, 2) as $i) {
            Message::create([
                'conversation_id' => $conversation1->id,
                'sender_type' => 'agent',
                'sender_id' => $this->user->id,
                'is_from_customer' => false,
                'content' => "Agent Msg {$i}",
                'message_type' => 'text',
            ]);
        }

        $context = [
            'customer' => $customer,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        // 3 + 4 = 7 total customer messages
        $result = $method->invoke($this->executor, $context, 'total_message_count', 'greater_equal', 7);

        $this->assertTrue($result);

        // Should not be >= 8
        $result2 = $method->invoke($this->executor, $context, 'total_message_count', 'greater_equal', 8);
        $this->assertFalse($result2);
    }

    /** @test */
    public function conversation_count_is_calculated_correctly()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        foreach (range(1, 3) as $i) {
            Conversation::factory()->create([
                'company_id' => $this->company->id,
                'customer_id' => $customer->id,
                'platform_connection_id' => $this->connection->id,
            ]);
        }

        $context = [
            'customer' => $customer,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $context, 'conversation_count', 'equals', 3);

        $this->assertTrue($result);
    }

    /** @test */
    public function customer_attribute_condition_returns_false_for_non_existent_customer()
    {
        $context = [
            'customer' => null,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $context, 'customer_type', 'equals', 'new');

        $this->assertFalse($result);
    }

    /** @test */
    public function customer_type_returns_new_for_zero_messages()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        // No customer messages

        $context = [
            'customer' => $customer,
            'conversation' => $conversation,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $context, 'customer_type', 'equals', 'new');

        $this->assertTrue($result);
    }

    /** @test */
    public function customer_attribute_works_with_customer_array()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
            'name' => 'Test Customer',
        ]);

        // Pass customer as array (like from webhook)
        $context = [
            'customer' => $customer->toArray(),
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $context, 'name', 'equals', 'Test Customer');

        $this->assertTrue($result);
    }

    /** @test */
    public function determine_customer_type_method_is_accessible()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('determineCustomerType');
        $method->setAccessible(true);

        $result = $method->invoke($this->executor, $customer);

        // Customer with 0 messages should be 'new'
        $this->assertEquals('new', $result);
    }

    /** @test */
    public function agent_messages_do_not_affect_customer_type()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        // Create 50 agent messages (should not affect customer type)
        foreach (range(1, 50) as $i) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_type' => 'agent',
                'sender_id' => $this->user->id,
                'is_from_customer' => false,
                'content' => "Agent Msg {$i}",
                'message_type' => 'text',
            ]);
        }

        $context = [
            'customer' => $customer,
            'conversation' => $conversation,
        ];

        $reflection = new \ReflectionClass($this->executor);
        $method = $reflection->getMethod('evaluateCustomerAttribute');
        $method->setAccessible(true);

        // Should still be 'new' since there are no customer messages
        $result = $method->invoke($this->executor, $context, 'customer_type', 'equals', 'new');

        $this->assertTrue($result);
    }
}

