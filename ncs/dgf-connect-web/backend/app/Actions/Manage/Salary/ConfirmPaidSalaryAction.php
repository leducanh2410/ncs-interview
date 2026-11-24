<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;

class ConfirmPaidSalaryAction
{
    public function __invoke(SalaryRequest $salaryRequest): ActionResult
    {
        if ($salaryRequest->status !== SalaryRequestStatus::WaitForPayment->value) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $salaryRequest->update([
            'status' => SalaryRequestStatus::Paid,
            'paid_at' => now(),
        ]);

        return new ActionResult(data: $salaryRequest);
    }
}
