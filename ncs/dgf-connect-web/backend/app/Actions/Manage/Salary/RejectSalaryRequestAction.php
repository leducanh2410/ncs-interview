<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;

class RejectSalaryRequestAction
{
    public function __invoke(SalaryRequest $salaryRequest, string $reason): ActionResult
    {
        if ($salaryRequest->status !== SalaryRequestStatus::WaitForApproval->value) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $salaryRequest->update([
            'status' => SalaryRequestStatus::Rejected,
            'rejected_at' => now(),
            'approver_id' => auth()->id(),
            'reject_reason' => $reason,
        ]);

        return new ActionResult(data: $salaryRequest);
    }
}
