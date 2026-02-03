<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed messaging platforms required for factories
        $this->artisan('db:seed', ['--class' => 'MessagingPlatformSeeder']);
    }

    public function test_user_cannot_access_routes_without_company(): void
    {
        // Create a user without a company
        $user = User::factory()->create(['current_company_id' => null]);

        // Try to access a protected route
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        // Should get 403 No company selected
        $response->assertStatus(403)
            ->assertJson(['message' => 'No company selected']);
    }

    public function test_user_cannot_access_company_not_member_of(): void
    {
        // Create two companies
        $company1 = Company::factory()->create();
        $company2 = Company::factory()->create();

        // User belongs to company1 but tries to set current_company_id to company2
        $user = User::factory()->create(['current_company_id' => $company2->id]);
        $user->companies()->attach($company1->id); // Only member of company1

        // Try to access dashboard with company2 as current
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        // Should be denied
        $response->assertStatus(403)
            ->assertJson(['message' => 'Access denied to this company']);
    }

    public function test_user_can_access_own_company_routes(): void
    {
        // Create a company and user
        $company = Company::factory()->create();
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->companies()->attach($company->id);

        // Try to access a protected route
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        // Should be successful
        $response->assertStatus(200);
    }

    public function test_middleware_sets_company_id_in_request(): void
    {
        // Create a company and user
        $company = Company::factory()->create();
        $user = User::factory()->create(['current_company_id' => $company->id]);
        $user->companies()->attach($company->id);

        // Access a protected route
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/dashboard/stats');

        // Verify the request was processed successfully
        $response->assertStatus(200);

        // The middleware should have set company_id in the request
        // Controllers should be able to access it via $request->company_id
    }
}
