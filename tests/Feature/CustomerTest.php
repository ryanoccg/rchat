<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected MessagingPlatform $platform;
    protected PlatformConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id);

        $this->platform = MessagingPlatform::factory()->create([
            'name' => 'WhatsApp',
            'slug' => 'whatsapp',
        ]);

        $this->connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);
    }

    public function test_can_list_customers(): void
    {
        Customer::factory()->count(3)->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_cannot_see_other_company_customers(): void
    {
        $otherCompany = Company::factory()->create();

        Customer::factory()->create([
            'company_id' => $otherCompany->id,
            'name' => 'Other Company Customer',
        ]);

        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'My Customer',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'My Customer'])
            ->assertJsonMissing(['name' => 'Other Company Customer']);
    }

    public function test_can_search_customers_by_name(): void
    {
        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'John Doe',
        ]);

        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Jane Smith',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers?search=john');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'John Doe']);
    }

    public function test_can_search_customers_by_email(): void
    {
        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'John',
            'email' => 'john@example.com',
        ]);

        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Jane',
            'email' => 'jane@test.com',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers?search=example.com');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'John']);
    }

    public function test_can_filter_customers_by_platform(): void
    {
        $otherPlatform = MessagingPlatform::factory()->create([
            'name' => 'Telegram',
            'slug' => 'telegram',
        ]);

        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'WhatsApp Customer',
            'messaging_platform_id' => $this->platform->id,
        ]);

        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Telegram Customer',
            'messaging_platform_id' => $otherPlatform->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers?platform=' . $this->platform->id);

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => 'WhatsApp Customer']);
    }

    public function test_can_create_customer(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/customers', [
                'name' => 'New Customer',
                'email' => 'new@example.com',
                'phone' => '+1234567890',
                'language' => 'en',
                'platform_user_id' => 'platform_123',
                'messaging_platform_id' => $this->platform->id,
            ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'New Customer',
                'email' => 'new@example.com',
            ]);

        $this->assertDatabaseHas('customers', [
            'company_id' => $this->company->id,
            'name' => 'New Customer',
            'email' => 'new@example.com',
        ]);
    }

    public function test_cannot_create_customer_without_platform_info(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/customers', [
                'name' => 'Test Customer',
                'email' => 'test@example.com',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['platform_user_id', 'messaging_platform_id']);
    }

    public function test_can_view_customer_details(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Test Customer',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers/' . $customer->id);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Test Customer']);
    }

    public function test_cannot_view_other_company_customer(): void
    {
        $otherCompany = Company::factory()->create();
        $customer = Customer::factory()->create([
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers/' . $customer->id);

        $response->assertNotFound();
    }

    public function test_can_update_customer(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Original Name',
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/customers/' . $customer->id, [
                'name' => 'Updated Name',
                'email' => 'updated@example.com',
            ]);

        $response->assertOk()
            ->assertJsonFragment(['name' => 'Updated Name']);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Updated Name',
        ]);
    }

    public function test_can_delete_customer_without_conversations(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/customers/' . $customer->id);

        $response->assertOk();

        $this->assertDatabaseMissing('customers', [
            'id' => $customer->id,
        ]);
    }

    public function test_cannot_delete_customer_with_conversations(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson('/api/customers/' . $customer->id);

        $response->assertStatus(422);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
        ]);
    }

    public function test_can_update_customer_notes(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'metadata' => ['tags' => ['vip']],
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/customers/' . $customer->id . '/notes', [
                'notes' => 'This is a VIP customer who needs special attention.',
            ]);

        $response->assertOk();

        $customer->refresh();
        $this->assertEquals('This is a VIP customer who needs special attention.', $customer->metadata['notes']);
        $this->assertEquals(['vip'], $customer->metadata['tags']); // tags should be preserved
    }

    public function test_can_update_customer_tags(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
            'metadata' => ['notes' => 'Important customer'],
        ]);

        $response = $this->actingAs($this->user)
            ->putJson('/api/customers/' . $customer->id . '/tags', [
                'tags' => ['vip', 'priority'],
            ]);

        $response->assertOk();

        $customer->refresh();
        $this->assertEquals(['vip', 'priority'], $customer->metadata['tags']);
        $this->assertEquals('Important customer', $customer->metadata['notes']); // notes should be preserved
    }

    public function test_can_get_customer_conversations(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Conversation::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers/' . $customer->id . '/conversations');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    public function test_can_get_customer_stats(): void
    {
        // Create customers
        Customer::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'messaging_platform_id' => $this->platform->id,
        ]);

        // Create some new customers this month
        Customer::factory()->count(2)->create([
            'company_id' => $this->company->id,
            'created_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers-stats');

        $response->assertOk()
            ->assertJsonStructure([
                'total',
                'new_this_month',
                'active_last_30_days',
                'by_platform',
            ]);
    }

    public function test_can_get_all_tags(): void
    {
        Customer::factory()->create([
            'company_id' => $this->company->id,
            'metadata' => ['tags' => ['vip', 'priority']],
        ]);

        Customer::factory()->create([
            'company_id' => $this->company->id,
            'metadata' => ['tags' => ['support', 'vip']],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers-tags');

        $response->assertOk()
            ->assertJsonStructure(['tags']);

        $tags = $response->json('tags');
        $this->assertContains('vip', $tags);
        $this->assertContains('priority', $tags);
        $this->assertContains('support', $tags);
    }

    public function test_customers_include_conversation_count(): void
    {
        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Conversation::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $this->connection->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers');

        $response->assertOk()
            ->assertJsonPath('data.0.conversations_count', 5);
    }

    public function test_can_sort_customers_by_name(): void
    {
        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Zebra Company',
        ]);

        Customer::factory()->create([
            'company_id' => $this->company->id,
            'name' => 'Alpha Corp',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers?sort_by=name&sort_order=asc');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals('Alpha Corp', $data[0]['name']);
        $this->assertEquals('Zebra Company', $data[1]['name']);
    }

    public function test_pagination_works(): void
    {
        Customer::factory()->count(20)->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/customers?per_page=10');

        $response->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('total', 20)
            ->assertJsonPath('last_page', 2);
    }
}
