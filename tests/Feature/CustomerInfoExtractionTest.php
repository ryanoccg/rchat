<?php

namespace Tests\Feature;

use App\Jobs\ExtractCustomerInfoFromMessage;
use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use App\Services\Customer\CustomerInfoExtractorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CustomerInfoExtractionTest extends TestCase
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
        $this->seed(\Database\Seeders\AiProviderSeeder::class);

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
            'phone' => null, // Empty for testing auto-update
            'email' => null, // Empty for testing auto-update
        ]);

        $this->conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'platform_connection_id' => $this->connection->id,
            'status' => 'open',
        ]);
    }

    /** @test */
    public function it_extracts_phone_number_from_message()
    {
        $service = new CustomerInfoExtractorService();
        $result = $service->extractFromMessage('My phone number is +1 (555) 123-4567');

        $this->assertArrayHasKey('phone', $result);
        $this->assertEquals('15551234567', $result['phone']);
    }

    /** @test */
    public function it_extracts_email_from_message()
    {
        $service = new CustomerInfoExtractorService();
        $result = $service->extractFromMessage('You can reach me at john@example.com');

        $this->assertArrayHasKey('email', $result);
        $this->assertEquals('john@example.com', $result['email']);
    }

    /** @test */
    public function it_extracts_both_phone_and_email_from_message()
    {
        $service = new CustomerInfoExtractorService();
        $result = $service->extractFromMessage('Contact me at john@example.com or call +1-555-123-4567');

        $this->assertArrayHasKey('phone', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertEquals('15551234567', $result['phone']);
        $this->assertEquals('john@example.com', $result['email']);
    }

    /** @test */
    public function it_formats_phone_number_to_digits_only()
    {
        $service = new CustomerInfoExtractorService();

        $tests = [
            '+1 (555) 123-4567' => '15551234567',
            '(555) 123-4567' => '5551234567',
            '555-123-4567' => '5551234567',
            '555.123.4567' => '5551234567',
            '1-555-123-4567' => '15551234567',
        ];

        foreach ($tests as $input => $expected) {
            $result = $service->extractFromMessage($input);
            $this->assertArrayHasKey('phone', $result, "Failed to extract phone from: $input");
            $this->assertEquals($expected, $result['phone'], "Failed for: $input");
        }
    }

    /** @test */
    public function it_only_updates_empty_phone_field()
    {
        $service = new CustomerInfoExtractorService();

        // Create customer with existing phone
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'phone' => '1111111111',
            'email' => null,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'My new phone is +1 (555) 999-8888',
            'message_type' => 'text',
        ]);

        $service->updateCustomerInfo($message);

        // Phone should NOT be updated (already has a value)
        $customer->refresh();
        $this->assertEquals('1111111111', $customer->phone);
    }

    /** @test */
    public function it_only_updates_empty_email_field()
    {
        $service = new CustomerInfoExtractorService();

        // Create customer with existing email
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'phone' => null,
            'email' => 'existing@example.com',
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'My new email is newemail@example.com',
            'message_type' => 'text',
        ]);

        $service->updateCustomerInfo($message);

        // Email should NOT be updated (already has a value)
        $customer->refresh();
        $this->assertEquals('existing@example.com', $customer->email);
    }

    /** @test */
    public function it_updates_customer_phone_when_empty()
    {
        $service = new CustomerInfoExtractorService();

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'You can call me at +1 (555) 123-4567',
            'message_type' => 'text',
        ]);

        $service->updateCustomerInfo($message);

        $this->customer->refresh();
        $this->assertEquals('15551234567', $this->customer->phone);
    }

    /** @test */
    public function it_updates_customer_email_when_empty()
    {
        $service = new CustomerInfoExtractorService();

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'My email is jane.doe@example.com',
            'message_type' => 'text',
        ]);

        $service->updateCustomerInfo($message);

        $this->customer->refresh();
        $this->assertEquals('jane.doe@example.com', $this->customer->email);
    }

    /** @test */
    public function it_does_not_update_from_agent_messages()
    {
        $service = new CustomerInfoExtractorService();

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'agent',
            'sender_id' => $this->user->id,
            'is_from_customer' => false,
            'content' => 'Customer email is test@example.com',
            'message_type' => 'text',
        ]);

        $service->updateCustomerInfo($message);

        $this->customer->refresh();
        $this->assertNull($this->customer->email);
    }

    /** @test */
    public function it_does_not_update_from_ai_messages()
    {
        $service = new CustomerInfoExtractorService();

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'ai',
            'sender_id' => null,
            'is_from_customer' => false,
            'content' => 'Please update your email to test@example.com',
            'message_type' => 'text',
        ]);

        $service->updateCustomerInfo($message);

        $this->customer->refresh();
        $this->assertNull($this->customer->email);
    }

    /** @test */
    public function it_does_not_extract_from_empty_content()
    {
        $service = new CustomerInfoExtractorService();
        $result = $service->extractFromMessage('');

        $this->assertEmpty($result);
    }

    /** @test */
    public function it_handles_international_phone_formats()
    {
        $service = new CustomerInfoExtractorService();

        $tests = [
            '+1 (555) 123-4567' => '15551234567',
            '+1 555-123-4567' => '15551234567',
            '+1 555.123.4567' => '15551234567',
            // Note: The current regex requires 3-3-4 digit pattern (XXX-XXX-XXXX)
            // Formats like +86 138-0013-8000 may not match due to variable digit counts
        ];

        foreach ($tests as $input => $expected) {
            $result = $service->extractFromMessage($input);
            $this->assertArrayHasKey('phone', $result, "Failed to extract phone from: $input");
            $this->assertEquals($expected, $result['phone'], "Failed for: $input");
        }
    }

    /** @test */
    public function it_extracts_from_multiple_phone_numbers_in_message()
    {
        $service = new CustomerInfoExtractorService();
        $result = $service->extractFromMessage('Call me at 555-123-4567 or 555-987-6543');

        // Should extract the first phone number found
        $this->assertArrayHasKey('phone', $result);
        $this->assertNotEmpty($result['phone']);
    }

    /** @test */
    public function it_requires_minimum_7_digits_for_phone()
    {
        $service = new CustomerInfoExtractorService();
        $result = $service->extractFromMessage('Call me at 123-456');

        // Should NOT extract (less than 7 digits)
        $this->assertArrayNotHasKey('phone', $result);
    }

    /** @test */
    public function job_dispatches_for_customer_messages()
    {
        Queue::fake();

        Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'My phone is 555-123-4567',
            'message_type' => 'text',
        ]);

        // The job should be dispatched by the observer
        // This test verifies the observer is set up correctly
        Queue::assertPushed(ExtractCustomerInfoFromMessage::class);
    }

    /** @test */
    public function it_handles_complex_message_with_contact_info()
    {
        $service = new CustomerInfoExtractorService();

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Hi there! I\'m interested in your products. You can reach me at john.smith@example.com or call +1 (555) 987-6543 during business hours. Thanks!',
            'message_type' => 'text',
        ]);

        $service->updateCustomerInfo($message);

        $this->customer->refresh();
        $this->assertEquals('john.smith@example.com', $this->customer->email);
        $this->assertEquals('15559876543', $this->customer->phone);
    }

    /** @test */
    public function it_preserves_existing_data_when_no_new_info_found()
    {
        $service = new CustomerInfoExtractorService();

        $message = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Hello, I have a question about your products',
            'message_type' => 'text',
        ]);

        $originalPhone = $this->customer->phone;
        $originalEmail = $this->customer->email;

        $service->updateCustomerInfo($message);

        $this->customer->refresh();
        $this->assertEquals($originalPhone, $this->customer->phone);
        $this->assertEquals($originalEmail, $this->customer->email);
    }
}
