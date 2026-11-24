<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\FixedScheduleChangeRequestStatus;
use App\Enums\PermissionName;
use App\Models\FixedScheduleChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RejectFixedScheduleChangeRequestAction
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

            $locked->update([
                'status' => FixedScheduleChangeRequestStatus::Rejected->value,
                'processed_by_id' => $user->id,
                'processed_at' => now(),
            ]);

            return new ActionResult(data: $locked->fresh(['days']));
        });
    }
}
