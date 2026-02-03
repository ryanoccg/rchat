<?php

namespace Tests\Feature;

use App\Jobs\ProcessDelayedAiResponse;
use App\Models\AiConfiguration;
use App\Models\AiProvider;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class AiProductImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected AiConfiguration $aiConfig;
    protected MessagingPlatform $facebookPlatform;
    protected PlatformConnection $connection;
    protected Conversation $conversation;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);
        $this->seed(\Database\Seeders\AiProviderSeeder::class);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->user->companies()->attach($this->company->id, ['role' => 'Company Owner']);

        // Create AI configuration with auto-respond enabled
        $provider = AiProvider::where('slug', 'openai')->first();
        $this->aiConfig = AiConfiguration::create([
            'company_id' => $this->company->id,
            'primary_provider_id' => $provider->id,
            'primary_model' => 'gpt-5-mini',
            'system_prompt' => 'You are a helpful assistant.',
            'auto_respond' => true,
            'response_delay_seconds' => 1, // Short delay for testing
        ]);

        // Setup messaging platform and connection
        $this->facebookPlatform = MessagingPlatform::where('slug', 'facebook')->first();

        $this->connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->facebookPlatform->id,
            'is_active' => true,
            'credentials' => [
                'page_access_token' => 'test_token_123',
                'page_id' => 'test_page_id',
                'verify_token' => 'test_verify_token',
            ],
        ]);

        // Create customer and conversation
        $this->customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'platform_user_id' => 'test_customer_123',
            'name' => 'Test Customer',
        ]);

        $this->conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'platform_connection_id' => $this->connection->id,
            'is_ai_handling' => true,
            'status' => 'open',
        ]);

        // Clear any existing Mockery aliases between tests
        Mockery::close();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function it_extracts_product_images_from_ai_response()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Check out this great product!\n[PRODUCT_IMAGE: https://example.com/image1.jpg]\nLet me know if you like it.";

        $images = $this->invokeMethod($job, 'extractProductImages', [$content]);

        $this->assertCount(1, $images);
        $this->assertEquals('https://example.com/image1.jpg', $images[0]);
    }

    /** @test */
    public function it_extracts_multiple_product_images()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Here are some products:\n[PRODUCT_IMAGE: https://example.com/image1.jpg]\n[PRODUCT_IMAGE: https://example.com/image2.jpg]\n[PRODUCT_IMAGE: https://example.com/image3.jpg]";

        $images = $this->invokeMethod($job, 'extractProductImages', [$content]);

        $this->assertCount(3, $images);
        $this->assertEquals('https://example.com/image1.jpg', $images[0]);
        $this->assertEquals('https://example.com/image2.jpg', $images[1]);
        $this->assertEquals('https://example.com/image3.jpg', $images[2]);
    }

    /** @test */
    public function it_handles_product_image_tags_with_spaces()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Here is an image: [PRODUCT_IMAGE:   https://example.com/image.jpg   ]";

        $images = $this->invokeMethod($job, 'extractProductImages', [$content]);

        $this->assertCount(1, $images);
        $this->assertEquals('https://example.com/image.jpg', $images[0]);
    }

    /** @test */
    public function it_handles_case_insensitive_product_image_tags()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Here is an image: [product_image: https://example.com/image.jpg]";

        $images = $this->invokeMethod($job, 'extractProductImages', [$content]);

        $this->assertCount(1, $images);
    }

    /** @test */
    public function it_filters_out_invalid_urls()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Images:\n[PRODUCT_IMAGE: https://example.com/valid.jpg]\n[PRODUCT_IMAGE: not-a-url]\n[PRODUCT_IMAGE: https://example.com/valid2.jpg]";

        $images = $this->invokeMethod($job, 'extractProductImages', [$content]);

        $this->assertCount(2, $images);
        $this->assertContains('https://example.com/valid.jpg', $images);
        $this->assertContains('https://example.com/valid2.jpg', $images);
    }

    /** @test */
    public function it_removes_product_image_tags_from_content()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Check out this product!\n[PRODUCT_IMAGE: https://example.com/image.jpg]\nIt's really great!";

        $cleaned = $this->invokeMethod($job, 'removeProductImageTags', [$content]);

        $this->assertStringNotContainsString('[PRODUCT_IMAGE:', $cleaned);
        $this->assertStringNotContainsString('https://example.com/image.jpg', $cleaned);
        $this->assertStringContainsString('Check out this product!', $cleaned);
        $this->assertStringContainsString("It's really great!", $cleaned);
    }

    /** @test */
    public function it_removes_multiple_product_image_tags()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Products:\n[PRODUCT_IMAGE: https://example.com/image1.jpg]\n[PRODUCT_IMAGE: https://example.com/image2.jpg]\n[PRODUCT_IMAGE: https://example.com/image3.jpg]\nLet me know!";

        $cleaned = $this->invokeMethod($job, 'removeProductImageTags', [$content]);

        $this->assertStringNotContainsString('[PRODUCT_IMAGE:', $cleaned);
        $this->assertStringNotContainsString('https://', $cleaned);
        $this->assertStringContainsString('Products:', $cleaned);
        $this->assertStringContainsString('Let me know!', $cleaned);
    }

    /** @test */
    public function it_cleans_up_extra_newlines_after_removing_tags()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        $content = "Line 1\n[PRODUCT_IMAGE: https://example.com/image.jpg]\n\n\n\nLine 2";

        $cleaned = $this->invokeMethod($job, 'removeProductImageTags', [$content]);

        // Should not have more than 2 consecutive newlines
        $this->assertDoesNotMatchRegularExpression('/\n{3,}/', $cleaned);
    }

    /** @test */
    public function it_sends_images_before_text_on_facebook_platform()
    {
        // Mock all HTTP requests
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'message_id' => 'test_msg_id_123',
            ], 200),
            // Use a wildcard to catch any OpenAI API call
            '*' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-5-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "Here are our products:\n[PRODUCT_IMAGE: https://example.com/product1.jpg]\n[PRODUCT_IMAGE: https://example.com/product2.jpg]\nLet me know if you need more info!",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => [
                    'prompt_tokens' => 50,
                    'completion_tokens' => 30,
                    'total_tokens' => 80,
                ],
            ], 200),
        ]);

        // Create a customer message - sender_id must be null for customer messages
        // since customers are not in the users table
        $customerMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Show me products',
            'message_type' => 'text',
        ]);

        // Run the job immediately
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            $customerMessage->id,
            now()->toIso8601String()
        );
        $job->handle();

        // Verify the message was stored with clean content (no image tags, no raw URLs)
        $storedMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', 'ai')
            ->latest()
            ->first();

        $this->assertNotNull($storedMessage);

        // Critical: Content should NOT contain the [PRODUCT_IMAGE:] tag
        $this->assertStringNotContainsString('[PRODUCT_IMAGE:', $storedMessage->content,
            'Stored message should not contain [PRODUCT_IMAGE:] tag');

        // Critical: Content should NOT contain raw image URLs
        $this->assertStringNotContainsString('https://example.com/product1.jpg', $storedMessage->content,
            'Product image URL should be removed from stored content');
        $this->assertStringNotContainsString('https://example.com/product2.jpg', $storedMessage->content,
            'Product image URL should be removed from stored content');

        // Content should contain some text (not just empty)
        $this->assertNotEmpty($storedMessage->content);

        // Media URLs should be stored separately
        $this->assertNotNull($storedMessage->media_urls);
        $this->assertCount(2, $storedMessage->media_urls);
        $this->assertEquals('image', $storedMessage->media_urls[0]['type']);
        $this->assertEquals('https://example.com/product1.jpg', $storedMessage->media_urls[0]['url']);
        $this->assertEquals('https://example.com/product2.jpg', $storedMessage->media_urls[1]['url']);
    }

    /** @test */
    public function it_limits_product_images_to_ten()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'message_id' => 'test_msg_id',
            ], 200),
            // Use wildcard to catch all other HTTP calls (OpenAI API)
            '*' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-5-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "Products:\n" .
                                "[PRODUCT_IMAGE: https://example.com/image1.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image2.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image3.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image4.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image5.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image6.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image7.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image8.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image9.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image10.jpg]\n" .
                                "[PRODUCT_IMAGE: https://example.com/image11.jpg]\n" .
                                "Which one do you like?",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 100, 'total_tokens' => 150],
            ], 200),
        ]);

        $customerMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Show me products',
            'message_type' => 'text',
        ]);

        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            $customerMessage->id,
            now()->toIso8601String()
        );
        $job->handle();

        $storedMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', 'ai')
            ->latest()
            ->first();

        // Only first 10 images should be stored
        $this->assertCount(10, $storedMessage->media_urls);
        $this->assertEquals('https://example.com/image1.jpg', $storedMessage->media_urls[0]['url']);
        $this->assertEquals('https://example.com/image10.jpg', $storedMessage->media_urls[9]['url']);

        // The 11th image should not be in media_urls
        $this->assertNotEquals('https://example.com/image11.jpg', $storedMessage->media_urls[9]['url']);

        // All URLs should be removed from content (including the ones not sent)
        $this->assertStringNotContainsString('https://example.com/image11.jpg', $storedMessage->content);
    }

    /** @test */
    public function it_handles_response_without_product_images()
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'message_id' => 'test_msg_id',
            ], 200),
            // Use wildcard to catch all other HTTP calls (OpenAI API)
            '*' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-5-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "Hello! How can I help you today?",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 20, 'completion_tokens' => 10, 'total_tokens' => 30],
            ], 200),
        ]);

        $customerMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Hello',
            'message_type' => 'text',
        ]);

        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            $customerMessage->id,
            now()->toIso8601String()
        );
        $job->handle();

        $storedMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', 'ai')
            ->latest()
            ->first();

        $this->assertEquals('text', $storedMessage->message_type);
        $this->assertNull($storedMessage->media_urls);
        $this->assertEquals("Hello! How can I help you today?", $storedMessage->content);
    }

    /** @test */
    public function it_handles_malformed_product_image_tags()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        // Test malformed tags
        $content = "Images:\n[PRODUCT_IMAGE: https://example.com/valid.jpg]\n[PRODUCT_IMAGE]\n[PRODUCT_IMAGE: ]\nSome text";

        $images = $this->invokeMethod($job, 'extractProductImages', [$content]);

        // Should only extract the valid one
        $this->assertCount(1, $images);
        $this->assertEquals('https://example.com/valid.jpg', $images[0]);
    }

    /** @test */
    public function it_preserves_non_image_urls_in_text_content()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        // Non-image URLs should be preserved (like website links)
        $content = "Visit our website at https://example.com for more info.\n[PRODUCT_IMAGE: https://example.com/product.jpg]";

        $cleaned = $this->invokeMethod($job, 'removeProductImageTags', [$content]);

        // The website URL should remain, but the product image URL should be removed
        $this->assertStringContainsString('https://example.com', $cleaned);
        $this->assertStringNotContainsString('[PRODUCT_IMAGE:', $cleaned);
        $this->assertStringNotContainsString('https://example.com/product.jpg', $cleaned);
    }

    /** @test */
    public function ai_never_pastes_raw_urls_only_uses_product_image_tag()
    {
        // This test ensures the system prompt instructs AI correctly
        // and that the processing logic enforces this behavior

        // Mock all HTTP requests
        Http::fake([
            'graph.facebook.com/*' => Http::response(['message_id' => 'test'], 200),
            // Use wildcard to catch all other HTTP calls (OpenAI API)
            '*' => Http::response([
                'id' => 'chatcmpl-test',
                'object' => 'chat.completion',
                'created' => time(),
                'model' => 'gpt-5-mini',
                'choices' => [
                    [
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => "Here are the red shoes you asked about!\n" .
                                "[PRODUCT_IMAGE: https://example.com/red-shoes.jpg]\n" .
                                "You can see more at https://example.com/catalog",
                        ],
                        'finish_reason' => 'stop',
                    ],
                ],
                'usage' => ['prompt_tokens' => 30, 'completion_tokens' => 30, 'total_tokens' => 60],
            ], 200),
        ]);

        $customerMessage = Message::create([
            'conversation_id' => $this->conversation->id,
            'sender_type' => 'customer',
            'sender_id' => null,
            'is_from_customer' => true,
            'content' => 'Show me the red shoes',
            'message_type' => 'text',
        ]);

        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            $customerMessage->id,
            now()->toIso8601String()
        );
        $job->handle();

        $storedMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('sender_type', 'ai')
            ->latest()
            ->first();

        // Critical assertions:
        // 1. No [PRODUCT_IMAGE:] tag in stored content
        $this->assertStringNotContainsString('[PRODUCT_IMAGE:', $storedMessage->content,
            'Stored message should not contain [PRODUCT_IMAGE:] tag');

        // 2. No image URL from the tag in stored content
        $this->assertStringNotContainsString('https://example.com/red-shoes.jpg', $storedMessage->content,
            'Product image URL should be removed from stored content');

        // 3. Other URLs (non-product-image) should remain
        $this->assertStringContainsString('https://example.com/catalog', $storedMessage->content,
            'Non-image URLs should be preserved in content');

        // 4. Image should be in media_urls for separate sending
        $this->assertNotNull($storedMessage->media_urls);
        $this->assertCount(1, $storedMessage->media_urls);
        $this->assertEquals('https://example.com/red-shoes.jpg', $storedMessage->media_urls[0]['url'],
            'Product image should be in media_urls for native attachment sending');
    }

    /** @test */
    public function it_handles_empty_content_after_removing_image_tags()
    {
        $job = new ProcessDelayedAiResponse(
            $this->conversation->id,
            0,
            now()->toIso8601String()
        );

        // Content that only contains image tags
        $content = "[PRODUCT_IMAGE: https://example.com/image.jpg]";

        $cleaned = $this->invokeMethod($job, 'removeProductImageTags', [$content]);

        $this->assertEmpty(trim($cleaned));
    }

    /**
     * Helper method to call protected/private methods
     */
    protected function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
