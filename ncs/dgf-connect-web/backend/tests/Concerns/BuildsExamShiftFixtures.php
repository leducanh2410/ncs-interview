<?php

namespace Tests\Concerns;

use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplaceShift;

trait BuildsExamShiftFixtures
{
    /**
     * @param iterable<int> $workplaceIds
     */
    protected function giveExamShifts(User $user, iterable $workplaceIds): void
    {
        foreach ($workplaceIds as $workplaceId) {
            $this->giveExamShift($user, (int) $workplaceId);
        }
    }

    protected function giveExamShift(
        User $user,
        Workplace|int $workplace,
        ?string $date = null,
        ShiftUserStatus $status = ShiftUserStatus::Approved,
        bool $isAbsent = false
    ): ShiftUser {
        $workplaceId = $workplace instanceof Workplace ? $workplace->id : $workplace;
        $date ??= now()->toDateString();

        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplaceId,
            'start_time' => '09:00:00',
            'end_time' => '18:00:00',
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);

        $shift = Shift::create([
            'workplace_id' => $workplaceId,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $user->id,
            'date' => $date,
            'adjusted_date' => $date,
            'temp_total' => 0,
            'fixed_total' => 0,
        ]);

        return ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $user->id,
            'status' => $status->value,
            'is_fixed' => false,
            'is_absent' => $isAbsent,
        ]);
    }

    protected function clearExamShifts(Workplace $workplace): void
    {
        Shift::query()->where('workplace_id', $workplace->id)->delete();
        WorkplaceShift::query()->where('workplace_id', $workplace->id)->delete();
    }
}
