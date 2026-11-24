<?php

use App\Enums\RoleName;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * Remove all non-user roles from users who have the 'user' role,
     * except for user ID 1 (system admin).
     */
    public function up(): void
    {
        $userRoleName = RoleName::User->value;

        // Get the user role ID
        $userRoleId = DB::table('roles')->where('name', $userRoleName)->value('id');

        if (!$userRoleId) {
            return;
        }

        // Find users (except ID 1) who have BOTH 'user' role AND at least one other role
        $usersWithConflictingRoles = DB::table('role_user as ru1')
            ->join('role_user as ru2', 'ru1.user_id', '=', 'ru2.user_id')
            ->where('ru1.role_id', $userRoleId)
            ->where('ru2.role_id', '!=', $userRoleId)
            ->where('ru1.user_id', '!=', 1)
            ->distinct()
            ->pluck('ru1.user_id');

        if ($usersWithConflictingRoles->isEmpty()) {
            return;
        }

        // Delete all non-user role assignments for these users
        DB::table('role_user')
            ->whereIn('user_id', $usersWithConflictingRoles)
            ->where('role_id', '!=', $userRoleId)
            ->delete();
    }

    /**
     * Reverse the migrations.
     *
     * Note: This migration cannot be fully reversed as we don't track
     * which roles were removed. The down method is a no-op.
     */
    public function down(): void
    {
        // Cannot restore removed roles as we don't track what was removed
    }
};
