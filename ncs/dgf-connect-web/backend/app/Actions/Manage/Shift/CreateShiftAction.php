<?php

namespace App\Actions\Manage\Shift;

use App\Models\Shift;
use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;

/**
 * Persists one concrete dated Shift from a WorkplaceShift template. Pure persistence: callers own
 * authorization and any active-type gating. `adjusted_date` is derived from the template's
 * `date_adjustment`.
 */
class CreateShiftAction
{
    public function __invoke(
        WorkplaceShift $workplaceShift,
        string $date,
        int $creatorId,
        int $fixedTotal,
        int $tempTotal,
        ?int $maxRegisteredTemp,
        ?int $breakMinutes
    ): Shift {
        return Shift::query()->create([
            'workplace_id' => $workplaceShift->workplace_id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $creatorId,
            'date' => $date,
            'adjusted_date' => Carbon::parse($date)->addDays($workplaceShift->date_adjustment)->format('Y-m-d'),
            'temp_total' => $tempTotal,
            'fixed_total' => $fixedTotal,
            'max_registered_temp' => $maxRegisteredTemp,
            'break_minutes' => $breakMinutes,
        ]);
    }
}
