<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $admin;
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\MessagingPlatformSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);

        $this->company = Company::factory()->create();
        $this->owner = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->owner->companies()->attach($this->company->id, ['role' => 'Company Owner']);

        $this->admin = User::factory()->create([
            'current_company_id' => $this->company->id,
        ]);
        $this->admin->companies()->attach($this->company->id, ['role' => 'Company Admin']);
    }

    /** @test */
    public function owner_can_list_roles()
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/roles');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'is_custom', 'is_editable', 'member_count', 'permissions']]]);
    }

    /** @test */
    public function can_list_permission_groups()
    {
        Sanctum::actingAs($this->owner);

        $response = $this->getJson('/api/roles/permissions');

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => [['name', 'permissions']]]);
    }

    /** @test */
    public function owner_can_view_role_permissions()
    {
        Sanctum::actingAs($this->owner);

        $role = Role::where('name', 'Agent')->first();
        $response = $this->getJson("/api/roles/{$role->id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', 'Agent');
    }

    /** @test */
    public function owner_can_update_role_permissions()
    {
        Sanctum::actingAs($this->owner);

        $role = Role::where('name', 'Agent')->first();
        $response = $this->putJson("/api/roles/{$role->id}/permissions", [
            'permissions' => ['view conversations', 'reply conversations', 'view knowledge base', 'view reports'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Permissions updated successfully');
    }

    /** @test */
    public function cannot_update_company_owner_permissions()
    {
        Sanctum::actingAs($this->owner);

        $role = Role::where('name', 'Company Owner')->first();
        $response = $this->putJson("/api/roles/{$role->id}/permissions", [
            'permissions' => ['view conversations'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function owner_can_create_custom_role()
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/roles', [
            'name' => 'Supervisor',
            'permissions' => ['view conversations', 'reply conversations', 'assign conversations', 'view reports'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'Supervisor')
            ->assertJsonPath('data.is_custom', true);
    }

    /** @test */
    public function cannot_create_duplicate_role_name()
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/roles', [
            'name' => 'Agent',
            'permissions' => ['view conversations'],
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function owner_can_delete_custom_role()
    {
        Sanctum::actingAs($this->owner);

        $role = Role::create([
            'name' => 'Temp Role',
            'guard_name' => 'web',
            'company_id' => $this->company->id,
            'is_custom' => true,
        ]);

        $response = $this->deleteJson("/api/roles/{$role->id}");
        $response->assertStatus(200);
    }

    /** @test */
    public function cannot_delete_default_role()
    {
        Sanctum::actingAs($this->owner);

        $role = Role::where('name', 'Agent')->first();
        $response = $this->deleteJson("/api/roles/{$role->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function cannot_delete_role_with_members()
    {
        Sanctum::actingAs($this->owner);

        $role = Role::create([
            'name' => 'Busy Role',
            'guard_name' => 'web',
            'company_id' => $this->company->id,
            'is_custom' => true,
        ]);

        // Assign a member with this role
        $user = User::factory()->create(['current_company_id' => $this->company->id]);
        $user->companies()->attach($this->company->id, ['role' => 'Busy Role']);

        $response = $this->deleteJson("/api/roles/{$role->id}");
        $response->assertStatus(422);
    }

    /** @test */
    public function non_owner_cannot_create_role()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/roles', [
            'name' => 'Test Role',
            'permissions' => ['view conversations'],
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function non_owner_cannot_update_permissions()
    {
        Sanctum::actingAs($this->admin);

        $role = Role::where('name', 'Agent')->first();
        $response = $this->putJson("/api/roles/{$role->id}/permissions", [
            'permissions' => ['view conversations'],
        ]);

        $response->assertStatus(403);
    }
}
