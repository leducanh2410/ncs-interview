<?php

namespace App\Actions\Admin\User;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\RoleName;
use App\Models\Role;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Support\Facades\Auth;

class AssignRolesAction
{
    /**
     * Assign a single role to a user with optional workplace scope
     *
     * @param int $userId User ID to assign role to
     * @param string $roleName Role name to assign
     * @param int|null $workplaceId Optional workplace ID for workplace-scoped role
     * @return ActionResult
     */
    public function __invoke(int $userId, string $roleName, ?int $workplaceId = null): ActionResult
    {
        // Security check: Cannot assign roles to self
        if (Auth::id() === $userId) {
            return new ActionResult(error: ErrorCode::UserCannotAssignRoleSelf, status: 403);
        }

        $user = User::query()->findOrFail($userId);

        // Validate role exists
        $role = Role::query()->where('name', $roleName)->first();
        if (!$role) {
            return new ActionResult(error: ErrorCode::RoleNotFound, status: 400);
        }

        // Validate exclusive user role constraint
        $exclusiveRoleError = $this->validateExclusiveUserRole($user, $roleName);
        if ($exclusiveRoleError) {
            return new ActionResult(error: $exclusiveRoleError, status: 400);
        }

        // Validate workplace exists if specified
        if ($workplaceId !== null) {
            $workplace = Workplace::query()->find($workplaceId);
            if (!$workplace) {
                return new ActionResult(error: ErrorCode::WorkplaceNotFound, status: 400);
            }
        }

        // Add role with optional workplace scope
        $user->addRole($role, $workplaceId);

        return new ActionResult(data: $user->load('roles'));
    }

    /**
     * Validate that the user role is mutually exclusive with other roles.
     *
     * @param User $user The user to check
     * @param string $roleName The role being assigned
     * @return ErrorCode|null Error code if validation fails, null if valid
     */
    private function validateExclusiveUserRole(User $user, string $roleName): ?ErrorCode
    {
        $userRoleName = RoleName::User->value;
        $userRoleNames = $user->roles->pluck('name')->toArray();

        // If trying to add 'user' role and user already has other roles
        if ($roleName === $userRoleName && !empty($userRoleNames)) {
            $hasOtherRoles = collect($userRoleNames)->contains(fn($name) => $name !== $userRoleName);
            if ($hasOtherRoles) {
                return ErrorCode::RoleCannotAddUserWithOtherRoles;
            }
        }

        // If trying to add other role and user already has 'user' role
        if ($roleName !== $userRoleName && in_array($userRoleName, $userRoleNames)) {
            return ErrorCode::RoleUserCannotHaveOtherRoles;
        }

        return null;
    }
}
