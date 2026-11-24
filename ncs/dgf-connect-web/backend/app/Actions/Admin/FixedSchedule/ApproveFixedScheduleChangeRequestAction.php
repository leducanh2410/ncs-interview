<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Enums\PermissionName;
use App\Models\FixedSchedule;
use App\Models\FixedScheduleChangeRequest;
use App\Models\User;
use App\Models\WorkplaceShift;
use Illuminate\Support\Facades\DB;

class ApproveFixedScheduleChangeRequestAction
{
    public function __invoke(User $user, FixedScheduleChangeRequest $request): ActionResult
    {
        if (!$user->isAbleTo(PermissionName::FixedScheduleChangeRequestProcess->value, $request->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        return DB::transaction(function () use ($user, $request) {
            // Re-fetch under a row lock so a concurrent approve/reject on the same request
            // serializes here instead of both racing past the pending check below.
            $locked = FixedScheduleChangeRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== FixedScheduleChangeRequestStatus::Pending->value) {
                return new ActionResult(error: ErrorCode::FixedScheduleRequestNotPending, status: 409);
            }

            // Re-validate the requested shifts are still active: an admin may have deactivated
            // one after this request was submitted, and the mobile catalog only ever offered
            // active shifts, so a stale requested shift must not be applied.
            $requestedShiftIds = $locked->days->pluck('requested_workplace_shift_id')->filter()->unique()->values();
            if ($requestedShiftIds->isNotEmpty()) {
                $activeShiftCount = WorkplaceShift::query()
                    ->where('is_active', true)
                    ->whereIn('id', $requestedShiftIds)
                    ->count();
                if ($activeShiftCount !== $requestedShiftIds->count()) {
                    return new ActionResult(error: ErrorCode::FixedScheduleInvalidShift, status: 422);
                }
            }

            $schedule = FixedSchedule::query()->lockForUpdate()->findOrFail($locked->fixed_schedule_id);

            foreach ($locked->days as $day) {
                $schedule->applyDetailForDay($day->day, $day->requested_workplace_shift_id);
            }

            $locked->update([
                'status' => FixedScheduleChangeRequestStatus::Approved->value,
                'processed_by_id' => $user->id,
                'processed_at' => now(),
            ]);

            return new ActionResult(data: $locked->fresh(['days']));
        });
    }
}
