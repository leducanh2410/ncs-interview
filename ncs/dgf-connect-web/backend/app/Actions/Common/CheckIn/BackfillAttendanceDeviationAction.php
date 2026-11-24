<?php

namespace App\Actions\Common\CheckIn;

use App\Models\CheckIn;
use Illuminate\Database\Eloquent\Collection;

class BackfillAttendanceDeviationAction
{
    public function __construct(private readonly CalculateAttendanceDeviationAction $calculate) {}

    public function __invoke(): void
    {
        CheckIn::query()
            ->with(['shift.workplaceShift', 'shiftUser.report'])
            ->chunkById(500, function (Collection $checkIns) {
                foreach ($checkIns as $checkIn) {
                    if (!$checkIn->shift?->workplaceShift) {
                        continue;
                    }

                    $report = $checkIn->shiftUser?->report;
                    $deviation = $report
                        ? [
                            'late_minutes' => (int) $report->late_minutes,
                            'early_minutes' => (int) $report->early_minutes,
                        ]
                        : ($this->calculate)($checkIn);
                    if ($deviation['late_minutes'] === 0 && $deviation['early_minutes'] === 0) {
                        continue;
                    }
                    CheckIn::query()->where('id', $checkIn->id)->update($deviation);
                }
            });
    }
}
