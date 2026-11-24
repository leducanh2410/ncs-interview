<?php

namespace App\Actions\User\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;
use Illuminate\Support\Facades\Auth;

class ConfirmReceivedAction
{
    public function __invoke(SalaryRequest $salaryRequest): ActionResult
    {
        // The route binds {salaryRequest} by bare id, so ownership is this action's to check.
        // Checked before the status so a stranger cannot probe which payments are outstanding.
        if ($salaryRequest->user_id !== Auth::id()) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if ($salaryRequest->status !== SalaryRequestStatus::Paid->value) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $salaryRequest->update([
            'status' => SalaryRequestStatus::Received,
            'payment_confirm_id' => Auth::id(),
            'payment_confirmed_at' => now(),
        ]);

        return new ActionResult(data: $salaryRequest);
    }
}
