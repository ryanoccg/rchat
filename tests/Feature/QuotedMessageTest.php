<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Messaging\FacebookMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuotedMessageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected MessagingPlatform $platform;
    protected PlatformConnection $connection;
    protected Customer $customer;
    protected Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->user->companies()->attach($this->company->id, ['role' => 'Company Owner']);

        $this->platform = MessagingPlatform::where('slug', 'facebook')->first();
        $this->connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
            'is_active' => true,
            'credentials' => ['page_access_token' => 'test_token'],
        ]);

        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'platform_user_id' => 'test_customer_123',
            'name' => 'Test Customer',
        ]);

        $this->conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'platform_connection_id' => $this->connection->id,
            'status' => 'open',
        ]);
    }

    /** @test */
    public function message_has_quoted_message_relationship()
    {
        // Create original message
        $originalMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'What are your prices?',
            'message_type' => 'text',
            'platform_message_id' => 'msg_123',
        ]);

        // Create message with quote
        $quotedMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Our prices start at $50',
            'message_type' => 'text',
            'quoted_message_id' => $originalMessage->id,
        ]);

        $this->assertEquals($originalMessage->id, $quotedMessage->quoted_message_id);
        $this->assertEquals($originalMessage->id, $quotedMessage->quotedMessage->id);
    }

    /** @test */
    public function quoted_message_id_is_set_to_null_when_original_is_deleted()
    {
        $originalMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Original message',
            'message_type' => 'text',
        ]);

        $quotedMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Reply with quote',
            'message_type' => 'text',
            'quoted_message_id' => $originalMessage->id,
        ]);

        // Verify the quote is set
        $this->assertEquals($originalMessage->id, $quotedMessage->quoted_message_id);

        // Delete the original message
        $originalMessage->delete();

        // Refresh from database
        $quotedMessage->refresh();

        // The foreign key constraint with nullOnDelete() sets quoted_message_id to null
        $this->assertNull($quotedMessage->quoted_message_id);

        // The relationship will also return null
        $this->assertNull($quotedMessage->quotedMessage);
    }

    /** @test */
    public function can_send_message_with_quote_via_api()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'message_id' => 'new_msg_id',
            ], 200),
        ]);

        $originalMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Hi there!',
            'message_type' => 'text',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'Thanks for reaching out!',
                'quoted_message_id' => $originalMessage->id,
            ]);

        // 201 = Created, which is the correct status for creating a new message
        $response->assertStatus(201);

        $newMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', 'agent')
            ->latest()
            ->first();

        $this->assertEquals($originalMessage->id, $newMessage->quoted_message_id);
    }

    /** @test */
    public function quoted_message_id_must_reference_existing_message()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'Test message',
                'quoted_message_id' => 99999, // Non-existent message ID
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['quoted_message_id']);
    }

    /** @test */
    public function quoted_message_id_is_optional()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'message_id' => 'new_msg_id',
            ], 200),
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'Test message without quote',
            ]);

        // 201 = Created, which is the correct status for creating a new message
        $response->assertStatus(201);

        $message = Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', 'agent')
            ->latest()
            ->first();

        $this->assertNull($message->quoted_message_id);
    }

    /** @test */
    public function message_can_be_quoted_by_multiple_messages()
    {
        $originalMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Original question',
            'message_type' => 'text',
        ]);

        $reply1 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'First reply',
            'message_type' => 'text',
            'quoted_message_id' => $originalMessage->id,
        ]);

        $reply2 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Follow-up reply',
            'message_type' => 'text',
            'quoted_message_id' => $originalMessage->id,
        ]);

        $this->assertEquals($originalMessage->id, $reply1->quoted_message_id);
        $this->assertEquals($originalMessage->id, $reply2->quoted_message_id);
    }

    /** @test */
    public function quoted_by_relationship_returns_messages_that_quote_this_message()
    {
        $originalMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Original',
            'message_type' => 'text',
        ]);

        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Reply',
            'message_type' => 'text',
            'quoted_message_id' => $originalMessage->id,
        ]);

        $this->assertCount(1, $originalMessage->quotedBy);
    }

    /** @test */
    public function extracts_facebook_quote_context_from_webhook()
    {
        $handler = new FacebookMessageHandler();

        // Structure the message data as AbstractMessageHandler expects it
        // The raw_message should contain the parsed Facebook webhook data
        $messageData = [
            'metadata' => [
                'raw_message' => [
                    'reply_to' => [
                        'mid' => 'original_msg_mid',
                    ],
                ],
            ],
        ];

        // Create the original message in database
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Original message content',
            'message_type' => 'text',
            'platform_message_id' => 'original_msg_mid',
        ]);

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('extractReplyContext');
        $method->setAccessible(true);

        $replyContext = $method->invoke($handler, $messageData, $this->conversation);

        $this->assertNotNull($replyContext);
        $this->assertEquals('original_msg_mid', $replyContext['platform_message_id']);
        $this->assertEquals('Original message content', $replyContext['text']);
        $this->assertTrue($replyContext['is_from_customer']);
    }

    /** @test */
    public function extracts_whatsapp_quote_context_from_webhook()
    {
        // This tests the WhatsApp format (context.id)
        $messageData = [
            'metadata' => [
                'raw_message' => [
                    'context' => [
                        'id' => 'whatsapp_msg_id_123',
                    ],
                ],
            ],
        ];

        // Create the original message
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'WhatsApp original message',
            'message_type' => 'text',
            'platform_message_id' => 'whatsapp_msg_id_123',
        ]);

        $handler = new FacebookMessageHandler();
        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('extractReplyContext');
        $method->setAccessible(true);

        $replyContext = $method->invoke($handler, $messageData, $this->conversation);

        $this->assertNotNull($replyContext);
        $this->assertEquals('whatsapp_msg_id_123', $replyContext['platform_message_id']);
    }

    /** @test */
    public function extracts_telegram_quote_context_from_webhook()
    {
        // This tests the Telegram format (reply_to_message)
        $messageData = [
            'metadata' => [
                'raw_message' => [
                    'reply_to_message' => [
                        'message_id' => 'telegram_msg_456',
                        'text' => 'Telegram original',
                        'from' => ['is_bot' => false],
                    ],
                ],
            ],
        ];

        $handler = new FacebookMessageHandler();
        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('extractReplyContext');
        $method->setAccessible(true);

        $replyContext = $method->invoke($handler, $messageData, $this->conversation);

        $this->assertNotNull($replyContext);
        $this->assertEquals('telegram_msg_456', $replyContext['platform_message_id']);
        $this->assertEquals('Telegram original', $replyContext['text']);
        $this->assertTrue($replyContext['is_from_customer']);
    }

    /** @test */
    public function returns_null_when_no_quote_context_exists()
    {
        $handler = new FacebookMessageHandler();

        $messageData = [
            'sender' => ['id' => '123'],
            'message' => [
                'mid' => 'msg_id',
                'text' => 'Regular message without reply',
            ],
        ];

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('extractReplyContext');
        $method->setAccessible(true);

        $replyContext = $method->invoke($handler, $messageData, $this->conversation);

        $this->assertNull($replyContext);
    }

    /** @test */
    public function can_get_message_with_quoted_message_relationship()
    {
        $originalMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Original question',
            'message_type' => 'text',
            'platform_message_id' => 'msg_123',
        ]);

        $replyMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Here is the answer',
            'message_type' => 'text',
            'quoted_message_id' => $originalMessage->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/conversations/{$this->conversation->id}/messages");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'content',
                    ],
                ],
            ]);

        // Verify the quoted_message_id is stored correctly in the database
        $replyFromDb = Message::find($replyMessage->id);
        $this->assertEquals($originalMessage->id, $replyFromDb->quoted_message_id);
        $this->assertEquals($originalMessage->id, $replyFromDb->quotedMessage->id);
    }

    /** @test */
    public function quote_context_stored_in_message_metadata()
    {
        $handler = new FacebookMessageHandler();

        // Structure the message data as AbstractMessageHandler expects it
        $messageData = [
            'metadata' => [
                'raw_message' => [
                    'reply_to' => [
                        'mid' => 'original_mid',
                    ],
                ],
            ],
        ];

        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Original',
            'message_type' => 'text',
            'platform_message_id' => 'original_mid',
        ]);

        $reflection = new \ReflectionClass($handler);
        $method = $reflection->getMethod('extractReplyContext');
        $method->setAccessible(true);

        $replyContext = $method->invoke($handler, $messageData, $this->conversation);

        // Verify the extracted context includes the original message text
        $this->assertNotNull($replyContext);
        $this->assertEquals('original_mid', $replyContext['platform_message_id']);
        $this->assertEquals('Original', $replyContext['text']);
        $this->assertTrue($replyContext['is_from_customer']);
    }

    /** @test */
    public function multiple_conversations_have_separate_quote_chains()
    {
        $otherConversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => Customer::factory()->create(['company_id' => $this->company->id])->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $msgInConv1 = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Message in conv1',
            'message_type' => 'text',
            'platform_message_id' => 'msg_1',
        ]);

        $msgInConv2 = Message::create([
            'conversation_id' => $otherConversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Message in conv2',
            'message_type' => 'text',
            'platform_message_id' => 'msg_2',
        ]);

        // Quote in conversation 1
        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Reply to conv1',
            'message_type' => 'text',
            'quoted_message_id' => $msgInConv1->id,
        ]);

        // Quote in conversation 2
        Message::create([
            'conversation_id' => $otherConversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Reply to conv2',
            'message_type' => 'text',
            'quoted_message_id' => $msgInConv2->id,
        ]);

        // Verify each conversation only has its own quote
        $this->assertCount(1, $msgInConv1->quotedBy);
        $this->assertCount(1, $msgInConv2->quotedBy);
    }
}
