<?php

namespace App\Actions\Manage\Salary;

use App\Actions\ActionResult;
use App\Models\Salary;
use Illuminate\Support\Collection;

class GetSalariesAction
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
                'work_time_reports.work_minutes_night',
                'work_time_reports.work_minutes_overtime',
                'work_time_reports.work_minutes_night_overtime',
            ])
            ->paginate($conditions['page_size'] ?? null);

        // setRelation to a Collection, not the plain toArray() output: a plain array
        // isn't Arrayable, so Model::relationsToArray() would silently drop it from
        // the JSON response.
        $salaries->through(function (Salary $salary) {
            $salary->setRelation('user', $salary->user ? new Collection($salary->user->toArray()) : null);

            return $salary;
        });

        return new ActionResult(data: $salaries);
    }
}
