<?php

namespace App\Observers;

use App\Actions\Common\CheckIn\CalculateAttendanceDeviationAction;
use App\Models\CheckIn;

class CheckInAttendanceDeviationObserver
{
    public function __construct(private readonly CalculateAttendanceDeviationAction $calculate) {}

    public function saving(CheckIn $checkIn): void
    {
        if ($checkIn->exists && !$checkIn->isDirty(['check_in_time', 'check_out_time', 'shift_id'])) {
            return;
        }

        if ($checkIn->isDirty('shift_id')) {
            $checkIn->load('shift.workplaceShift');
        } else {
            $checkIn->loadMissing('shift.workplaceShift');
        }
        if (!$checkIn->shift?->workplaceShift) {
            return;
        }

        $checkIn->fill(($this->calculate)($checkIn));
    }
}
