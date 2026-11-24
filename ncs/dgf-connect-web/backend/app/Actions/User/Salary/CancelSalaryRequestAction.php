<?php

namespace App\Actions\User\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;
use Illuminate\Support\Facades\Auth;

class CancelSalaryRequestAction
{
    public function __invoke(SalaryRequest $salaryRequest): ActionResult
    {
        // The route binds {salaryRequest} by bare id, so ownership is this action's to check.
        // Checked before the status so a stranger cannot probe which requests are cancellable.
        if ($salaryRequest->user_id !== Auth::id()) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if (
            $salaryRequest->status !== SalaryRequestStatus::WaitForApproval->value &&
            $salaryRequest->status !== SalaryRequestStatus::WaitForApprovalAccountant->value
        ) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $salaryRequest->update([
            'status' => SalaryRequestStatus::Cancelled->value,
            'cancelled_at' => now(),
        ]);

        return new ActionResult(data: $salaryRequest);
    }
}
