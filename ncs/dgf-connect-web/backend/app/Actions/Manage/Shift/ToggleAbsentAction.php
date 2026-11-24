<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Enums\ShiftUserStatus;
use App\Facades\SalaryFacade;
use App\Jobs\CollectWorkTime;
use App\Jobs\UpdateUserSalary;
use App\Models\Salary;
use App\Models\SalaryRequestDetail;
use App\Models\ShiftUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ToggleAbsentAction
{
    public function __construct(private readonly FindWeeklyHourViolationsAction $findWeeklyHourViolations) {}

    public function __invoke(int $shiftUserId): ActionResult
    {
        $result = DB::transaction(function () use ($shiftUserId) {
            $shiftUser = ShiftUser::query()
                ->with(['shift', 'checkIn'])
                ->find($shiftUserId);

            if (!$shiftUser) {
                return new ActionResult(error: ErrorCode::ShiftUserNotFound, status: 404);
            }

            if ($shiftUser->status !== ShiftUserStatus::Approved->value) {
                return new ActionResult(error: ErrorCode::ShiftUserInvalidStatus, status: 400);
            }

            if (!$shiftUser->is_absent) {
                $conflict = $this->salaryConflict($shiftUser);
                if ($conflict) {
                    return new ActionResult(error: $conflict, status: 400);
                }
            }

            if ($shiftUser->is_absent && !$shiftUser->checkIn?->check_out_time) {
                $shift = $shiftUser->shift;
                $overHours =
                    ($this->findWeeklyHourViolations)($shift, [$shiftUser->user_id], $shift->getDuration())[
                        $shiftUser->user_id
                    ] ?? null;
                if ($overHours !== null) {
                    return new ActionResult(
                        error: ErrorCode::ScheduleMaxWorkHoursExceeded,
                        status: 400,
                        data: ['over_hours' => round($overHours, 2)]
                    );
                }
            }

            $shiftUser->update([
                'is_absent' => !$shiftUser->is_absent,
            ]);

            return new ActionResult(data: $shiftUser);
        });

        if (!$result->getError()) {
            /** @var ShiftUser $shiftUser */
            $shiftUser = $result->getData();
            $shiftUser->setAttribute('is_salary_settled', $this->isSalarySettled($shiftUser));
            $this->recollectWorkTime($result->getData());
        }

        return $result;
    }

    /**
     * Dropping this shift's hours lowers the month's salary, which is only safe while nothing
     * downstream has committed to the old amount. Returns the reason to refuse, or null.
     */
    private function salaryConflict(ShiftUser $shiftUser): ?ErrorCode
    {
        if (!$shiftUser->checkIn) {
            return null;
        }

        $month = mb_substr($shiftUser->shift->date, 0, 7);
        $salary = Salary::query()->where('user_id', $shiftUser->user_id)->where('month', $month)->first();

        if (!$salary) {
            return null;
        }

        // A salary request snapshots the amount when it is created and no later step
        // recalculates it, so an active one must be settled or cancelled first.
        $hasActiveRequest = SalaryRequestDetail::query()
            ->where('salary_id', $salary->id)
            ->whereHas('salaryRequest', function (Builder $builder) {
                $builder->whereIn('status', SalaryRequestStatus::getActiveStatuses());
            })
            ->exists();

        if ($hasActiveRequest) {
            return ErrorCode::ShiftUserAbsentSalaryRequested;
        }

        if ($salary->is_finalized || $salary->is_fully_paid) {
            return null;
        }

        // salaries.total_remaining is unsigned and cannot express money already paid out for a
        // month worth less than that, so the recalculation job would fail on the queue and the
        // absent flag would silently disagree with the salary.
        $committed = $salary->total_paid + $salary->total_deductions + $salary->total_fee;

        if ($committed <= 0) {
            return null;
        }

        return SalaryFacade::previewMonthTotal($shiftUser->user_id, $month, $shiftUser->id) < $committed
            ? ErrorCode::ShiftUserAbsentSalaryPaid
            : null;
    }

    private function isSalarySettled(ShiftUser $shiftUser): bool
    {
        if (!$shiftUser->checkIn) {
            return false;
        }

        $salary = Salary::query()
            ->where('user_id', $shiftUser->user_id)
            ->where('month', mb_substr($shiftUser->shift->date, 0, 7))
            ->first();

        return (bool) $salary?->is_finalized || (bool) $salary?->is_fully_paid;
    }

    private function recollectWorkTime(ShiftUser $shiftUser): void
    {
        if (!$shiftUser->checkIn) {
            return;
        }

        Cache::forget(CollectUserSalaryAction::CACHE_KEY_PREFIX . $shiftUser->user_id);

        $month = mb_substr($shiftUser->shift->date, 0, 7);
        dispatch(new UpdateUserSalary($shiftUser->user_id, $month));
        dispatch(new CollectWorkTime(userId: $shiftUser->user_id, month: $month));
    }
}
