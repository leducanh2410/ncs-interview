<?php

namespace App\Services\Common;

use App\Enums\AbsentNoticeStatus;
use App\Enums\ShiftUserStatus;
use Closure;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AbsenceService
{
    public function getWorkplaceMemberAbsenceCount(int $workplaceId): array
    {
        $windowDays = $this->windowDays();
        $today = Carbon::today();

        return DB::table('shift_users')
            ->join('workplace_users', function ($join) use ($workplaceId) {
                $join
                    ->on('workplace_users.user_id', '=', 'shift_users.user_id')
                    ->where('workplace_users.workplace_id', $workplaceId);
            })
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->leftJoin('shift_absent_notices', self::standingAbsentNoticeJoin())
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->where('shifts.date', '<=', $today->copy()->subDays(2)->toDateString())
            ->where('shifts.date', '>=', $today->copy()->subDays($windowDays)->toDateString())
            ->whereNull('check_ins.id')
            ->whereNull('shift_absent_notices.id')
            ->groupBy('shift_users.user_id')
            ->selectRaw('shift_users.user_id, COUNT(*) as absence_count')
            ->pluck('absence_count', 'user_id')
            ->map(fn($count) => (int) $count)
            ->toArray();
    }

    public function getUserAbsenceCount(int|array $userIds): int|array
    {
        $single = is_int($userIds);
        $ids = $single ? [$userIds] : array_values($userIds);

        if (empty($ids)) {
            return $single ? 0 : [];
        }

        $windowDays = $this->windowDays();
        $today = Carbon::today();

        $results = DB::table('shift_users')
            ->join('shifts', 'shifts.id', '=', 'shift_users.shift_id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->leftJoin('shift_absent_notices', self::standingAbsentNoticeJoin())
            ->whereIn('shift_users.user_id', $ids)
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->where('shifts.date', '<=', $today->copy()->subDays(2)->toDateString())
            ->where('shifts.date', '>=', $today->copy()->subDays($windowDays)->toDateString())
            ->whereNull('check_ins.id')
            ->whereNull('shift_absent_notices.id')
            ->groupBy('shift_users.user_id')
            ->selectRaw('shift_users.user_id, COUNT(*) as absence_count')
            ->get()
            ->pluck('absence_count', 'user_id')
            ->map(fn($count) => (int) $count)
            ->toArray();

        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = $results[$id] ?? 0;
        }

        return $single ? $counts[$ids[0]] : $counts;
    }

    /**
     * The join condition every absence statistic shares. Once an admin refuses a request it stops
     * standing as a request at all - the employee kept the shift and was told to work it - so it
     * must neither excuse a missing check-in here nor feed the forecast's `absent_notice_rate`.
     * Kept in one place so the two readings cannot drift apart.
     */
    public static function standingAbsentNoticeJoin(): Closure
    {
        return function (JoinClause $join) {
            $join
                ->on('shift_absent_notices.shift_user_id', '=', 'shift_users.id')
                ->where('shift_absent_notices.status', '!=', AbsentNoticeStatus::Rejected->value);
        };
    }

    private function windowDays(): int
    {
        $windowDays = (int) config('absence.window_days', 180);

        return $windowDays > 0 ? $windowDays : 180;
    }
}
