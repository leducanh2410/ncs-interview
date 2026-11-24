<?php

namespace App\Services\Common;

use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SimilarShiftService
{
    /**
     * How far apart (in minutes) two shifts' start and end times may be while still counting
     * as the same time range. A shift starting/ending within this window of the target is
     * treated as "similar".
     */
    public const SIMILAR_SHIFT_TIME_WINDOW_MINUTES = 60;

    /**
     * Workplace-shift IDs whose time range is "similar" to the target shift: those whose start
     * and end times each fall within SIMILAR_SHIFT_TIME_WINDOW_MINUTES of the target's, with
     * shifts that cross midnight normalized so an overnight shift still matches its daytime
     * neighbours correctly. Falls back to the target shift's own ID when nothing matches.
     *
     * @param  int[]  $workplaceIds
     * @return int[]
     */
    public function similarWorkplaceShiftIds(array $workplaceIds, WorkplaceShift $targetShift): array
    {
        $targetStart = $this->minutesFromTime((string) $targetShift->start_time);
        $targetEnd = $this->normalizedEndMinutes((string) $targetShift->start_time, (string) $targetShift->end_time);

        $ids = DB::table('workplace_shifts')
            ->whereIn('workplace_id', $workplaceIds)
            ->select('id', 'start_time', 'end_time')
            ->get()
            ->filter(function ($shift) use ($targetStart, $targetEnd) {
                [$start, $end] = $this->normalizedShiftRangeAgainst(
                    $targetStart,
                    (string) $shift->start_time,
                    (string) $shift->end_time
                );

                return abs($start - $targetStart) <= self::SIMILAR_SHIFT_TIME_WINDOW_MINUTES &&
                    abs($end - $targetEnd) <= self::SIMILAR_SHIFT_TIME_WINDOW_MINUTES;
            })
            ->pluck('id')
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();

        return empty($ids) ? [(int) $targetShift->id] : $ids;
    }

    /**
     * Number of shifts each user actually worked (i.e. has a check-in record for) within the
     * given window, restricted to the supplied workplace and its similar workplace-shift IDs.
     * Every requested user id is present in the result, defaulting to 0.
     *
     * @param  int[]  $userIds
     * @param  int[]  $similarWorkplaceShiftIds
     * @return array<int, int>
     */
    public function getSimilarShiftWorkedCount(
        array $userIds,
        int $workplaceId,
        array $similarWorkplaceShiftIds,
        int $windowDays = 90
    ): array {
        $ids = array_values($userIds);
        if (empty($ids) || empty($similarWorkplaceShiftIds)) {
            return array_fill_keys($ids, 0);
        }

        $today = Carbon::today();

        $results = DB::table('check_ins')
            ->join('shifts', 'shifts.id', '=', 'check_ins.shift_id')
            ->whereIn('check_ins.user_id', $ids)
            ->where('shifts.workplace_id', $workplaceId)
            ->whereIn('shifts.workplace_shift_id', $similarWorkplaceShiftIds)
            ->where('shifts.date', '>=', $today->copy()->subDays($windowDays)->toDateString())
            ->where('shifts.date', '<=', $today->toDateString())
            ->groupBy('check_ins.user_id')
            ->selectRaw('check_ins.user_id, COUNT(DISTINCT shifts.id) as worked_count')
            ->pluck('worked_count', 'user_id')
            ->map(fn($count) => (int) $count)
            ->toArray();

        $counts = [];
        foreach ($ids as $id) {
            $counts[$id] = $results[$id] ?? 0;
        }

        return $counts;
    }

    private function normalizedShiftRangeAgainst(int $targetStart, string $startTime, string $endTime): array
    {
        $start = $this->minutesFromTime($startTime);
        $end = $this->normalizedEndMinutes($startTime, $endTime);
        $bestStart = $start;
        $bestEnd = $end;
        $bestDistance = PHP_INT_MAX;

        foreach ([-1440, 0, 1440] as $offset) {
            $candidateStart = $start + $offset;
            $distance = abs($candidateStart - $targetStart);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $bestStart = $candidateStart;
                $bestEnd = $end + $offset;
            }
        }

        return [$bestStart, $bestEnd];
    }

    private function normalizedEndMinutes(string $startTime, string $endTime): int
    {
        $start = $this->minutesFromTime($startTime);
        $end = $this->minutesFromTime($endTime);

        return $end <= $start ? $end + 1440 : $end;
    }

    private function minutesFromTime(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', mb_substr($time, 0, 5)));

        return $hour * 60 + $minute;
    }
}
