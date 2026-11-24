<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class RemoveRoleAction
{
    /**
     * Remove a single role assignment from a user
     *
     * @param int $userId User ID to remove role from
     * @param string $roleName Role name to remove
     * @param int|null $workplaceId Optional workplace ID to remove specific workplace-scoped role
     * @return ActionResult
     */
    public function __invoke(int $userId, string $roleName, ?int $workplaceId = null): ActionResult
    {
        // Security check: Cannot remove roles from self
        if (Auth::id() === $userId) {
            return new ActionResult(error: ErrorCode::UserCannotAssignRoleSelf, status: 403);
        }

        $user = User::query()->findOrFail($userId);

        // Security check: Only user ID 1 can remove GlobalAdmin role
        if ($roleName === RoleName::GlobalAdmin->value && Auth::id() !== 1) {
            return new ActionResult(error: ErrorCode::UserCannotAssignRoleGlobalAdmin, status: 403);
        }

        // Validate role exists
        $role = Role::where('name', $roleName)->first();
        if (!$role) {
            return new ActionResult(error: ErrorCode::RoleNotFound, status: 400);
        }

        // Remove role with optional workplace scope
        $user->removeRole($role, $workplaceId);

        return new ActionResult(data: $user->load('roles'));
    }
}
