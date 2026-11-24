<?php

namespace App\Actions\Manage\Shift;

use App\Enums\RoleName;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Answers "which users administer shifts at these workplaces", the inverse of
 * User::getAccessibleWorkplacesByPermissionGroup. Resolves every workplace in
 * the batch with a single query rather than one query per shift.
 */
class ResolveWorkplaceShiftAdminsAction
{
    /**
     * @param int[] $workplaceIds
     * @return array<int, int[]>
     */
    public function __invoke(array $workplaceIds): array
    {
        if (empty($workplaceIds)) {
            return [];
        }

        $rows = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->join('permission_role', 'permission_role.role_id', '=', 'role_user.role_id')
            ->join('permissions', 'permissions.id', '=', 'permission_role.permission_id')
            ->where('role_user.user_type', User::class)
            ->where('roles.name', '!=', RoleName::GlobalAdmin->value)
            ->whereNotNull('role_user.team_id')
            ->whereIn('role_user.team_id', $workplaceIds)
            ->where('permissions.name', 'like', 'shift.%')
            ->select(['role_user.team_id', 'role_user.user_id'])
            ->distinct()
            ->get();

        $result = [];
        foreach ($rows as $row) {
            $result[(int) $row->team_id][] = (int) $row->user_id;
        }

        return $result;
    }
}
