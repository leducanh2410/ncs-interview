<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BulkUpdateAllowOvertimeAction
{
    /**
     * Set the exact overtime state for the shift's approved & present employees: those in
     * {$enabledShiftUserIds} get overtime turned on, every other candidate gets it turned
     * off. Ids that are not approved/present candidates of this shift are ignored.
     *
     * @param int[] $enabledShiftUserIds
     */
    public function __invoke(User $user, Shift $shift, array $enabledShiftUserIds): ActionResult
    {
        // Validate workplace permission: route middleware ignores team scope, so re-check
        // the overtime permission against this shift's workplace (Laratrust strict_check=false).
        if (!$user->isAbleTo(PermissionName::ShiftUpdateOvertime->value, $shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $enabledSet = array_flip(array_map('intval', $enabledShiftUserIds));

        $candidates = $shift
            ->shiftUsers()
            ->where('status', ShiftUserStatus::Approved->value)
            ->where('is_absent', false)
            ->get();

        DB::transaction(function () use ($candidates, $enabledSet) {
            foreach ($candidates as $shiftUser) {
                $shouldEnable = isset($enabledSet[$shiftUser->id]);
                if ($shiftUser->is_overtime_allowed !== $shouldEnable) {
                    $shiftUser->update(['is_overtime_allowed' => $shouldEnable]);
                }
            }
        });

        return new ActionResult(
            data: $candidates
                ->map(
                    fn($shiftUser) => [
                        'shift_user_id' => $shiftUser->id,
                        'is_overtime_allowed' => $shiftUser->is_overtime_allowed,
                    ]
                )
                ->values()
        );
    }
}
