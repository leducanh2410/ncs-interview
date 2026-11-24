<?php

namespace App\Actions\Common\Deployment;

use App\Models\CheckIn;
use App\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only analysis that helps decide whether a given moment is a good time to
 * deploy. It looks at the shift boundaries (the start/end times when workers check
 * in and out) around the evaluation time, the amount of real check-in/check-out
 * activity in the recent past, and scans ahead to suggest the next quiet windows.
 */
class AnalyzeDeploymentWindowAction
{
    /**
     * @param array<int, int> $horizonSteps Ascending list of look-ahead tiers (hours)
     *                                       to try in turn until a usable window is found.
     * @return array{
     *     at: Carbon,
     *     timezone: string,
     *     lookback_minutes: int,
     *     horizon_steps: array<int, int>,
     *     margin_minutes: int,
     *     recent_activity: array{check_ins: int, check_outs: int, total: int},
     *     active_now: array{workers: int, shifts: int},
     *     nearest: array<string, array|null>,
     *     best_windows: array{horizon_used_hours: int|null, horizons_tried: array<int, int>, windows: array<int, array>},
     *     verdict: array{level: string, reasons: array<int, string>}
     * }
     */
    public function __invoke(
        Carbon $at,
        int $lookbackMinutes = 5,
        array $horizonSteps = [1, 2, 4, 12],
        int $marginMinutes = 15,
        int $busyActivityThreshold = 5
    ): array {
        $horizonSteps = $this->normalizeHorizonSteps($horizonSteps);
        $maxHorizon = end($horizonSteps);

        // Load the data once for the widest horizon; narrower tiers just filter it.
        $intervals = $this->loadShiftIntervals($at, $maxHorizon);
        $events = $this->buildEvents($intervals);

        $recent = $this->recentActivity($at, $lookbackMinutes);
        $nearest = $this->nearestBoundaries($at, $events);
        $activeNow = $this->activeAt($at, $intervals);
        $bestWindows = $this->escalateWindows($at, $horizonSteps, $marginMinutes, $events, $intervals);
        $verdict = $this->verdict($at, $recent, $nearest, $marginMinutes, $busyActivityThreshold);

        return [
            'at' => $at->copy(),
            'timezone' => $at->getTimezone()->getName(),
            'lookback_minutes' => $lookbackMinutes,
            'horizon_steps' => $horizonSteps,
            'margin_minutes' => $marginMinutes,
            'recent_activity' => $recent,
            'active_now' => $activeNow,
            'nearest' => $nearest,
            'best_windows' => $bestWindows,
            'verdict' => $verdict,
        ];
    }

