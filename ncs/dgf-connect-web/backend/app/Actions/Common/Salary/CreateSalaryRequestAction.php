<?php

namespace App\Actions\Common\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\SalaryRequestStatus;
use App\Enums\SalaryRequestType;
use App\Facades\SalaryFacade;
use App\Models\SalaryRequest;
use Illuminate\Support\Facades\DB;

class CreateSalaryRequestAction
{
    public function __invoke(int $userId, int $amount, string $type): ActionResult
    {
        if (SalaryRequest::hasActiveRequest($userId)) {
            return new ActionResult(error: ErrorCode::SalaryRequestExists, status: 400);
        }

        $salaries = SalaryFacade::getUnpaidSalaries($userId);

        $finalizedAmounts = [];
        $unfinalizedAmounts = [];
        $maxAmount = 0;
        foreach ($salaries as $salary) {
            $monthAmount = $salary->getPayableAmount();
            $maxAmount += $monthAmount;
            if ($salary->is_finalized) {
                $finalizedAmounts[$salary->month] = $monthAmount;
            } else {
                $unfinalizedAmounts[$salary->month] = $monthAmount;
            }
        }
        $monthlyData = SalaryFacade::calculateMonthlyAmount(
            $finalizedAmounts,
            $unfinalizedAmounts,
            $amount,
            $type === SalaryRequestType::Transfer->value
        );

        if (!$monthlyData) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'amount' => ['The amount is invalid.'],
                ]
            );
        }

        $salaryRequest = DB::transaction(function () use (
            $userId,
            $amount,
            $type,
            $maxAmount,
            $salaries,
            $monthlyData
        ) {
            $salaryRequest = SalaryRequest::query()->create([
                'user_id' => $userId,
                'max_amount' => $maxAmount,
                'amount' => $amount,
                'status' => SalaryRequestStatus::WaitForApproval,
                'type' => $type,
            ]);

            $salaryByMonth = $salaries->keyBy('month');
            foreach ($monthlyData['amounts'] as $monthData) {
                $month = $monthData['month'];
                $monthAmount = $monthData['amount'];
                $data = [
                    'salary_id' => $salaryByMonth[$month]->id,
                    'amount' => $monthAmount,
                ];

                if ($monthlyData['fee_month'] === $month) {
                    $data['has_fee'] = true;
                }

                $salaryRequest->details()->create($data);
            }

            return $salaryRequest;
        });

        return new ActionResult(data: $salaryRequest);
    }
}
