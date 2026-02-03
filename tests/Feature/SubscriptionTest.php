<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Subscription;
use App\Models\User;
use App\Models\UsageTracking;
use App\Services\Billing\SubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id);
    }

    public function test_can_get_available_plans(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/subscriptions/plans');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'free',
                    'starter',
                    'professional',
                    'enterprise',
                ],
            ]);
    }

    public function test_can_get_current_subscription(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'starter',
            'plan_type' => 'monthly',
            'status' => 'active',
            'message_limit' => 5000,
            'storage_limit' => 1024,
            'team_member_limit' => 3,
            'platform_limit' => 2,
            'price' => 29,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/subscriptions/current');

        $response->assertOk()
            ->assertJsonPath('data.subscription.plan_name', 'starter')
            ->assertJsonPath('data.subscription.status', 'active');
    }

    public function test_can_subscribe_to_free_plan(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/subscriptions/subscribe', [
                'plan' => 'free',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.plan_name', 'free')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $this->company->id,
            'plan_name' => 'free',
            'status' => 'active',
        ]);
    }

    public function test_can_subscribe_to_paid_plan_creates_trial(): void
    {
        // Without Stripe configured, creates a trial subscription
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/subscriptions/subscribe', [
                'plan' => 'professional',
                'plan_type' => 'monthly',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.plan_name', 'professional')
            ->assertJsonPath('data.status', 'trial');

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $this->company->id,
            'plan_name' => 'professional',
            'status' => 'trial',
        ]);
    }

    public function test_can_change_plan(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'starter',
            'plan_type' => 'monthly',
            'status' => 'active',
            'message_limit' => 5000,
            'storage_limit' => 1024,
            'team_member_limit' => 3,
            'platform_limit' => 2,
            'price' => 29,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/subscriptions/change-plan', [
                'plan' => 'professional',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.plan_name', 'professional');

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $this->company->id,
            'plan_name' => 'professional',
        ]);
    }

    public function test_can_cancel_subscription(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'status' => 'active',
            'message_limit' => 25000,
            'storage_limit' => 5120,
            'team_member_limit' => 10,
            'platform_limit' => 4,
            'price' => 79,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/subscriptions/cancel');

        $response->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $this->company->id,
            'status' => 'cancelling',
        ]);
    }

    public function test_can_cancel_subscription_immediately(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'status' => 'active',
            'message_limit' => 25000,
            'storage_limit' => 5120,
            'team_member_limit' => 10,
            'platform_limit' => 4,
            'price' => 79,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/subscriptions/cancel', [
                'immediately' => true,
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('subscriptions', [
            'company_id' => $this->company->id,
            'status' => 'cancelled',
        ]);
    }

    public function test_can_resume_cancelling_subscription(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'professional',
            'plan_type' => 'monthly',
            'status' => 'cancelling',
            'message_limit' => 25000,
            'storage_limit' => 5120,
            'team_member_limit' => 10,
            'platform_limit' => 4,
            'price' => 79,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/subscriptions/resume');

        $response->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_can_get_usage_statistics(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'starter',
            'plan_type' => 'monthly',
            'status' => 'active',
            'message_limit' => 5000,
            'storage_limit' => 1024,
            'team_member_limit' => 3,
            'platform_limit' => 2,
            'price' => 29,
        ]);

        UsageTracking::create([
            'company_id' => $this->company->id,
            'period_date' => now()->toDateString(),
            'message_count' => 100,
            'storage_used' => 50 * 1024 * 1024, // 50 MB in bytes
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/subscriptions/usage');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'usage',
                    'limits',
                    'percentage',
                ],
            ])
            ->assertJsonPath('data.usage.messages_sent', 100);
    }

    public function test_subscription_service_checks_message_limit(): void
    {
        $subscription = Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'free',
            'plan_type' => 'monthly',
            'status' => 'active',
            'message_limit' => 500,
            'storage_limit' => 100,
            'team_member_limit' => 1,
            'platform_limit' => 1,
            'price' => 0,
        ]);

        $service = new SubscriptionService();

        // Under limit
        $this->assertTrue($service->canSendMessage($this->company));

        // Create usage at limit
        UsageTracking::create([
            'company_id' => $this->company->id,
            'period_date' => now()->toDateString(),
            'message_count' => 500,
            'storage_used' => 0,
        ]);

        // At limit
        $this->assertFalse($service->canSendMessage($this->company));
    }

    public function test_subscription_service_checks_team_limit(): void
    {
        Subscription::create([
            'company_id' => $this->company->id,
            'plan_name' => 'free',
            'plan_type' => 'monthly',
            'status' => 'active',
            'message_limit' => 500,
            'storage_limit' => 100,
            'team_member_limit' => 1,
            'platform_limit' => 1,
            'price' => 0,
        ]);

        $service = new SubscriptionService();

        // Already has 1 user, limit is 1
        $this->assertFalse($service->canAddTeamMember($this->company));

        // Update to 3 team members
        $this->company->subscription->update(['team_member_limit' => 3]);
        $this->assertTrue($service->canAddTeamMember($this->company->fresh()));
    }

    public function test_cannot_access_other_company_subscription(): void
    {
        $otherCompany = Company::factory()->create();
        Subscription::create([
            'company_id' => $otherCompany->id,
            'plan_name' => 'enterprise',
            'plan_type' => 'monthly',
            'status' => 'active',
            'price' => 199,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/subscriptions/current');

        // Should get null since user's company doesn't have subscription
        $response->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_validation_requires_valid_plan(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/subscriptions/subscribe', [
                'plan' => 'invalid_plan',
            ]);

        $response->assertStatus(422);
    }
}
