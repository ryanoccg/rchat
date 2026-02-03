<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles if they don't exist
        Role::firstOrCreate(['name' => 'Company Owner', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Company Admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Agent', 'guard_name' => 'web']);

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id, [
            'role' => 'Company Owner',
            'joined_at' => now(),
        ]);
        $this->user->assignRole('Company Owner');
    }

    public function test_can_list_team_members(): void
    {
        // Add another member
        $agent = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $agent->companies()->attach($this->company->id, [
            'role' => 'Agent',
            'joined_at' => now(),
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/team');

        $response->assertOk()
            ->assertJsonCount(2, 'members')
            ->assertJsonFragment(['name' => $this->user->name])
            ->assertJsonFragment(['name' => $agent->name]);
    }

    public function test_members_include_role_information(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/team');

        $response->assertOk()
            ->assertJsonFragment([
                'role' => 'Company Owner',
                'is_current_user' => true,
            ]);
    }

    public function test_can_list_pending_invitations(): void
    {
        TeamInvitation::factory()->count(2)->create([
            'company_id' => $this->company->id,
            'invited_by' => $this->user->id,
        ]);

        // Create an accepted invitation (should not appear)
        TeamInvitation::factory()->accepted()->create([
            'company_id' => $this->company->id,
            'invited_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/team/invitations');

        $response->assertOk()
            ->assertJsonCount(2, 'invitations');
    }

    public function test_can_invite_new_team_member(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/team/invite', [
                'email' => 'newmember@example.com',
                'role' => 'Agent',
            ]);

        $response->assertCreated()
            ->assertJsonFragment([
                'email' => 'newmember@example.com',
                'role' => 'Agent',
            ]);

        $this->assertDatabaseHas('team_invitations', [
            'company_id' => $this->company->id,
            'email' => 'newmember@example.com',
            'role' => 'Agent',
            'invited_by' => $this->user->id,
        ]);
    }

    public function test_cannot_invite_existing_team_member(): void
    {
        $existingMember = User::factory()->create([
            'email' => 'existing@example.com',
            'current_company_id' => $this->company->id,
        ]);
        $existingMember->companies()->attach($this->company->id);

        $response = $this->actingAs($this->user)
            ->postJson('/api/team/invite', [
                'email' => 'existing@example.com',
                'role' => 'Agent',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'This email is already a member of your team.',
            ]);
    }

    public function test_cannot_send_duplicate_invitation(): void
    {
        TeamInvitation::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'pending@example.com',
            'invited_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/team/invite', [
                'email' => 'pending@example.com',
                'role' => 'Agent',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'An invitation has already been sent to this email.',
            ]);
    }

    public function test_can_resend_invitation(): void
    {
        $invitation = TeamInvitation::factory()->create([
            'company_id' => $this->company->id,
            'invited_by' => $this->user->id,
        ]);

        $originalToken = $invitation->token;

        $response = $this->actingAs($this->user)
            ->postJson("/api/team/invitations/{$invitation->id}/resend");

        $response->assertOk();

        $invitation->refresh();
        $this->assertNotEquals($originalToken, $invitation->token);
    }

    public function test_can_cancel_invitation(): void
    {
        $invitation = TeamInvitation::factory()->create([
            'company_id' => $this->company->id,
            'invited_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/team/invitations/{$invitation->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('team_invitations', [
            'id' => $invitation->id,
        ]);
    }

    public function test_can_update_member_role(): void
    {
        $agent = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $agent->companies()->attach($this->company->id, ['role' => 'Agent']);
        $agent->assignRole('Agent');

        $response = $this->actingAs($this->user)
            ->putJson("/api/team/members/{$agent->id}/role", [
                'role' => 'Company Admin',
            ]);

        $response->assertOk();

        $this->assertEquals(
            'Company Admin',
            $this->company->users()->where('users.id', $agent->id)->first()->pivot->role
        );
    }

    public function test_cannot_change_own_role(): void
    {
        $response = $this->actingAs($this->user)
            ->putJson("/api/team/members/{$this->user->id}/role", [
                'role' => 'Agent',
            ]);

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'You cannot change your own role.',
            ]);
    }

    public function test_admin_cannot_assign_owner_role(): void
    {
        // Create an admin user
        $admin = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $admin->companies()->attach($this->company->id, ['role' => 'Company Admin']);
        $admin->assignRole('Company Admin');

        // Create an agent
        $agent = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $agent->companies()->attach($this->company->id, ['role' => 'Agent']);

        $response = $this->actingAs($admin)
            ->putJson("/api/team/members/{$agent->id}/role", [
                'role' => 'Company Owner',
            ]);

        $response->assertStatus(403);
    }

    public function test_can_remove_team_member(): void
    {
        $agent = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $agent->companies()->attach($this->company->id);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/team/members/{$agent->id}");

        $response->assertOk();

        $this->assertDatabaseMissing('company_user', [
            'company_id' => $this->company->id,
            'user_id' => $agent->id,
        ]);
    }

    public function test_cannot_remove_self(): void
    {
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/team/members/{$this->user->id}");

        $response->assertStatus(422)
            ->assertJsonFragment([
                'message' => 'You cannot remove yourself from the team.',
            ]);
    }

    public function test_admin_cannot_remove_owner(): void
    {
        // Create an admin
        $admin = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $admin->companies()->attach($this->company->id, ['role' => 'Company Admin']);
        $admin->assignRole('Company Admin');

        $response = $this->actingAs($admin)
            ->deleteJson("/api/team/members/{$this->user->id}");

        $response->assertStatus(403);
    }

    public function test_can_get_invitation_by_token(): void
    {
        $invitation = TeamInvitation::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'invited@example.com',
            'role' => 'Agent',
            'invited_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/team/invitation?token={$invitation->token}");

        $response->assertOk()
            ->assertJsonFragment([
                'email' => 'invited@example.com',
                'role' => 'Agent',
                'company_name' => $this->company->name,
            ]);
    }

    public function test_expired_invitation_returns_410(): void
    {
        $invitation = TeamInvitation::factory()->expired()->create([
            'company_id' => $this->company->id,
            'invited_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/team/invitation?token={$invitation->token}");

        $response->assertStatus(410);
    }

    public function test_accepted_invitation_returns_410(): void
    {
        $invitation = TeamInvitation::factory()->accepted()->create([
            'company_id' => $this->company->id,
            'invited_by' => $this->user->id,
        ]);

        $response = $this->getJson("/api/team/invitation?token={$invitation->token}");

        $response->assertStatus(410);
    }

    public function test_can_accept_invitation_as_new_user(): void
    {
        $invitation = TeamInvitation::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'newuser@example.com',
            'role' => 'Agent',
            'invited_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/team/invitation/accept', [
            'token' => $invitation->token,
            'name' => 'New User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'user' => ['id', 'name', 'email'],
                'token',
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@example.com',
            'name' => 'New User',
        ]);

        $this->assertDatabaseHas('company_user', [
            'company_id' => $this->company->id,
            'role' => 'Agent',
        ]);

        $invitation->refresh();
        $this->assertNotNull($invitation->accepted_at);
    }

    public function test_can_accept_invitation_as_existing_user(): void
    {
        $existingUser = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $invitation = TeamInvitation::factory()->create([
            'company_id' => $this->company->id,
            'email' => 'existing@example.com',
            'role' => 'Agent',
            'invited_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/team/invitation/accept', [
            'token' => $invitation->token,
            'name' => 'Ignored',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('company_user', [
            'company_id' => $this->company->id,
            'user_id' => $existingUser->id,
            'role' => 'Agent',
        ]);
    }

    public function test_cannot_accept_invalid_token(): void
    {
        $response = $this->postJson('/api/team/invitation/accept', [
            'token' => Str::random(64),
            'name' => 'New User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(404);
    }

    public function test_cannot_accept_expired_invitation(): void
    {
        $invitation = TeamInvitation::factory()->expired()->create([
            'company_id' => $this->company->id,
            'email' => 'expired@example.com',
            'invited_by' => $this->user->id,
        ]);

        $response = $this->postJson('/api/team/invitation/accept', [
            'token' => $invitation->token,
            'name' => 'New User',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(404);
    }

    public function test_can_get_available_roles(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/team/roles');

        $response->assertOk()
            ->assertJsonFragment([
                'current_user_role' => 'Company Owner',
            ])
            ->assertJsonStructure([
                'roles',
                'current_user_role',
            ]);
    }

    public function test_admin_has_limited_roles(): void
    {
        $admin = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $admin->companies()->attach($this->company->id, ['role' => 'Company Admin']);

        $response = $this->actingAs($admin)
            ->getJson('/api/team/roles');

        $response->assertOk()
            ->assertJsonFragment([
                'current_user_role' => 'Company Admin',
            ])
            ->assertJsonPath('roles', ['Agent']);
    }

    public function test_cannot_access_other_company_team(): void
    {
        $otherCompany = Company::factory()->create();
        $otherUser = User::factory()->create([
            'current_company_id' => $otherCompany->id,
        ]);
        $otherUser->companies()->attach($otherCompany->id);

        // Create invitation in other company
        $invitation = TeamInvitation::factory()->create([
            'company_id' => $otherCompany->id,
            'invited_by' => $otherUser->id,
        ]);

        // Try to cancel from our company
        $response = $this->actingAs($this->user)
            ->deleteJson("/api/team/invitations/{$invitation->id}");

        $response->assertStatus(404);
    }

    public function test_removed_member_company_id_is_reset(): void
    {
        $agent = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $agent->companies()->attach($this->company->id);

        $this->actingAs($this->user)
            ->deleteJson("/api/team/members/{$agent->id}");

        $agent->refresh();
        $this->assertNull($agent->current_company_id);
    }

    public function test_role_validation_on_invite(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/team/invite', [
                'email' => 'test@example.com',
                'role' => 'Super Admin', // Invalid role
            ]);

        $response->assertStatus(422);
    }

    public function test_email_validation_on_invite(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/team/invite', [
                'email' => 'not-an-email',
                'role' => 'Agent',
            ]);

        $response->assertStatus(422);
    }
}
