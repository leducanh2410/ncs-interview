<?php

namespace App\Actions\Common\CheckIn;

use App\Enums\ErrorCode;
use App\Models\ShiftUser;
use App\Models\User;

/**
 * Fail-closed workplace-scope check shared by:
 * - `shift.check_in` (admin proxy check-in/out, shift-users check-in/out)
 * - `shift.update_salary` (shift-users salary)
 * - `shift.update_overtime` (shift-users allow-overtime)
 *
 * `$permission` is required so call sites cannot silently fall back to
 * `shift.check_in`. `$outOfScopeError` defaults to
 * `CHECK_IN_WORKPLACE_OUT_OF_SCOPE`; salary/overtime pass `Forbidden` so
 * they match bulk overtime / eligibility on the same screen.
 *
 * Delegates to `User::canAccessWorkplace()` so read (`getCheckInShift` via
 * `getWorkplaceIdsByPermission`) and write agree on GlobalAdmin, while still
 * honouring a direct `permission_user` grant the same way route middleware does.
 *
 * Accepts a bound `ShiftUser` to reuse the route-model query, or a raw id
 * when the id comes from the request body.
 */
class AssertShiftWorkplaceAccessAction
{
    public function __invoke(
        mixed $actor,
        ShiftUser|int $shiftUser,
        string $permission,
        ErrorCode $outOfScopeError = ErrorCode::CheckInWorkplaceOutOfScope
    ): ?ErrorCode {
        if (!$actor instanceof User) {
            return ErrorCode::Forbidden;
        }

        $workplaceId = $this->resolveWorkplaceId($shiftUser);

        // Missing shift_user is fail-closed for scoped actors. Unrestricted
        // access (role-global, GlobalAdmin, or permission_user team_id = null)
        // is allowed through so CoreCheckIn/OutAction can return 404 instead
        // of a misleading CHECK_IN_WORKPLACE_OUT_OF_SCOPE.
        if ($workplaceId === null) {
            return $actor->hasUnrestrictedWorkplaceAccess($permission) ? null : $outOfScopeError;
        }

        return $actor->canAccessWorkplace($permission, $workplaceId) ? null : $outOfScopeError;
    }

    private function resolveWorkplaceId(ShiftUser|int $shiftUser): ?int
    {
        if ($shiftUser instanceof ShiftUser) {
            $workplaceId = $shiftUser->shift?->workplace_id;

            return $workplaceId === null ? null : (int) $workplaceId;
        }

        $workplaceId = ShiftUser::query()
            ->where('shift_users.id', $shiftUser)
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->value('shifts.workplace_id');

        return $workplaceId === null ? null : (int) $workplaceId;
    }
}
