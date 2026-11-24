<?php

namespace App\Actions\User\Salary;

use App\Actions\ActionResult;
use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\ErrorCode;
use App\Models\SalaryRequest;
use Illuminate\Support\Facades\Auth;

readonly class GetSalaryInfoAction
{
    /**
     * The salary screen an employee opens before asking for an advance.
     *
     * `amount.min`/`amount.max` are the raw balance - what the unpaid months are worth - while
     * `amount.min_transfer`/`amount.max_transfer`, computed by SalaryService, are the bounds a
     * request has to land between: stepped to a full 1000 yen, leaving the transfer fee
     * payable, and capped at salary.request.max_transfer_amount. The client shows the former
     * and requests against the latter.
     */
    public function __invoke(): ActionResult
    {
        if (SalaryRequest::hasActiveRequest(Auth::id())) {
            return new ActionResult(error: ErrorCode::SalaryRequestExists, status: 400);
        }

        return (new CollectUserSalaryAction())(Auth::id());
    }
}
