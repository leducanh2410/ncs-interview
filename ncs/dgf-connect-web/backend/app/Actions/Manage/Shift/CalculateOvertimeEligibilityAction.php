<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\User;

class CalculateOvertimeEligibilityAction
{
    public function __construct(private readonly CalculateShiftWeeklyWorkLoadAction $calculateWeeklyWorkLoad) {}

    /**
     * For every approved & present employee in the shift, work out whether they could
     * take {$overtimeMinutes} of overtime without exceeding their weekly work-hour limit.
     *
     * Eligibility mirrors the registration rule (WorkHelper::validateShiftRegister): we
     * take the binding weekly total (the largest of the seven sliding 7-day windows that
     * contain the shift date, already including this shift) and require the remaining
     * headroom to cover the requested overtime.
     */
    public function __invoke(User $user, Shift $shift, int $overtimeMinutes): ActionResult
    {
        // Validate workplace permission: route middleware ignores team scope, so re-check
        // the overtime permission against this shift's workplace (Laratrust strict_check=false).
        if (!$user->isAbleTo(PermissionName::ShiftUpdateOvertime->value, $shift->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $overtimeHours = $overtimeMinutes / 60;

        $shiftUsers = $shift
            ->shiftUsers()
            ->where('status', ShiftUserStatus::Approved->value)
            ->where('is_absent', false)
            ->get();

        $userIds = $shiftUsers->pluck('user_id')->unique()->values()->all();
        $workLoads = ($this->calculateWeeklyWorkLoad)($shift, $userIds);

        $candidates = [];
        foreach ($shiftUsers as $shiftUser) {
            $workLoad = $workLoads[$shiftUser->user_id];

            $candidates[] = [
                'shift_user_id' => $shiftUser->id,
                'user_id' => $shiftUser->user_id,
                'is_overtime_allowed' => $shiftUser->is_overtime_allowed,
                'weekly_hours' => round($workLoad['projected_hours'], 2),
                'max_hour_per_week' => $workLoad['max_hour_per_week'],
                'remaining_hours' => round($workLoad['remaining_hours'], 2),
                'is_eligible' => $workLoad['remaining_hours'] >= $overtimeHours,
            ];
        }

        return new ActionResult(
            data: [
                'overtime_minutes' => $overtimeMinutes,
                'candidates' => $candidates,
            ]
        );
    }
}
