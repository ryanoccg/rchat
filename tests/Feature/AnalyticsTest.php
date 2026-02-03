<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PlatformConnection;
use App\Models\SatisfactionRating;
use App\Models\SentimentAnalysis;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('db:seed', ['--class' => 'MessagingPlatformSeeder']);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id);
    }

    public function test_can_get_analytics_overview(): void
    {
        // Create some test data
        $platformConnection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Conversation::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
            'status' => 'active',
        ]);

        Conversation::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
            'status' => 'closed',
            'is_ai_handling' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/overview');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'total_conversations',
                    'conversation_change',
                    'total_messages',
                    'total_customers',
                    'ai_handle_rate',
                    'avg_response_time',
                    'resolution_rate',
                    'period',
                ],
            ]);

        $this->assertEquals(8, $response->json('data.total_conversations'));
    }

    public function test_can_get_conversation_trends(): void
    {
        $platformConnection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Conversation::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/conversation-trends');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'period',
                'group_by',
            ]);
    }

    public function test_can_get_sentiment_trends(): void
    {
        $platformConnection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
        ]);

        SentimentAnalysis::create([
            'conversation_id' => $conversation->id,
            'sentiment' => 'positive',
            'score' => 0.85,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/sentiment');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'distribution',
                    'over_time',
                    'overall_score',
                ],
            ]);
    }

    public function test_can_get_satisfaction_data(): void
    {
        $platformConnection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $conversation = Conversation::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
        ]);

        SatisfactionRating::create([
            'company_id' => $this->company->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'rating' => 5,
            'feedback' => 'Great service!',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/satisfaction');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'avg_rating',
                    'total_ratings',
                    'distribution',
                    'over_time',
                    'nps',
                ],
            ]);

        $this->assertEquals(5, $response->json('data.avg_rating'));
    }

    public function test_can_get_platform_performance(): void
    {
        $platformConnection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Conversation::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/platform-performance');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'period',
            ]);
    }

    public function test_can_get_agent_performance(): void
    {
        $platformConnection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        // Create conversation assigned to user
        Conversation::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
            'assigned_to' => $this->user->id,
            'status' => 'closed',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/agent-performance');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'period',
            ]);
    }

    public function test_can_get_hourly_distribution(): void
    {
        $platformConnection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $customer = Customer::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Conversation::factory()->count(5)->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'platform_connection_id' => $platformConnection->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/hourly-distribution');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
                'period',
            ]);

        // Should have 24 hours
        $this->assertCount(24, $response->json('data'));
    }

    public function test_can_export_analytics_json(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/export?format=json');

        $response->assertOk()
            ->assertJsonStructure([
                'generated_at',
                'period',
                'conversations',
                'satisfaction',
            ]);
    }

    public function test_can_filter_by_period(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/overview?period=7');

        $response->assertOk();
        $this->assertEquals('7', $response->json('data.period'));

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/overview?period=90');

        $response->assertOk();
        $this->assertEquals('90', $response->json('data.period'));
    }

    public function test_cannot_see_other_company_analytics(): void
    {
        $otherCompany = Company::factory()->create();
        $otherPlatformConnection = PlatformConnection::factory()->create([
            'company_id' => $otherCompany->id,
        ]);
        $otherCustomer = Customer::factory()->create([
            'company_id' => $otherCompany->id,
        ]);

        Conversation::factory()->count(10)->create([
            'company_id' => $otherCompany->id,
            'customer_id' => $otherCustomer->id,
            'platform_connection_id' => $otherPlatformConnection->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/analytics/overview');

        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total_conversations'));
    }
}