    /**
     * Sanitise the requested horizon tiers: positive, unique and ascending. Falls
     * back to a sensible default when nothing usable is provided.
     *
     * @param array<int, int> $horizonSteps
     * @return array<int, int>
     */
    private function normalizeHorizonSteps(array $horizonSteps): array
    {
        $steps = collect($horizonSteps)
            ->map(fn($hours) => (int) $hours)
            ->filter(fn(int $hours) => $hours > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return empty($steps) ? [1, 2, 4, 12] : $steps;
    }

    /**
     * Try each horizon tier in order and return the first one that yields at least
     * one usable window, along with which tiers were attempted.
     *
     * @param array<int, int> $horizonSteps
     * @param Collection<int, array> $events
     * @param Collection<int, array> $intervals
     * @return array{horizon_used_hours: int|null, horizons_tried: array<int, int>, windows: array<int, array>}
     */
    private function escalateWindows(
        Carbon $at,
        array $horizonSteps,
        int $marginMinutes,
        Collection $events,
        Collection $intervals
    ): array {
        $tried = [];

        foreach ($horizonSteps as $hours) {
            $tried[] = $hours;
            $windows = $this->bestWindows($at, $hours, $marginMinutes, $events, $intervals);

            if (!empty($windows)) {
                return [
                    'horizon_used_hours' => $hours,
                    'horizons_tried' => $tried,
                    'windows' => $windows,
                ];
            }
        }

        return [
            'horizon_used_hours' => null,
            'horizons_tried' => $tried,
            'windows' => [],
        ];
    }

    /**
     * Load shifts whose time range may overlap the analysis window and resolve each
     * one into a concrete [start, end, weight] interval. The date range is padded by
     * a day on each side so overnight shifts are not missed.
     *
     * @return Collection<int, array>
     */
    private function loadShiftIntervals(Carbon $at, int $horizonHours): Collection
    {
        $from = $at->copy()->subDay()->toDateString();
        $to = $at->copy()->addHours($horizonHours)->addDay()->toDateString();

        $shifts = Shift::query()
            ->with('workplace:id,name', 'workplaceShift')
            ->withCount(['fixedUsers as approved_fixed_count', 'tempUsers as approved_temp_count'])
            ->whereBetween('date', [$from, $to])
            ->get();

        return $shifts
            ->filter(fn(Shift $shift) => $shift->workplaceShift !== null)
            ->map(function (Shift $shift) {
                [$start, $end] = $shift->getTimeRange();
                $weight = (int) $shift->approved_fixed_count + (int) $shift->approved_temp_count;

                return [
                    'shift_id' => $shift->id,
                    'workplace' => $shift->workplace?->name ?? '#' . $shift->workplace_id,
                    'label' => $shift->workplaceShift->start_time . '-' . $shift->workplaceShift->end_time,
                    'start' => $start,
                    'end' => $end,
                    'weight' => $weight,
                ];
            })
            ->values();
    }

    /**
     * Flatten intervals into a sorted list of boundary events (one per check-in
     * moment and one per check-out moment).
     *
     * @param Collection<int, array> $intervals
     * @return Collection<int, array>
     */
    private function buildEvents(Collection $intervals): Collection
    {
        $events = collect();

        foreach ($intervals as $interval) {
            $events->push([
                'type' => 'start',
                'time' => $interval['start'],
                'weight' => $interval['weight'],
                'workplace' => $interval['workplace'],
                'label' => $interval['label'],
            ]);
            $events->push([
                'type' => 'end',
                'time' => $interval['end'],
                'weight' => $interval['weight'],
                'workplace' => $interval['workplace'],
                'label' => $interval['label'],
            ]);
        }

        return $events->sortBy(fn(array $event) => $event['time']->timestamp)->values();
    }

    /**
     * @return array{check_ins: int, check_outs: int, total: int}
     */
    private function recentActivity(Carbon $at, int $lookbackMinutes): array
    {
        $from = $at->copy()->subMinutes($lookbackMinutes);

        $checkIns = CheckIn::query()
            ->whereBetween('check_in_time', [$from, $at])
            ->count();

        $checkOuts = CheckIn::query()
            ->whereNotNull('check_out_time')
            ->whereBetween('check_out_time', [$from, $at])
            ->count();

        return [
            'check_ins' => $checkIns,
            'check_outs' => $checkOuts,
            'total' => $checkIns + $checkOuts,
        ];
    }

    /**
     * Find the closest shift start/end on each side of the evaluation time.
     *
     * @param Collection<int, array> $events
     * @return array<string, array|null>
     */
    private function nearestBoundaries(Carbon $at, Collection $events): array
    {
        $starts = $events->where('type', 'start');
        $ends = $events->where('type', 'end');

        return [
            'last_start' => $this->describeBoundary(
                $starts->filter(fn($e) => $e['time']->lte($at))->sortByDesc(fn($e) => $e['time']->timestamp)->first(),
                $at
            ),
            'next_start' => $this->describeBoundary(
                $starts->filter(fn($e) => $e['time']->gt($at))->sortBy(fn($e) => $e['time']->timestamp)->first(),
                $at
            ),
            'last_end' => $this->describeBoundary(
                $ends->filter(fn($e) => $e['time']->lte($at))->sortByDesc(fn($e) => $e['time']->timestamp)->first(),
                $at
            ),
            'next_end' => $this->describeBoundary(
                $ends->filter(fn($e) => $e['time']->gt($at))->sortBy(fn($e) => $e['time']->timestamp)->first(),
                $at
            ),
        ];
    }

    private function describeBoundary(?array $event, Carbon $at): ?array
    {
        if ($event === null) {
            return null;
        }

        return [
            'time' => $event['time']->copy(),
            'minutes_away' => abs($this->minutesBetween($at, $event['time'])),
            'weight' => $event['weight'],
            'workplace' => $event['workplace'],
            'label' => $event['label'],
        ];
    }

    /**
     * @param Collection<int, array> $intervals
     * @return array{workers: int, shifts: int}
     */
    private function activeAt(Carbon $at, Collection $intervals): array
    {
        $active = $intervals->filter(fn(array $interval) => $interval['start']->lte($at) && $interval['end']->gt($at));

        return [
            'workers' => (int) $active->sum('weight'),
            'shifts' => $active->count(),
        ];
    }

    /**
     * Scan the horizon for quiet gaps between shift boundaries and rank them. A gap
     * is safer when fewer workers are active during it and when it is long enough to
     * give clearance on both sides of the deploy moment.
     *
     * @param Collection<int, array> $events
     * @param Collection<int, array> $intervals
     * @return array<int, array>
     */
    private function bestWindows(
        Carbon $at,
        int $horizonHours,
        int $marginMinutes,
        Collection $events,
        Collection $intervals
    ): array {
        $horizonEnd = $at->copy()->addHours($horizonHours);
        $minGapMinutes = max(10, $marginMinutes);

        $points = $events
            ->pluck('time')
            ->filter(fn(Carbon $time) => $time->gt($at) && $time->lt($horizonEnd))
            ->unique(fn(Carbon $time) => $time->timestamp)
            ->sortBy(fn(Carbon $time) => $time->timestamp)
            ->values();

        $boundaries = collect([$at->copy()])
            ->merge($points)
            ->push($horizonEnd->copy())
            ->values();

        $gaps = collect();

        for ($i = 0; $i < $boundaries->count() - 1; $i++) {
            $gapStart = $boundaries[$i];
            $gapEnd = $boundaries[$i + 1];
            $lengthMinutes = $this->minutesBetween($gapStart, $gapEnd);

            if ($lengthMinutes < $minGapMinutes) {
                continue;
            }

            $recommendedAt = $gapStart->copy()->addMinutes(intdiv($lengthMinutes, 2));
            $active = $this->activeAt($recommendedAt, $intervals);

            $gaps->push([
                'from' => $gapStart->copy(),
                'to' => $gapEnd->copy(),
                'recommended_at' => $recommendedAt,
                'length_minutes' => $lengthMinutes,
                'clearance_minutes' => intdiv($lengthMinutes, 2),
                'active_workers' => $active['workers'],
                'active_shifts' => $active['shifts'],
            ]);
        }

        return $gaps
            ->sortBy([['active_workers', 'asc'], ['length_minutes', 'desc']])
            ->take(3)
            ->values()
            ->all();
    }

    /**
     * @param array{check_ins: int, check_outs: int, total: int} $recent
     * @param array<string, array|null> $nearest
     * @return array{level: string, reasons: array<int, string>}
     */
    private function verdict(
        Carbon $at,
        array $recent,
        array $nearest,
        int $marginMinutes,
        int $busyActivityThreshold
    ): array {
        $reasons = [];

        $upcoming = collect([$nearest['next_start'], $nearest['next_end']])
            ->filter()
            ->min('minutes_away');
        $recentBoundary = collect([$nearest['last_start'], $nearest['last_end']])
            ->filter()
            ->min('minutes_away');

        $level = 'good';

        if ($recent['total'] > $busyActivityThreshold) {
            $level = 'avoid';
            $reasons[] = "High recent activity: {$recent['total']} check-in/out events in the last few minutes.";
        } elseif ($recent['total'] > 0) {
            $level = 'caution';
            $reasons[] = "Some recent activity: {$recent['total']} check-in/out event(s) just happened.";
        }

        if ($upcoming !== null && $upcoming <= $marginMinutes) {
            $level = 'avoid';
            $reasons[] = "A shift boundary is only {$upcoming} min away (workers about to check in/out).";
        } elseif ($upcoming !== null && $upcoming <= $marginMinutes * 2) {
            $level = $level === 'avoid' ? 'avoid' : 'caution';
            $reasons[] = "A shift boundary is {$upcoming} min away.";
        }

        if ($recentBoundary !== null && $recentBoundary <= $marginMinutes) {
            $level = $level === 'good' ? 'caution' : $level;
            $reasons[] = "A shift boundary was {$recentBoundary} min ago (check-in/out may still be in progress).";
        }

        if ($level === 'good') {
            $reasons[] = 'No nearby shift boundaries and no recent check-in/out activity.';
        }

        return [
            'level' => $level,
            'reasons' => $reasons,
        ];
    }

    private function minutesBetween(Carbon $from, Carbon $to): int
    {
        return intdiv($to->timestamp - $from->timestamp, 60);
    }
}
