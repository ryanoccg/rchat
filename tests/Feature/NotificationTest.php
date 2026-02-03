<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
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
        $this->user->companies()->attach($this->company->id, [
            'role' => 'Company Owner',
            'joined_at' => now(),
        ]);
    }

    /** @test */
    public function can_list_notifications()
    {
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonCount(3, 'data');
    }

    /** @test */
    public function notifications_are_ordered_by_created_at_desc()
    {
        $old = Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'created_at' => now()->subDays(2),
        ]);

        $new = Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'created_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertEquals($new->id, $data[0]['id']);
        $this->assertEquals($old->id, $data[1]['id']);
    }

    /** @test */
    public function can_only_see_own_notifications()
    {
        $otherUser = User::factory()->create();
        $otherUser->companies()->attach($this->company->id);

        Notification::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $this->company->id,
            'title' => 'Other User Notification',
        ]);

        Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'title' => 'My Notification',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['title' => 'My Notification'])
            ->assertJsonMissing(['title' => 'Other User Notification']);
    }

    /** @test */
    public function can_get_unread_count()
    {
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'is_read' => false,
        ]);

        Notification::factory()->count(2)->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'is_read' => true,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications/unread-count');

        $response->assertOk()
            ->assertJson(['count' => 3]);
    }

    /** @test */
    public function can_mark_single_notification_as_read()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'is_read' => false,
            'read_at' => null,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/notifications/{$notification->id}/read");

        $response->assertOk()
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.is_read', true);

        $notification->refresh();
        $this->assertTrue($notification->is_read);
        $this->assertNotNull($notification->read_at);
    }

    /** @test */
    public function cannot_mark_other_users_notification_as_read()
    {
        $otherUser = User::factory()->create();
        $otherUser->companies()->attach($this->company->id);

        $notification = Notification::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $this->company->id,
            'is_read' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/notifications/{$notification->id}/read");

        $response->assertStatus(404);
    }

    /** @test */
    public function can_mark_all_notifications_as_read()
    {
        Notification::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'is_read' => false,
        ]);

        // Create notification for other company (should not be affected)
        $otherCompany = Company::factory()->create();
        Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $otherCompany->id,
            'is_read' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/notifications/mark-all-read');

        $response->assertOk()
            ->assertJson(['message' => 'All notifications marked as read']);

        // Check all notifications for current company are marked as read
        $this->assertEquals(0, Notification::where('user_id', $this->user->id)
            ->where('company_id', $this->company->id)
            ->where('is_read', false)
            ->count());

        // Check other company notification is still unread
        $this->assertEquals(1, Notification::where('user_id', $this->user->id)
            ->where('company_id', $otherCompany->id)
            ->where('is_read', false)
            ->count());
    }

    /** @test */
    public function can_delete_notification()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/notifications/{$notification->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Notification deleted']);

        $this->assertDatabaseMissing('notifications', [
            'id' => $notification->id,
        ]);
    }

    /** @test */
    public function cannot_delete_other_users_notification()
    {
        $otherUser = User::factory()->create();
        $otherUser->companies()->attach($this->company->id);

        $notification = Notification::factory()->create([
            'user_id' => $otherUser->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/notifications/{$notification->id}");

        $response->assertStatus(404);

        $this->assertDatabaseHas('notifications', [
            'id' => $notification->id,
        ]);
    }

    /** @test */
    public function read_at_is_set_when_marking_as_read()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'is_read' => false,
            'read_at' => null,
        ]);

        $this->assertNull($notification->read_at);

        $this->actingAs($this->user)
            ->postJson("/api/notifications/{$notification->id}/read");

        $notification->refresh();
        $this->assertNotNull($notification->read_at);
    }

    /** @test */
    public function pagination_works_for_notifications()
    {
        Notification::factory()->count(25)->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2);
    }

    /** @test */
    public function notification_includes_conversation_id()
    {
        $notification = Notification::factory()->create([
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'data' => ['conversation_id' => 123],
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/notifications');

        $response->assertOk()
            ->assertJsonPath('data.0.conversation_id', 123);
    }
}
