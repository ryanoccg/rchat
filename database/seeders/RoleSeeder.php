<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles with 'web' guard
        $superAdmin = Role::firstOrCreate(['name' => 'Super Admin', 'guard_name' => 'web']);
        $companyOwner = Role::firstOrCreate(['name' => 'Company Owner', 'guard_name' => 'web']);
        $companyAdmin = Role::firstOrCreate(['name' => 'Company Admin', 'guard_name' => 'web']);
        $agent = Role::firstOrCreate(['name' => 'Agent', 'guard_name' => 'web']);

        // Create permissions
        $permissions = [
            // User management
            'view users',
            'create users',
            'edit users',
            'delete users',

            // Settings (company settings)
            'view settings',
            'edit settings',

            // Conversation management
            'view conversations',
            'reply conversations',
            'assign conversations',
            'close conversations',

            // AI configuration
            'view ai config',
            'edit ai config',

            // Knowledge base
            'view knowledge base',
            'create knowledge base',
            'edit knowledge base',
            'delete knowledge base',

            // Platform connections
            'view platforms',
            'connect platforms',
            'disconnect platforms',

            // Reports
            'view reports',
            'export reports',

            // Workflows
            'view workflows',
            'create workflows',
            'edit workflows',
            'delete workflows',
            'execute workflows',
            'view workflow executions',

            // Broadcasts
            'view broadcasts',
            'create broadcasts',
            'edit broadcasts',
            'delete broadcasts',
            'send broadcasts',

            // Products
            'view products',
            'create products',
            'edit products',
            'delete products',

            // Calendar & Appointments
            'view calendar',
            'manage calendar',
            'view appointments',
            'create appointments',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        // Assign permissions to roles
        $superAdmin->givePermissionTo(Permission::all());

        $companyOwner->givePermissionTo([
            'view users', 'create users', 'edit users', 'delete users',
            'view settings', 'edit settings',
            'view conversations', 'reply conversations', 'assign conversations', 'close conversations',
            'view ai config', 'edit ai config',
            'view knowledge base', 'create knowledge base', 'edit knowledge base', 'delete knowledge base',
            'view platforms', 'connect platforms', 'disconnect platforms',
            'view reports', 'export reports',
            'view workflows', 'create workflows', 'edit workflows', 'delete workflows', 'execute workflows', 'view workflow executions',
            'view broadcasts', 'create broadcasts', 'edit broadcasts', 'delete broadcasts', 'send broadcasts',
            'view products', 'create products', 'edit products', 'delete products',
            'view calendar', 'manage calendar', 'view appointments', 'create appointments',
        ]);

        $companyAdmin->givePermissionTo([
            'view users', 'create users', 'edit users',
            'view settings', 'edit settings',
            'view conversations', 'reply conversations', 'assign conversations', 'close conversations',
            'view ai config',
            'view knowledge base', 'create knowledge base', 'edit knowledge base',
            'view platforms',
            'view reports',
            'view workflows', 'create workflows', 'edit workflows', 'execute workflows', 'view workflow executions',
            'view broadcasts', 'create broadcasts', 'edit broadcasts', 'send broadcasts',
            'view products', 'create products', 'edit products',
            'view calendar', 'manage calendar', 'view appointments', 'create appointments',
        ]);

        $agent->givePermissionTo([
            'view conversations', 'reply conversations', 'close conversations',
            'view knowledge base',
            'view products',
            'view broadcasts',
            'view calendar', 'view appointments', 'create appointments',
        ]);
    }
}
