<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Broadcast;
use App\Models\BroadcastRecipient;
use App\Models\PlatformConnection;
use App\Jobs\SendBroadcast;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;
    protected PlatformConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed required data
        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id, ['role' => 'Company Admin']);

        $this->connection = PlatformConnection::factory()->create([
            'company_id' => $this->company->id,
        ]);

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function it_can_list_broadcasts()
    {
        Broadcast::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson('/api/broadcasts?company_id=' . $this->company->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function it_can_create_broadcast()
    {
        $broadcastData = [
            'company_id' => $this->company->id,
            'name' => 'Test Broadcast',
            'message' => 'Hello customers!',
            'platform_connection_id' => $this->connection->id,
        ];

        $response = $this->postJson('/api/broadcasts', $broadcastData);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Broadcast created successfully',
            ]);

        $this->assertDatabaseHas('broadcasts', [
            'name' => 'Test Broadcast',
            'company_id' => $this->company->id,
        ]);
    }

    /** @test */
    public function it_can_show_broadcast()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        $response = $this->getJson("/api/broadcasts/{$broadcast->id}?company_id={$this->company->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $broadcast->id)
            ->assertJsonPath('data.name', $broadcast->name);
    }

    /** @test */
    public function it_can_update_broadcast()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]);

        $response = $this->putJson("/api/broadcasts/{$broadcast->id}", [
            'company_id' => $this->company->id,
            'name' => 'Updated Broadcast',
            'message' => 'Updated message',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Broadcast updated successfully',
            ]);

        $this->assertDatabaseHas('broadcasts', [
            'id' => $broadcast->id,
            'name' => 'Updated Broadcast',
        ]);
    }

    /** @test */
    public function it_can_delete_draft_broadcast()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]);

        $response = $this->deleteJson("/api/broadcasts/{$broadcast->id}?company_id={$this->company->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Broadcast deleted successfully',
            ]);
    }

    /** @test */
    public function it_cannot_delete_sent_broadcast()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);

        $response = $this->deleteJson("/api/broadcasts/{$broadcast->id}?company_id={$this->company->id}");

        $response->assertStatus(400);
    }

    /** @test */
    public function it_can_send_broadcast_immediately()
    {
        Queue::fake();

        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'platform_connection_id' => $this->connection->id,
            'status' => 'draft',
            'total_recipients' => 5,
        ]);

        // Create some recipients
        $customers = Customer::factory()->count(5)->create([
            'company_id' => $this->company->id,
        ]);
        foreach ($customers as $customer) {
            BroadcastRecipient::factory()->create([
                'broadcast_id' => $broadcast->id,
                'customer_id' => $customer->id,
                'status' => 'pending',
            ]);
        }

        $response = $this->postJson("/api/broadcasts/{$broadcast->id}/send?company_id={$this->company->id}");

        $response->assertStatus(200);
    }

    /** @test */
    public function it_can_schedule_broadcast()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
            'total_recipients' => 5,
        ]);

        $scheduledAt = now()->addDay();

        $response = $this->postJson("/api/broadcasts/{$broadcast->id}/schedule", [
            'company_id' => $this->company->id,
            'scheduled_at' => $scheduledAt->toISOString(),
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Broadcast scheduled successfully',
            ]);
    }

    /** @test */
    public function it_can_cancel_scheduled_broadcast()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'status' => 'scheduled',
        ]);

        $response = $this->postJson("/api/broadcasts/{$broadcast->id}/cancel?company_id={$this->company->id}");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Broadcast cancelled successfully',
            ]);
    }

    /** @test */
    public function it_can_get_broadcast_statistics()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        BroadcastRecipient::factory()->count(3)->create([
            'broadcast_id' => $broadcast->id,
            'status' => 'sent',
        ]);
        BroadcastRecipient::factory()->count(2)->create([
            'broadcast_id' => $broadcast->id,
            'status' => 'failed',
        ]);

        $response = $this->getJson("/api/broadcasts/{$broadcast->id}/statistics?company_id={$this->company->id}");

        $response->assertStatus(200);
    }

    /** @test */
    public function it_can_get_broadcast_recipients()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
        ]);

        BroadcastRecipient::factory()->count(5)->create([
            'broadcast_id' => $broadcast->id,
        ]);

        $response = $this->getJson("/api/broadcasts/{$broadcast->id}/recipients?company_id={$this->company->id}");

        $response->assertStatus(200)
            ->assertJsonCount(5, 'data');
    }

    /** @test */
    public function it_can_estimate_recipient_count()
    {
        Customer::factory()->count(10)->create([
            'company_id' => $this->company->id,
        ]);

        $response = $this->getJson('/api/broadcasts/estimate?company_id=' . $this->company->id . '&platform_connection_id=' . $this->connection->id);

        $response->assertStatus(200);
    }

    /** @test */
    public function it_prevents_accessing_other_company_broadcasts()
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create(['current_company_id' => $otherCompany->id]);
        $broadcast = Broadcast::factory()->create([
            'company_id' => $otherCompany->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->getJson("/api/broadcasts/{$broadcast->id}?company_id={$this->company->id}");

        $response->assertStatus(404);
    }

    /** @test */
    public function it_validates_required_fields_when_creating_broadcast()
    {
        $response = $this->postJson('/api/broadcasts', [
            'company_id' => $this->company->id,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'message', 'platform_connection_id']);
    }

    /** @test */
    public function it_validates_platform_connection_id()
    {
        $response = $this->postJson('/api/broadcasts', [
            'company_id' => $this->company->id,
            'name' => 'Test Broadcast',
            'message' => 'Test message',
            'platform_connection_id' => 9999,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['platform_connection_id']);
    }

    /** @test */
    public function it_validates_scheduled_at_is_future()
    {
        $broadcast = Broadcast::factory()->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'total_recipients' => 5,
        ]);

        $pastDate = now()->subDay();

        $response = $this->postJson("/api/broadcasts/{$broadcast->id}/schedule", [
            'company_id' => $this->company->id,
            'scheduled_at' => $pastDate->toISOString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['scheduled_at']);
    }

    /** @test */
    public function it_can_filter_broadcasts_by_status()
    {
        Broadcast::factory()->count(3)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'status' => 'completed',
        ]);
        Broadcast::factory()->count(2)->create([
            'company_id' => $this->company->id,
            'user_id' => $this->user->id,
            'status' => 'draft',
        ]);

        $response = $this->getJson('/api/broadcasts?status=draft&company_id=' . $this->company->id);

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }
}
