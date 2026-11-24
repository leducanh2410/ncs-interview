<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Models\Salary;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ImportSalaryDeductionAction
{
    const HEADERS = [
        0 => '社員コード',
        34 => '前払い',
        35 => '前払い手数料',
        36 => '控除合計',
    ];

    public function __invoke(string $month, Collection $dataRows): ActionResult
    {
        $header = $dataRows->get(1);
        foreach (self::HEADERS as $index => $headerName) {
            $value = $header[$index] ?? null;
            if ($value !== $headerName) {
                return new ActionResult(error: ErrorCode::SalaryCsvMalformed, status: 400);
            }
        }

        $dataRows = $dataRows->slice(2);

        $staffNos = $dataRows->pluck(0)->unique()->values()->toArray();
        $users = User::query()->whereIn('staff_no', $staffNos)->get()->pluck('id', 'staff_no');
        $salaries = Salary::query()
            ->where('month', $month)
            ->whereIn('user_id', $users->values()->toArray())
            ->get()
            ->keyBy('user_id');

        $errorData = [];
        $validData = [];
        $skippedData = [];
        foreach ($dataRows as $index => $dataRow) {
            $staffNo = $dataRow[0] ?? null;
            $totalPaid = $dataRow[34] ?? null;
            $totalFee = $dataRow[35] ?? null;
            $totalSubtraction = $dataRow[36] ?? null;
            $line = $index + 1;

            if (null === $staffNo || !isset($users[$staffNo])) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryCsvInvalidStaffNo,
                ];

                continue;
            }

            if (
                !is_numeric($totalPaid) ||
                $totalPaid < 0 ||
                !is_numeric($totalFee) ||
                $totalFee < 0 ||
                !is_numeric($totalSubtraction) ||
                $totalSubtraction < 0
            ) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryRequestInvalidAmount,
                ];

                continue;
            }

            $totalDeduction = $totalSubtraction - $totalPaid - $totalFee;
            if ($totalDeduction < 0) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryRequestInvalidAmount,
                ];

                continue;
            }

            $salary = $salaries[$users[$staffNo]] ?? null;

            if (!$salary) {
                // Any money on the row at all, not just a positive net deduction: an advance
                // that its own fee cancels out still means payroll moved money in a month this
                // system has not calculated yet. (The guard above leaves subtraction >= paid +
                // fee, so a zero subtraction is the only genuinely empty row.)
                if ($totalSubtraction > 0) {
                    // The payroll system moved money in a month this system has not calculated
                    // yet - no work history recorded, or UpdateUserSalary has not run for it.
                    // Booking against a month worth nothing would leave it owing more than it
                    // holds, which later makes the bank return file unbookable, so say what is
                    // actually wrong instead.
                    $errorData[] = [
                        'line' => $line,
                        'staff_no' => $staffNo,
                        'error_code' => ErrorCode::SalaryCsvSalaryNotFound,
                    ];

                    continue;
                }

                // An entirely empty row carries no information, and opening a settled 0 yen
                // month would lock it against every later recalculation - work history added
                // afterwards (a late check-in fix, an added shift) would never be counted. It
                // is still reported back, so the admin can see which employees the payroll
                // file covers but this system has not calculated a salary for.
                $skippedData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                ];

                continue;
            }

            $remain = $salary->total_amount - $salary->total_fee - $salary->total_paid - $totalDeduction;
            if ($remain < 0) {
                $errorData[] = [
                    'line' => $line,
                    'staff_no' => $staffNo,
                    'error_code' => ErrorCode::SalaryCsvAmountTooHigh,
                ];

                continue;
            }

            $validData[$staffNo] = [
                'total_deductions' => $totalDeduction,
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

        // Only months the system already knows about reach this point, so every row is an
        // update: the import settles a month, it never invents one.
        DB::transaction(function () use ($validData, $users, $salaries) {
            foreach ($validData as $staffNo => $data) {
                $salaries[$users[$staffNo]]->update([
                    'total_deductions' => $data['total_deductions'],
                    'is_finalized' => true,
                ]);
            }
        });

        return new ActionResult(
            data: [
                'error_data' => $errorData,
                'valid_data' => $validData,
                'skipped_data' => $skippedData,
            ]
        );
    }
}
