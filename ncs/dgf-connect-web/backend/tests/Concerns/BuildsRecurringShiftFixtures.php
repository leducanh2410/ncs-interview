<?php

namespace Tests\Concerns;

use App\Enums\ShiftUserStatus;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use Carbon\CarbonImmutable;

trait BuildsRecurringShiftFixtures
{
    /** @var array<int, WorkplaceShift> */
    private array $recurringWorkplaceShifts = [];

    protected function recurringShift(
        User $user,
        Workplace $workplace,
        string $date,
        bool $worked = false,
        ShiftUserStatus $status = ShiftUserStatus::Approved,
        bool $isAbsent = false
    ): ShiftUser {
        $workplaceShift = $this->recurringWorkplaceShifts[$workplace->id] ??= WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => $status->value,
            'is_fixed' => false,
            'is_absent' => $isAbsent,
        ]);

        if ($worked) {
            $this->recurringCheckOut($shiftUser);
        }

        return $shiftUser;
    }

    /**
     * @return list<ShiftUser>
     */
    protected function recurringWorkedDays(User $user, Workplace $workplace, string $firstDate, int $count): array
    {
        $shiftUsers = [];
        $date = CarbonImmutable::parse($firstDate);
        for ($i = 0; $i < $count; $i++) {
            $shiftUsers[] = $this->recurringShift($user, $workplace, $date->toDateString(), worked: true);
            $date = $date->addDay();
        }

        return $shiftUsers;
    }

    protected function recurringCheckOut(ShiftUser $shiftUser): CheckIn
    {
        $date = $shiftUser->shift->date;

        return CheckIn::query()->updateOrCreate(
            ['shift_user_id' => $shiftUser->id],
            [
                'shift_id' => $shiftUser->shift_id,
                'user_id' => $shiftUser->user_id,
                'check_in_time' => $date . ' 09:00:00',
                'check_out_time' => $date . ' 18:00:00',
            ]
        );
    }
}
