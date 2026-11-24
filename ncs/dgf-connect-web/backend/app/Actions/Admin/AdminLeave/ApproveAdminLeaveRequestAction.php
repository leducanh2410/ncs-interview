<?php

namespace App\Actions\Admin\AdminLeave;

use App\Actions\ActionResult;
use App\Enums\AdminLeaveRequestStatus;
use App\Enums\ErrorCode;
use App\Models\AdminLeaveRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApproveAdminLeaveRequestAction
{
    public function __invoke(User $decider, AdminLeaveRequest $request): ActionResult
    {
        // Refused in the action rather than only hidden in the UI. Checked before the lock,
        // since it needs no consistency guarantee.
        if ($decider->id === $request->requester_id) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        return DB::transaction(function () use ($decider, $request) {
            // Re-fetch under a row lock so two managers deciding the same request serialize here
            // instead of both racing past the pending check.
            $locked = AdminLeaveRequest::query()->lockForUpdate()->findOrFail($request->id);

            // An existing decision is never overwritten, whichever way it went.
            if ($locked->status !== AdminLeaveRequestStatus::Pending) {
                return new ActionResult(error: ErrorCode::AdminLeaveRequestNotPending, status: 409);
            }

            $locked->update([
                'status' => AdminLeaveRequestStatus::Approved->value,
                'decided_by_id' => $decider->id,
                'decided_at' => now(),
            ]);

            return new ActionResult(data: $locked->fresh(['requester', 'decidedBy']));
        });
    }
}
