<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\SalaryRequestStatus;
use App\Models\SalaryRequest;
use App\Models\User;

class ApproveSalaryRequestAction
{
    public function __invoke(SalaryRequest $salaryRequest): ActionResult
    {
        if (
            !in_array($salaryRequest->status, [
                SalaryRequestStatus::WaitForApproval->value,
                SalaryRequestStatus::WaitForApprovalAccountant->value,
            ])
        ) {
            return new ActionResult(error: ErrorCode::BadRequest, status: 400);
        }

        $needAccountantApproval = $salaryRequest->status === SalaryRequestStatus::WaitForApprovalAccountant->value;
        /** @var User $user */
        $user = auth()->user();
        if ($needAccountantApproval) {
            if (!$user->hasPermission(PermissionName::SalaryRequestApproveAccountant->value)) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }

            $salaryRequest->update([
                'status' => SalaryRequestStatus::WaitForPayment,
                'accountant_approved_at' => now(),
                'accountant_approver_id' => auth()->id(),
            ]);
        } else {
            if (!$user->hasPermission(PermissionName::SalaryRequestApprove->value)) {
                return new ActionResult(error: ErrorCode::Forbidden, status: 403);
            }

            $salaryRequest->update([
                'status' => SalaryRequestStatus::WaitForApprovalAccountant,
                'approved_at' => now(),
                'approver_id' => auth()->id(),
            ]);
        }

        return new ActionResult(data: $salaryRequest);
    }
}
