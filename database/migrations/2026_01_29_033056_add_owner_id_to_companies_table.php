<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Fix existing company owners: ensure the first user attached to each company
     * has the 'Company Owner' role in the pivot table.
     */
    public function up(): void
    {
        // Find all company_user records where user has Spatie 'Company Owner' role
        // and update their pivot role accordingly
        $ownerRoleUsers = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', 'Company Owner')
            ->where('model_has_roles.model_type', 'App\\Models\\User')
            ->pluck('model_has_roles.model_id');

        if ($ownerRoleUsers->isNotEmpty()) {
            DB::table('company_user')
                ->whereIn('user_id', $ownerRoleUsers)
                ->where('role', '!=', 'Company Owner')
                ->update(['role' => 'Company Owner']);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reversal needed
    }
};
