<?php

namespace App\Actions\Admin\User;

use App\Enums\ShiftUserStatus;
use App\Events\ShiftUserRejected;
use App\Models\CheckIn;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\User;
use Illuminate\Support\Carbon;

class RejectUpcomingShiftsAction
{
    public function __invoke(User $user, int $approverId): int
    {
        $tomorrow = Carbon::tomorrow()->toDateString();

        $shiftUsers = ShiftUser::query()->with('shift')->upcomingForUser($user->id)->get();

        $approvedAt = now();
        foreach ($shiftUsers as $shiftUser) {
            $shiftUser->update([
                'status' => ShiftUserStatus::Rejected->value,
                'confirmed_at' => null,
                'approver_id' => $approverId,
                'approved_at' => $approvedAt,
            ]);

            CheckIn::query()->where('shift_id', $shiftUser->shift_id)->where('user_id', $user->id)->delete();

            ShiftUserRejected::dispatch(Carbon::parse($shiftUser->shift->date), $shiftUser);
        }

        ShiftPlanDraft::query()->where('user_id', $user->id)->whereDate('date', '>=', $tomorrow)->delete();

        return $shiftUsers->count();
    }
}
