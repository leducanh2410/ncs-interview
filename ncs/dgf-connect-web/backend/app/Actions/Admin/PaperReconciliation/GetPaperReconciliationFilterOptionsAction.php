<?php

namespace App\Actions\Admin\PaperReconciliation;

use App\Actions\ActionResult;
use App\Models\Shift;
use App\Models\Workplace;
use App\Models\WorkplaceShift;

/**
 * Everything the filter bar offers, served under the reconciliation permission itself:
 * the workplaces to choose from, and — once a workplace and a day are picked — the
 * shifts that actually ran, so the admin cannot pick a combination the list will answer
 * with nothing.
 */
class GetPaperReconciliationFilterOptionsAction
{
    public function __invoke(?int $workplaceId, ?string $date): ActionResult
    {
        // The reconciliation admin needs a workplace to filter by but has no business on
        // the workplace administration screens, so the options travel with this call
        // rather than sending the page to an endpoint gated by workplace.general.list.
        $workplaces = Workplace::query()
            ->select('id', 'name')
            ->orderBy('id')
            ->get()
            ->map(fn(Workplace $workplace) => ['id' => $workplace->id, 'name' => $workplace->name])
            ->all();

        if ($workplaceId === null || $date === null) {
            return new ActionResult(data: ['workplaces' => $workplaces, 'workplace_shifts' => []]);
        }

        // WorkplaceShift has no shifts() relation, so the roster is read from shifts
        // directly: the templates that actually ran at this workplace on this day.
        $ranThatDay = Shift::query()
            ->where('workplace_id', $workplaceId)
            ->where('date', $date)
            ->distinct()
            ->pluck('workplace_shift_id');

        $shifts = WorkplaceShift::query()
            ->whereIn('id', $ranThatDay)
            ->orderBy('start_time')
            ->get(['id', 'start_time', 'end_time']);

        return new ActionResult(
            data: [
                'workplaces' => $workplaces,
                'workplace_shifts' => $shifts
                    ->map(
                        fn(WorkplaceShift $shift) => [
                            'id' => $shift->id,
                            'start_time' => $shift->start_time,
                            'end_time' => $shift->end_time,
                            // workplace_shifts carries no name column; the app labels a shift
                            // by its hours, as AnalyzeDeploymentWindowAction already does.
                            'label' => $shift->start_time . '-' . $shift->end_time,
                        ]
                    )
                    ->all(),
            ]
        );
    }
}
