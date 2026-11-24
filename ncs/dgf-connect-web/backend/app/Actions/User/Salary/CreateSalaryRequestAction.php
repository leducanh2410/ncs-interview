<?php

namespace App\Actions\User\Salary;

use App\Actions\ActionResult;
use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Actions\Common\Salary\CreateSalaryRequestAction as CommonCreateSalaryRequestAction;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestType;
use App\Models\SalaryRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

readonly class CreateSalaryRequestAction
{
    public function __invoke(int $amount): ActionResult
    {
        if (SalaryRequest::hasActiveRequest(Auth::id())) {
            return new ActionResult(error: ErrorCode::SalaryRequestExists, status: 400);
        }

        $canRequestAmount = Cache::get(CollectUserSalaryAction::CACHE_KEY_PREFIX . Auth::id());
        if (!$canRequestAmount) {
            $result = (new CollectUserSalaryAction())(Auth::id());
            if ($result->getError()) {
                return $result;
            }

            $canRequestAmount = $result->getData()['amount'];
        }

        $canRequestAmount['max'] = min($canRequestAmount['max'], config('salary.request.max_transfer_amount'));

        if ($amount < $canRequestAmount['min'] || $amount > $canRequestAmount['max']) {
            return new ActionResult(error: ErrorCode::SalaryRequestInvalidAmount, status: 400);
        }

        return (new CommonCreateSalaryRequestAction())(Auth::id(), $amount, SalaryRequestType::Transfer->value);
    }
}
