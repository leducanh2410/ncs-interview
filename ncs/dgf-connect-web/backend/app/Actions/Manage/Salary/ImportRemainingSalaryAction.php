<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Actions\Common\Salary\CollectUserSalaryAction;
use App\Enums\ErrorCode;
use App\Helpers\DateTimeHelper;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ImportRemainingSalaryAction
{
    const HEADERS = [
        0 => '月',
        1 => '社員コード',
        2 => '残額',
    ];

    public function __invoke(Collection $dataRows): ActionResult
    {
        $header = $dataRows->first();
        foreach (self::HEADERS as $index => $headerName) {
            $value = $header[$index] ?? null;
            if ($value !== $headerName) {
                return new ActionResult(error: ErrorCode::SalaryCsvMalformed, status: 400);
            }
        }

        $dataRows = $dataRows->slice(1);

        $staffNos = $dataRows->pluck(1)->unique()->values()->toArray();
        $months = $dataRows->pluck(0)->unique()->values()->toArray();
        $users = User::query()->whereIn('staff_no', $staffNos)->get()->pluck('id', 'staff_no');
        // The cut-off is a full date but the rows carry a month, so compare like with like:
        // the month the system took over is its own to calculate and must not be imported.
        $startMonth = mb_substr((string) config('salary.start_date'), 0, 7);

        $salaries = Salary::query()
            ->whereIn('user_id', $users->values()->toArray())
            ->whereIn('month', $months)
            ->get()
            ->mapWithKeys(function ($salary) {
                return [$salary->user_id . '_' . $salary->month => $salary];
            });

        $errorData = [];
        $validData = [];
        foreach ($dataRows as $index => $dataRow) {
            $month = $dataRow[0] ?? null;
            $staffNo = $dataRow[1] ?? null;
            $amount = $dataRow[2] ?? null;
            $line = $index + 1;

            if (!$month || !DateTimeHelper::isValidFormat($month, 'Y-m') || $month >= $startMonth) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryCsvInvalidMonth,
                ];

                continue;
            }

            if (!$staffNo) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryCsvInvalidStaffNo,
                ];

                continue;
            }

            $userId = $users[$staffNo] ?? null;
            if (!$userId) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryCsvInvalidStaffNo,
                ];

                continue;
            }

            if (!is_numeric($amount) || $amount < 0) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryCsvInvalidAmount,
                ];

                continue;
            }

            $salary = $salaries[$userId . '_' . $month] ?? null;
            $validData[] = [
                'line' => $line,
                'salary' => $salary,
                'amount' => $amount,
                'month' => $month,
                'user_id' => $userId,
            ];
        }

        if (count($errorData) > 0) {
            return new ActionResult(
                error: ErrorCode::UnprocessableEntity,
                status: 422,
                data: [
                    'error_data' => $errorData,
                ]
            );
        }

        DB::transaction(function () use ($validData) {
            foreach ($validData as $data) {
                $salary = $data['salary'];
                if (!$salary) {
                    Salary::query()->create([
                        'user_id' => $data['user_id'],
                        'month' => $data['month'],
                        'total_amount' => $data['amount'],
                        'total_deductions' => 0,
                        'total_fee' => 0,
                        'total_paid' => 0,
                        'total_remaining' => $data['amount'],
                        'min_salary' => 0,
                        'max_salary' => 0,
                        'total_minutes' => 0,
                        'is_completed' => true,
                        'is_finalized' => true,
                    ]);
                } else {
                    $salary->update([
                        'total_amount' => $data['amount'],
                        'total_deductions' => 0,
                        'total_fee' => 0,
                        'total_paid' => 0,
                        'total_remaining' => $data['amount'],
                        'is_completed' => true,
                        'is_finalized' => true,
                    ]);
                }

                Cache::forget(CollectUserSalaryAction::CACHE_KEY_PREFIX . $data['user_id']);
            }
        });

        return new ActionResult(data: []);
    }
}
