<?php

namespace App\Observers;

use App\Actions\Common\CheckIn\RecalculateWorkplaceShiftAttendanceDeviationAction;
use App\Models\WorkplaceShift;

class WorkplaceShiftAttendanceDeviationObserver
{
    public function __construct(private readonly RecalculateWorkplaceShiftAttendanceDeviationAction $recalculate) {}

    public function updated(WorkplaceShift $workplaceShift): void
    {
        if (!$workplaceShift->wasChanged(['start_time', 'end_time'])) {
            return;
        }

        ($this->recalculate)($workplaceShift);
    }
}
