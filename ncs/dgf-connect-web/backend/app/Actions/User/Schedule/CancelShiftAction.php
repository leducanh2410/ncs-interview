<?php

namespace App\Actions\User\Schedule;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\ShiftUserStatus;
use App\Models\ShiftUser;
use App\Models\User;

class CancelShiftAction
{
    public function __invoke(ShiftUser $shiftUser, User $authUser): ActionResult
    {
        if ($shiftUser->user_id !== $authUser->id) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if ($shiftUser->status !== ShiftUserStatus::Pending->value) {
            return new ActionResult(error: ErrorCode::ShiftUserInvalidStatus, status: 400);
        }

        $shiftUser->update(['status' => ShiftUserStatus::Rejected->value]);

        return new ActionResult(data: $shiftUser);
    }
}
