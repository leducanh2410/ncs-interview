<?php

namespace App\Actions\Common\CheckIn;

use App\Models\CheckIn;
use App\Models\ShiftReport;
use App\Models\WorkplaceShift;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class RecalculateWorkplaceShiftAttendanceDeviationAction
{
    public function __construct(private readonly CalculateAttendanceDeviationAction $calculate) {}

    public function __invoke(WorkplaceShift $workplaceShift): void
    {
        CheckIn::query()
            ->whereHas(
                'shift',
                fn(Builder $query) => $query
                    ->where('workplace_shift_id', $workplaceShift->id)
                    ->where('date', '>=', today()->subDay()->toDateString())
            )
            ->with('shift.workplaceShift')
            ->chunkById(500, function (Collection $checkIns) {
                foreach ($checkIns as $checkIn) {
                    $deviation = ($this->calculate)($checkIn);
                    if (
                        $deviation['late_minutes'] === $checkIn->late_minutes &&
                        $deviation['early_minutes'] === $checkIn->early_minutes
                    ) {
                        continue;
                    }
                    CheckIn::query()->where('id', $checkIn->id)->update($deviation);
                    ShiftReport::query()
                        ->where('shift_user_id', $checkIn->shift_user_id)
                        ->update([
                            'late_minutes' => $deviation['late_minutes'] ?: null,
                            'early_minutes' => $deviation['early_minutes'] ?: null,
                        ]);
                }
            });
    }
}
