<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MessagingPlatform;
use App\Models\PlatformConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformConnectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('db:seed', ['--class' => 'MessagingPlatformSeeder']);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['current_company_id' => $this->company->id]);
        $this->user->companies()->attach($this->company->id);
    }

    public function test_can_list_platforms(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/platforms');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'slug', 'display_name', 'config_fields'],
                ],
            ]);

        $this->assertCount(5, $response->json('data'));
    }

    public function test_can_create_platform_connection(): void
    {
        $platform = MessagingPlatform::where('slug', 'telegram')->first();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/platform-connections', [
                'messaging_platform_id' => $platform->id,
                'platform_account_name' => 'My Telegram Bot',
                'credentials' => [
                    'bot_token' => '123456:ABC-DEF',
                    'bot_username' => 'mybot',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'data' => [
                    'id',
                    'company_id',
                    'messaging_platform_id',
                    'platform_account_name',
                    'webhook_config',
                    'is_active',
                ],
            ]);

        $this->assertDatabaseHas('platform_connections', [
            'company_id' => $this->company->id,
            'messaging_platform_id' => $platform->id,
            'platform_account_name' => 'My Telegram Bot',
        ]);
    }

    public function test_can_list_platform_connections(): void
    {
        PlatformConnection::factory()->count(3)->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/platform-connections');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_can_update_platform_connection(): void
    {
        $connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'platform_account_name' => 'Old Name',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/platform-connections/{$connection->id}", [
                'platform_account_name' => 'New Name',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('platform_connections', [
            'id' => $connection->id,
            'platform_account_name' => 'New Name',
        ]);
    }

    public function test_can_delete_platform_connection(): void
    {
        $connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/platform-connections/{$connection->id}");

        $response->assertStatus(200);

        $this->assertDatabaseMissing('platform_connections', [
            'id' => $connection->id,
        ]);
    }

    public function test_can_toggle_connection_status(): void
    {
        $connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson("/api/platform-connections/{$connection->id}/toggle");

        $response->assertStatus(200);

        $connection->refresh();
        $this->assertFalse($connection->is_active);
    }

    public function test_cannot_access_other_company_connections(): void
    {
        $otherCompany = Company::factory()->create();
        $connection = PlatformConnection::factory()->create([
            'company_id' => $otherCompany->id,
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/platform-connections/{$connection->id}");

        $response->assertStatus(404);
    }
}
