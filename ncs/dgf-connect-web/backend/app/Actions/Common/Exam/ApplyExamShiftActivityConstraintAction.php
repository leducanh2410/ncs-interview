<?php

namespace App\Actions\Common\Exam;

use App\Enums\ShiftUserStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

readonly class ApplyExamShiftActivityConstraintAction
{
    public function __invoke(Builder|QueryBuilder $query, int|string $user, string $workplaceColumn): void
    {
        $windowStart = now()->subDays(config('exam.eligibility_shift_lookback_days'))->toDateString();

        $query->whereExists(function (QueryBuilder $sub) use ($user, $workplaceColumn, $windowStart) {
            $sub->selectRaw('1')
                ->from('shift_users')
                ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
                ->where('shift_users.status', ShiftUserStatus::Approved->value)
                ->where('shift_users.is_absent', false)
                ->whereColumn('shifts.workplace_id', $workplaceColumn)
                ->where('shifts.date', '>=', $windowStart);

            is_int($user) ? $sub->where('shift_users.user_id', $user) : $sub->whereColumn('shift_users.user_id', $user);
        });
    }
}
