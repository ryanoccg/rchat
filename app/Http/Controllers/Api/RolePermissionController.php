<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RolePermissionController extends Controller
{
    /**
     * Permission groups for UI display.
     */
    private const PERMISSION_GROUPS = [
        'User Management' => ['view users', 'create users', 'edit users', 'delete users'],
        'Settings' => ['view settings', 'edit settings'],
        'Conversations' => ['view conversations', 'reply conversations', 'assign conversations', 'close conversations'],
        'AI Configuration' => ['view ai config', 'edit ai config'],
        'Knowledge Base' => ['view knowledge base', 'create knowledge base', 'edit knowledge base', 'delete knowledge base'],
        'Platforms' => ['view platforms', 'connect platforms', 'disconnect platforms'],
        'Products' => ['view products', 'create products', 'edit products', 'delete products'],
        'Broadcasts' => ['view broadcasts', 'create broadcasts', 'edit broadcasts', 'delete broadcasts', 'send broadcasts'],
        'Calendar & Appointments' => ['view calendar', 'manage calendar', 'view appointments', 'create appointments'],
        'Workflows' => ['view workflows', 'create workflows', 'edit workflows', 'delete workflows', 'execute workflows', 'view workflow executions'],
        'Reports' => ['view reports', 'export reports'],
    ];

    /**
     * List all permissions grouped by category.
     */
    public function permissions()
    {
        $allPermissions = Permission::where('guard_name', 'web')->pluck('name')->toArray();

        $groups = [];
        foreach (self::PERMISSION_GROUPS as $group => $perms) {
            $groups[] = [
                'name' => $group,
                'permissions' => array_values(array_intersect($perms, $allPermissions)),
            ];
        }

        return response()->json(['data' => $groups]);
    }

    /**
     * List roles available to the company (defaults + custom).
     */
    public function index(Request $request)
    {
        $company = $request->user()->currentCompany;

        // Get default roles (excluding Super Admin)
        $defaultRoles = Role::whereNull('company_id')
            ->where('name', '!=', 'Super Admin')
            ->get();

        // Get custom roles for this company
        $customRoles = Role::where('company_id', $company->id)
            ->where('is_custom', true)
            ->get();

        // Get member counts per role
        $memberCounts = DB::table('company_user')
            ->where('company_id', $company->id)
            ->select('role', DB::raw('count(*) as count'))
            ->groupBy('role')
            ->pluck('count', 'role');

        // Get company permission overrides
        $overrides = DB::table('company_role_permissions')
            ->where('company_id', $company->id)
            ->pluck('permissions', 'role_id')
            ->map(fn($p) => json_decode($p, true));

        $roles = [];

        foreach ($defaultRoles as $role) {
            $permissions = $overrides->has($role->id)
                ? $overrides[$role->id]
                : $role->permissions->pluck('name')->toArray();

            $roles[] = [
                'id' => $role->id,
                'name' => $role->name,
                'is_custom' => false,
                'is_editable' => $role->name !== 'Company Owner',
                'member_count' => $memberCounts[$role->name] ?? 0,
                'permissions' => $permissions,
            ];
        }

        foreach ($customRoles as $role) {
            $roles[] = [
                'id' => $role->id,
                'name' => $role->name,
                'is_custom' => true,
                'is_editable' => true,
                'member_count' => $memberCounts[$role->name] ?? 0,
                'permissions' => $role->permissions->pluck('name')->toArray(),
            ];
        }

        return response()->json(['data' => $roles]);
    }

    /**
     * Get permissions for a specific role.
     */
    public function show(Request $request, $id)
    {
        $company = $request->user()->currentCompany;
        $role = Role::findOrFail($id);

        // Ensure role belongs to this company or is a default
        if ($role->company_id && $role->company_id !== $company->id) {
            abort(403, 'Unauthorized');
        }

        // Check for company-level override
        $override = DB::table('company_role_permissions')
            ->where('company_id', $company->id)
            ->where('role_id', $role->id)
            ->first();

        $permissions = $override
            ? json_decode($override->permissions, true)
            : $role->permissions->pluck('name')->toArray();

        return response()->json([
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'is_custom' => $role->is_custom,
                'permissions' => $permissions,
            ],
        ]);
    }

    /**
     * Update permissions for a role.
     */
    public function updatePermissions(Request $request, $id)
    {
        $this->authorizeOwner($request);

        $company = $request->user()->currentCompany;
        $role = Role::findOrFail($id);

        // Cannot edit Company Owner permissions
        if ($role->name === 'Company Owner' && !$role->is_custom) {
            return response()->json(['message' => 'Cannot modify Company Owner permissions'], 403);
        }

        // Ensure role belongs to this company or is a default
        if ($role->company_id && $role->company_id !== $company->id) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        if ($role->is_custom) {
            // Custom role: update permissions directly
            $role->syncPermissions($validated['permissions']);
        } else {
            // Default role: store company-level override
            $exists = DB::table('company_role_permissions')
                ->where('company_id', $company->id)
                ->where('role_id', $role->id)
                ->exists();

            if ($exists) {
                DB::table('company_role_permissions')
                    ->where('company_id', $company->id)
                    ->where('role_id', $role->id)
                    ->update([
                        'permissions' => json_encode($validated['permissions']),
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('company_role_permissions')->insert([
                    'company_id' => $company->id,
                    'role_id' => $role->id,
                    'permissions' => json_encode($validated['permissions']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Permissions updated successfully',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'permissions' => $validated['permissions'],
            ],
        ]);
    }

    /**
     * Create a custom role.
     */
    public function store(Request $request)
    {
        $this->authorizeOwner($request);

        $company = $request->user()->currentCompany;

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('roles', 'name')->where(function ($query) use ($company) {
                    return $query->where('company_id', $company->id)
                        ->orWhereNull('company_id');
                }),
            ],
            'permissions' => 'required|array|min:1',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        $role = Role::create([
            'name' => $validated['name'],
            'guard_name' => 'web',
            'company_id' => $company->id,
            'is_custom' => true,
        ]);

        $role->syncPermissions($validated['permissions']);

        return response()->json([
            'message' => 'Role created successfully',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
                'is_custom' => true,
                'is_editable' => true,
                'member_count' => 0,
                'permissions' => $validated['permissions'],
            ],
        ], 201);
    }

    /**
     * Update a custom role's name.
     */
    public function update(Request $request, $id)
    {
        $this->authorizeOwner($request);

        $company = $request->user()->currentCompany;
        $role = Role::findOrFail($id);

        if (!$role->is_custom || $role->company_id !== $company->id) {
            return response()->json(['message' => 'Can only rename custom roles'], 403);
        }

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:50',
                Rule::unique('roles', 'name')->where(function ($query) use ($company) {
                    return $query->where('company_id', $company->id)
                        ->orWhereNull('company_id');
                })->ignore($role->id),
            ],
        ]);

        // Update role name in pivot table for existing members
        DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('role', $role->name)
            ->update(['role' => $validated['name']]);

        $role->update(['name' => $validated['name']]);

        return response()->json([
            'message' => 'Role updated successfully',
            'data' => [
                'id' => $role->id,
                'name' => $role->name,
            ],
        ]);
    }

    /**
     * Delete a custom role.
     */
    public function destroy(Request $request, $id)
    {
        $this->authorizeOwner($request);

        $company = $request->user()->currentCompany;
        $role = Role::findOrFail($id);

        if (!$role->is_custom || $role->company_id !== $company->id) {
            return response()->json(['message' => 'Can only delete custom roles'], 403);
        }

        // Check if any members have this role
        $memberCount = DB::table('company_user')
            ->where('company_id', $company->id)
            ->where('role', $role->name)
            ->count();

        if ($memberCount > 0) {
            return response()->json([
                'message' => "Cannot delete role with {$memberCount} assigned member(s). Reassign them first.",
            ], 422);
        }

        $role->delete();

        return response()->json(['message' => 'Role deleted successfully']);
    }

    /**
     * Ensure the current user is a Company Owner.
     */
    private function authorizeOwner(Request $request): void
    {
        $company = $request->user()->currentCompany;
        $userRole = $company->users()
            ->where('users.id', $request->user()->id)
            ->first()
            ?->pivot
            ?->role;

        if ($userRole !== 'Company Owner') {
            abort(403, 'Only Company Owners can manage roles and permissions');
        }
    }
}
