<?php

namespace App\Actions\Admin\FixedSchedule;

use App\Actions\ActionResult;
use App\Exports\FixedScheduleChangeRequestExport;
use App\Models\User;

class ExportFixedScheduleChangeRequestsAction
{
    public function __construct(private readonly GetFixedScheduleChangeRequestsAction $getAction) {}

    public function __invoke(User $user, array $filters): ActionResult
    {
        $requests = $this->getAction->buildQuery($user, $filters)->get();

        return new ActionResult(
            data: [
                'export' => new FixedScheduleChangeRequestExport($requests),
                'name' => 'fixed_schedule_change_requests.xlsx',
            ]
        );
    }
}
