<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Exports\SalaryExport;
use App\Helpers\DateTimeHelper;
use App\Models\Salary;

class ExportSalariesAction
{
    public function __invoke(array $conditions): ActionResult
    {
        $month = $conditions['month'] ?? now()->format('Y-m');
        $userId = $conditions['user_id'] ?? null;

        $salaries = Salary::query()
            ->with('user')
            ->leftJoin('work_time_reports', function ($join) use ($month) {
                $join->on('work_time_reports.user_id', 'salaries.user_id')->where('work_time_reports.month', $month);
            })
            ->when($userId, function ($query) use ($userId) {
                $query->where('salaries.user_id', $userId);
            })
            ->where('salaries.month', $month)
            ->select([
                'salaries.*',
                'work_time_reports.work_days',
                'work_time_reports.work_minutes',
                'work_time_reports.work_minutes_normal',
                'work_time_reports.work_minutes_overtime',
                'work_time_reports.work_minutes_night',
                'work_time_reports.work_minutes_night_overtime',
            ])
            ->get();

        $collection = collect();
        foreach ($salaries as $salary) {
            $diff = 0;
            if ($salary->min_salary !== $salary->max_salary) {
                $totalBaseSalary = floor(($salary->min_salary * $salary->work_minutes) / 60);
                $diff = $salary->total_amount - $totalBaseSalary;
            }
            $totalRemaining =
                $salary->total_amount - $salary->total_paid - $salary->total_deductions - $salary->total_fee;
            $collection->push([
                'staff_no' => $salary->user->staff_no,
                'name' => $salary->user->short_name,
                'name_kana' => $salary->user->name_kana,
                'work_days' => $salary->work_days,
                'work_hours_normal' => DateTimeHelper::formatMinuteToTime($salary->work_minutes_normal),
                'work_hours_overtime' => DateTimeHelper::formatMinuteToTime($salary->work_minutes_overtime),
                'work_hours_night' => DateTimeHelper::formatMinuteToTime($salary->work_minutes_night),
                'work_hours_night_overtime' => DateTimeHelper::formatMinuteToTime($salary->work_minutes_night_overtime),
                'base_salary' => $salary->min_salary,
                'bonus' => '',
                'diff' => $diff,
                'total_paid' => $salary->total_paid,
                'total_fee' => $salary->total_fee,
                'total_deductions' => $salary->total_deductions,
                'total_remaining' => $totalRemaining,
            ]);
        }

        return new ActionResult(
            data: [
                'export' => new SalaryExport($collection),
                'name' => 'salary_' . str_replace('-', '', $month) . '.xlsx',
            ]
        );
    }
}
