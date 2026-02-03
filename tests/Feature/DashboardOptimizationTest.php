<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use Database\Seeders\MessagingPlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardOptimizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected MessagingPlatform $platform;
    protected PlatformConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MessagingPlatformSeeder::class);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id);

        $this->platform = MessagingPlatform::first();
        $this->connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);
    }

    /** @test */
    public function recent_conversations_returns_minimal_data_structure()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
            'name' => 'Test Customer',
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
            'status' => 'active',
            'priority' => 'high',
            'assigned_to' => $this->user->id,
        ]);

        // Create a message for latestMessage
        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Latest message',
            'message_type' => 'text',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'status',
                    'priority',
                    'last_message_at',
                    'customer' => [
                        'id',
                        'name',
                        'display_name',
                        'profile_photo_url',
                    ],
                    'platform',
                    'assigned_agent',
                    'last_message',
                ],
            ]);
    }

    /** @test */
    public function recent_conversations_returns_only_required_fields_not_full_resource()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
            'name' => 'Test Customer',
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
            'status' => 'active',
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Latest message',
            'message_type' => 'text',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        // Verify we get exactly the fields we expect, no more
        $this->assertArrayHasKey('id', $data[0]);
        $this->assertArrayHasKey('status', $data[0]);
        $this->assertArrayHasKey('priority', $data[0]);
        $this->assertArrayHasKey('last_message_at', $data[0]);
        $this->assertArrayHasKey('customer', $data[0]);
        $this->assertArrayHasKey('platform', $data[0]);
        $this->assertArrayHasKey('assigned_agent', $data[0]);
        $this->assertArrayHasKey('last_message', $data[0]);

        // Verify excluded fields are not present
        $this->assertArrayNotHasKey('messages', $data[0]);
        $this->assertArrayNotHasKey('ai_response_data', $data[0]);
        $this->assertArrayNotHasKey('workflow_state', $data[0]);
    }

    /** @test */
    public function recent_conversations_includes_platform_data()
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

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        $this->assertNotNull($data[0]['platform']);
        $this->assertEquals($this->platform->id, $data[0]['platform']['id']);
        $this->assertEquals($this->platform->name, $data[0]['platform']['name']);
        $this->assertEquals($this->platform->slug, $data[0]['platform']['slug']);
    }

    /** @test */
    public function recent_conversations_limits_to_10_results()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        // Create 15 conversations
        foreach (range(1, 15) as $i) {
            $conv = Conversation::factory()->create([
                'company_id' => $this->company->id,
                'customer_id' => $customer->id,
                'platform_connection_id' => $this->connection->id,
                'last_message_at' => now()->subMinutes($i),
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        // Should return exactly 10 conversations
        $this->assertCount(10, $data);
    }

    /** @test */
    public function recent_conversations_orders_by_last_message_at()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        // Create conversations with different last_message_at times
        $conv1 = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
            'last_message_at' => now()->subMinutes(30),
        ]);

        $conv2 = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
            'last_message_at' => now()->subMinutes(5),
        ]);

        $conv3 = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
            'last_message_at' => now()->subMinutes(60),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        // Should be ordered by last_message_at descending (most recent first)
        $this->assertEquals($conv2->id, $data[0]['id']); // 5 minutes ago
        $this->assertEquals($conv1->id, $data[1]['id']); // 30 minutes ago
        $this->assertEquals($conv3->id, $data[2]['id']); // 60 minutes ago
    }

    /** @test */
    public function recent_conversations_only_returns_company_conversations()
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['current_company_id' => $otherCompany->id]);
        $otherUser->companies()->attach($otherCompany->id);

        $customer = Customer::factory()->create([
            'company_id' => $otherCompany->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        Conversation::factory()->create([
            'company_id' => $otherCompany->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        // Try to access as different company user
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        // Should not see other company's conversations
        $this->assertEmpty($data);
    }

    /** @test */
    public function recent_conversations_includes_customer_display_name()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
            'name' => 'John Doe',
            'platform_user_id' => '12345',
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        $this->assertEquals('John Doe', $data[0]['customer']['name']);
        $this->assertEquals('John Doe', $data[0]['customer']['display_name']);
    }

    /** @test */
    public function recent_conversations_includes_assigned_agent_when_present()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
            'assigned_to' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        $this->assertNotNull($data[0]['assigned_agent']);
        $this->assertEquals($this->user->id, $data[0]['assigned_agent']['id']);
        $this->assertEquals($this->user->name, $data[0]['assigned_agent']['name']);
    }

    /** @test */
    public function recent_conversations_returns_null_for_assigned_agent_when_not_assigned()
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
            'assigned_to' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        $this->assertNull($data[0]['assigned_agent']);
    }

    /** @test */
    public function recent_conversations_includes_last_message_content()
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

        Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'This is a test message content',
            'message_type' => 'text',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/recent-conversations');

        $response->assertOk();
        $data = $response->json();

        $this->assertEquals('This is a test message content', $data[0]['last_message']);
    }
}
