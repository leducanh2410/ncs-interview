<?php

namespace App\Actions\Common\Recurring;

use App\Enums\ShiftUserStatus;
use App\ValueObjects\Recurring\CountableShift;
use App\ValueObjects\Recurring\ShiftPosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoadCountableShiftsAction
{
    /**
     * @param list<int> $workplaceIds
     * @return Collection<int, list<CountableShift>>
     */
    public function __invoke(int $userId, array $workplaceIds): Collection
    {
        if (empty($workplaceIds)) {
            return collect();
        }

        $today = now()->toDateString();

        return DB::table('shift_users')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->join('workplace_shifts', 'workplace_shifts.id', '=', 'shifts.workplace_shift_id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->where('shift_users.user_id', $userId)
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->where('shift_users.is_absent', false)
            ->whereIn('shifts.workplace_id', $workplaceIds)
            ->where(function ($query) use ($today) {
                $query->whereNotNull('check_ins.check_out_time')->orWhere('shifts.date', '>=', $today);
            })
            ->orderBy('shifts.date')
            ->orderBy('workplace_shifts.start_time')
            ->orderBy('shift_users.id')
            ->get([
                'shift_users.id as shift_user_id',
                'shifts.workplace_id',
                'shifts.date',
                'workplace_shifts.start_time',
                'check_ins.check_out_time',
            ])
            ->map(function (object $row) {
                $date = CarbonImmutable::parse($row->date)->startOfDay();

                return new CountableShift(
                    workplaceId: (int) $row->workplace_id,
                    position: new ShiftPosition(
                        shiftUserId: (int) $row->shift_user_id,
                        date: $date,
                        startAt: CarbonImmutable::parse($date->toDateString() . ' ' . $row->start_time),
                        sortId: (int) $row->shift_user_id
                    ),
                    worked: $row->check_out_time !== null
                );
            })
            ->groupBy(fn(CountableShift $shift) => $shift->workplaceId)
            ->map(fn(Collection $shifts) => $shifts->values()->all());
    }
}
