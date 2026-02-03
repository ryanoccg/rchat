<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebChatTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;
    protected PlatformConnection $webchatConnection;
    protected MessagingPlatform $webchatPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        // Run platform seeder
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);

        $this->company = Company::factory()->create();
        $this->webchatPlatform = MessagingPlatform::where('slug', 'webchat')->first();

        $this->webchatConnection = PlatformConnection::create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->webchatPlatform->id,
            'platform_account_name' => 'Test Widget',
            'credentials' => [
                'widget_title' => 'Test Chat',
                'welcome_message' => 'Hello! How can we help?',
                'primary_color' => '#6366f1',
                'position' => 'bottom-right',
                'allowed_domains' => '*',
            ],
            'is_active' => true,
            'connected_at' => now(),
        ]);
    }

    public function test_can_init_webchat(): void
    {
        $response = $this->postJson('/api/webchat/init', [
            'widget_id' => (string) $this->webchatConnection->id,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'config' => [
                    'widget_id',
                    'visitor_id',
                    'title',
                    'welcome_message',
                    'primary_color',
                    'position',
                    'company_name',
                ],
                'messages',
            ]);

        $this->assertNotNull($response->json('config.visitor_id'));
    }

    public function test_init_with_existing_visitor_id(): void
    {
        $visitorId = 'test-visitor-123';

        $response = $this->postJson('/api/webchat/init', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'config' => [
                    'visitor_id' => $visitorId,
                ],
            ]);
    }

    public function test_init_fails_for_invalid_widget(): void
    {
        $response = $this->postJson('/api/webchat/init', [
            'widget_id' => 'invalid-widget-id',
        ]);

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_init_fails_for_inactive_widget(): void
    {
        $this->webchatConnection->update(['is_active' => false]);

        $response = $this->postJson('/api/webchat/init', [
            'widget_id' => (string) $this->webchatConnection->id,
        ]);

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_can_send_message(): void
    {
        $visitorId = 'test-visitor-' . uniqid();

        $response = $this->postJson('/api/webchat/messages', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
            'message' => 'Hello, I need help!',
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message' => [
                    'id',
                    'content',
                    'is_from_customer',
                    'sender_type',
                    'created_at',
                ],
                'conversation_id',
            ]);

        // Check that customer was created
        $this->assertDatabaseHas('customers', [
            'company_id' => $this->company->id,
            'platform_user_id' => $visitorId,
            'messaging_platform_id' => $this->webchatPlatform->id,
        ]);

        // Check that conversation was created
        $this->assertDatabaseHas('conversations', [
            'company_id' => $this->company->id,
        ]);

        // Check that message was created
        $this->assertDatabaseHas('messages', [
            'content' => 'Hello, I need help!',
            'is_from_customer' => true,
            'sender_type' => 'customer',
        ]);
    }

    public function test_send_message_with_visitor_info(): void
    {
        $visitorId = 'test-visitor-' . uniqid();

        $response = $this->postJson('/api/webchat/messages', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
            'message' => 'Hello!',
            'visitor_name' => 'John Doe',
            'visitor_email' => 'john@example.com',
        ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('customers', [
            'company_id' => $this->company->id,
            'platform_user_id' => $visitorId,
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);
    }

    public function test_can_poll_messages(): void
    {
        $visitorId = 'test-visitor-' . uniqid();

        // First send a message to create customer and conversation
        $this->postJson('/api/webchat/messages', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
            'message' => 'Hello!',
        ]);

        // Now poll for messages
        $response = $this->postJson('/api/webchat/poll', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'messages',
            ]);
    }

    public function test_poll_with_last_message_id(): void
    {
        $visitorId = 'test-visitor-' . uniqid();

        // Send a message
        $response = $this->postJson('/api/webchat/messages', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
            'message' => 'Hello!',
        ]);

        $lastMessageId = $response->json('message.id');

        // Poll with last_message_id
        $pollResponse = $this->postJson('/api/webchat/poll', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
            'last_message_id' => $lastMessageId,
        ]);

        $pollResponse->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    public function test_can_get_widget_script(): void
    {
        $response = $this->get("/api/webchat/widget/{$this->webchatConnection->id}.js");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'application/javascript');
    }

    public function test_widget_script_returns_404_for_invalid_widget(): void
    {
        $response = $this->get('/api/webchat/widget/invalid-id.js');

        $response->assertStatus(404);
    }

    public function test_widget_script_returns_404_for_inactive_widget(): void
    {
        $this->webchatConnection->update(['is_active' => false]);

        $response = $this->get("/api/webchat/widget/{$this->webchatConnection->id}.js");

        $response->assertStatus(404);
    }

    public function test_conversation_continues_with_same_visitor(): void
    {
        $visitorId = 'test-visitor-' . uniqid();

        // First message
        $response1 = $this->postJson('/api/webchat/messages', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
            'message' => 'First message',
        ]);

        $conversationId1 = $response1->json('conversation_id');

        // Second message
        $response2 = $this->postJson('/api/webchat/messages', [
            'widget_id' => (string) $this->webchatConnection->id,
            'visitor_id' => $visitorId,
            'message' => 'Second message',
        ]);

        $conversationId2 = $response2->json('conversation_id');

        // Should be same conversation
        $this->assertEquals($conversationId1, $conversationId2);

        // Should have 2 messages in conversation
        $conversation = Conversation::find($conversationId1);
        $this->assertNotNull($conversation);
        $this->assertEquals(2, $conversation->messages()->where('is_from_customer', true)->count());
    }
}
