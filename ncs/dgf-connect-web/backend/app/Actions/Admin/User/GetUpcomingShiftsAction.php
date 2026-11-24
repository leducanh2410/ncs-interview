<?php

namespace App\Actions\Admin\User;

use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GetUpcomingShiftsAction
{
    /**
     * Shifts the employee is still expected on.
     *
     * `$withinDays` narrows it to a window starting now: a warning about a decision taken
     * this minute is about the shifts that decision reaches, and a shift that already
     * started is not one of them. Without it the whole future is returned, unbounded,
     * which is what locking an account means by upcoming.
     */
    public function __invoke(User $user, ?int $withinDays = null): Collection
    {
        $from = $withinDays === null ? null : Carbon::now();

        return ShiftUser::query()
            ->select('shift_users.*')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->join('workplace_shifts', 'workplace_shifts.id', '=', 'shifts.workplace_shift_id')
            ->with(['shift.workplace:id,name', 'shift.workplaceShift'])
            ->upcomingForUser($user->id, $from)
            ->when(
                $from !== null,
                fn($query) => $query
                    // Compared against the shift's start, not its date, so a shift that
                    // began earlier today drops out while tonight's night shift stays
                    ->whereRaw("CONCAT(shifts.date, ' ', workplace_shifts.start_time) >= ?", [
                        $from->format('Y-m-d H:i:s'),
                    ])
                    ->whereDate('shifts.date', '<=', $from->copy()->addDays($withinDays)->toDateString())
            )
            ->orderBy('shifts.date')
            ->orderBy('workplace_shifts.start_time')
            ->get()
            ->map(
                fn(ShiftUser $shiftUser) => [
                    'id' => $shiftUser->id,
                    'shift_id' => $shiftUser->shift_id,
                    'date' => $shiftUser->shift->date,
                    'status' => $shiftUser->status,
                    'is_fixed' => (bool) $shiftUser->is_fixed,
                    'workplace_name' => $shiftUser->shift->workplace->name,
                    'start_time' => $shiftUser->shift->workplaceShift->start_time,
                    'end_time' => $shiftUser->shift->workplaceShift->end_time,
                ]
            );
    }
}
