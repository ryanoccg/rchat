<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        $newPermissions = [
            'view settings',
            'edit settings',
            'view broadcasts',
            'create broadcasts',
            'edit broadcasts',
            'delete broadcasts',
            'send broadcasts',
            'view products',
            'create products',
            'edit products',
            'delete products',
            'view calendar',
            'manage calendar',
            'view appointments',
            'create appointments',
        ];

        foreach ($newPermissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Remove obsolete company permissions
        Permission::where('guard_name', 'web')
            ->whereIn('name', ['view companies', 'edit company', 'delete company'])
            ->delete();
    }

    public function down(): void
    {
        $removePermissions = [
            'view settings',
            'edit settings',
            'view broadcasts',
            'create broadcasts',
            'edit broadcasts',
            'delete broadcasts',
            'send broadcasts',
            'view products',
            'create products',
            'edit products',
            'delete products',
            'view calendar',
            'manage calendar',
            'view appointments',
            'create appointments',
        ];

        Permission::where('guard_name', 'web')
            ->whereIn('name', $removePermissions)
            ->delete();

        // Restore old permissions
        foreach (['view companies', 'edit company', 'delete company'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
    }
};
