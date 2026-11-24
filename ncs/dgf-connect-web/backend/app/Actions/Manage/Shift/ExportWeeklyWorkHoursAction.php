<?php

namespace App\Actions\Manage\Shift;

use App\Actions\ActionResult;
use App\Exports\WeeklyWorkHoursExport;
use App\Models\User;
use Illuminate\Support\Facades\App;

class ExportWeeklyWorkHoursAction
{
    public function __construct(private readonly GetWeeklyWorkHoursAction $getWeeklyWorkHours) {}

    public function __invoke(User $actor, array $filters): ActionResult
    {
        $report = $this->getWeeklyWorkHours->collectAll($actor, $filters);

        return new ActionResult(
            data: [
                'export' => new WeeklyWorkHoursExport($report['weeks'], $report['rows']->all(), App::getLocale()),
                'name' => "weekly_work_hours_{$filters['dates'][0]}_{$filters['dates'][1]}.xlsx",
            ]
        );
    }
}
