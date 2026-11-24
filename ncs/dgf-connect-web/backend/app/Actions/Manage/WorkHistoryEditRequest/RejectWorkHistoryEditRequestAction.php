<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use Illuminate\Support\Facades\DB;

class RejectWorkHistoryEditRequestAction
{
    public function __invoke(User $user, WorkHistoryEditRequest $request, string $rejectReason): ActionResult
    {
        if (!$user->isAbleTo(PermissionName::WorkHistoryEditRequestProcess->value, $request->workplace_id)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        // Defense in depth alongside RejectRequest's FormRequest validation: a rejection without
        // a stated reason is not a decision the submitter can act on.
        if (mb_trim($rejectReason) === '') {
            return new ActionResult(error: ErrorCode::WorkHistoryEditRequestRejectReasonRequired, status: 422);
        }

        return DB::transaction(function () use ($user, $request, $rejectReason) {
            // Re-fetch under a row lock so a concurrent approve/reject on the same request
            // serializes here instead of both racing past the pending check below.
            $locked = WorkHistoryEditRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($locked->status !== WorkHistoryEditRequestStatus::Pending->value) {
                return new ActionResult(error: ErrorCode::WorkHistoryEditRequestNotPending, status: 409);
            }

            $locked->update([
                'status' => WorkHistoryEditRequestStatus::Rejected->value,
                'processed_by_id' => $user->id,
                'processed_at' => now(),
                'reject_reason' => $rejectReason,
            ]);

            return new ActionResult(data: $locked->fresh(['items', 'requestedBy', 'processedBy']));
        });
    }
}
