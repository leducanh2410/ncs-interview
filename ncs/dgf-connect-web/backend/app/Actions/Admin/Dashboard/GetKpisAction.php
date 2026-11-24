<?php

namespace App\Actions\Admin\Dashboard;

use App\Actions\ActionResult;
use App\Enums\ShiftUserStatus;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use App\Support\Dashboard\DashboardDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class GetKpisAction
{
    /**
     * Maximum number of distinct workplaces returned in the daily check-ins
     * breakdown. Workplaces beyond this cap are aggregated into a synthetic
     * "Others" bucket so the chart stays readable when many workplaces are
     * selected. The cap matches the frontend's eight-colour palette.
     */
    private const DAILY_CHECK_INS_TOP_N = 8;

    public function __invoke(
        DashboardWorkplaceResolver $resolver,
        DashboardDateRange $range,
        ?array $requestedIds
    ): ActionResult {
        $reliabilityIds = $resolver->intersectWithRequested($resolver->reliability(), $requestedIds);
        $demandIds = $resolver->intersectWithRequested($resolver->demand(), $requestedIds);
        $reliabilityAvailable = !$resolver->isEmpty($reliabilityIds);

        $emptyMetric = ['value' => null, 'delta' => null, 'series' => []];

        $activePool = $emptyMetric;
        $showUpRate = $emptyMetric;
        $noShowRisk = $emptyMetric;
        $avgDailyCheckIns = $emptyMetric;

        if ($reliabilityAvailable) {
            $prior = $range->getPriorRange();

            // Compute each daily series once per range, then reuse across
            // metrics so the controller issues four queries instead of ten.
            $currentDistinct = $this->dailyDistinctCheckInUsers($reliabilityIds, $range);
            $priorDistinct = $this->dailyDistinctCheckInUsers($reliabilityIds, $prior);
            $currentReliability = $this->dailyReliability($reliabilityIds, $range);
            $priorReliability = $this->dailyReliability($reliabilityIds, $prior);

            $activePool = $this->buildActivePool($reliabilityIds, $range, $prior, $currentDistinct);
            $avgDailyCheckIns = $this->buildAvgDailyCheckIns($range, $prior, $currentDistinct, $priorDistinct);
            $showUpRate = $this->buildShowUpRate($range, $currentReliability, $priorReliability);
            $noShowRisk = $this->buildNoShowRisk($range, $currentReliability, $priorReliability);
        }

        $dailyCheckIns = $this->computeDailyCheckInsByWorkplace(
            $reliabilityAvailable ? $reliabilityIds : $demandIds,
            $range,
            $reliabilityAvailable
        );

        return new ActionResult(
            data: [
                'active_pool' => $activePool,
                'show_up_rate' => $showUpRate,
                'avg_daily_check_ins' => $avgDailyCheckIns,
                'no_show_risk' => $noShowRisk,
                'daily_check_ins' => $dailyCheckIns,
                'reliability_available' => $reliabilityAvailable,
            ]
        );
    }

    /**
     * Active pool = number of distinct users who checked in over the range.
     * Series = daily distinct check-in users (not cumulative — useful as a
     * trend even though it does not sum to the headline value).
     *
     * @param  array<string, int>  $currentDistinct  date => distinct user count
     */
    private function buildActivePool(
        ?array $workplaceIds,
        DashboardDateRange $range,
        DashboardDateRange $prior,
        array $currentDistinct
    ): array {
        $current = $this->activePoolCount($workplaceIds, $range->getStart(), $range->getEnd());
        $previous = $this->activePoolCount($workplaceIds, $prior->getStart(), $prior->getEnd());

        $delta = $previous > 0 ? $current - $previous : null;

        $series = array_map(static fn(string $date) => $currentDistinct[$date] ?? 0, $range->eachDate());

        return ['value' => $current, 'delta' => $delta, 'series' => $series];
    }

    private function activePoolCount(?array $workplaceIds, string $from, string $to): int
    {
        $query = DB::table('check_ins')
            ->join('shifts', 'check_ins.shift_id', '=', 'shifts.id')
            ->whereBetween('shifts.date', [$from, $to]);

        $this->applyWorkplaceFilter($query, $workplaceIds, 'shifts.workplace_id');

        return (int) $query->distinct('check_ins.user_id')->count('check_ins.user_id');
    }

    /**
     * @return array<string, int> date => distinct user count
     */
    private function dailyDistinctCheckInUsers(?array $workplaceIds, DashboardDateRange $range): array
    {
        $query = DB::table('check_ins')
            ->join('shifts', 'check_ins.shift_id', '=', 'shifts.id')
            ->whereBetween('shifts.date', [$range->getStart(), $range->getEnd()]);

        $this->applyWorkplaceFilter($query, $workplaceIds, 'shifts.workplace_id');

        $rows = $query
            ->selectRaw('shifts.date, COUNT(DISTINCT check_ins.user_id) as users')
            ->groupBy('shifts.date')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->date] = (int) $row->users;
        }

        return $out;
    }

    /**
     * Per-day reliability counts for approved assignments in the given range.
     * One query yields the denominator (approved assignments), shows
     * (assignments with a check-in), and no_shows (no check-in plus an
     * is_absent shift report). Used to derive both the show-up and no-show
     * KPIs without issuing additional aggregate queries.
     *
     * `check_ins.shift_user_id` is unique, so the LEFT JOIN is 1:1 and we can
     * count it directly instead of using a correlated EXISTS.
     *
     * @return array<string, array{denominator:int, shows:int, no_shows:int}>
     */
    private function dailyReliability(?array $workplaceIds, DashboardDateRange $range): array
    {
        $query = DB::table('shift_users')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereBetween('shifts.date', [$range->getStart(), $range->getEnd()]);

        $this->applyWorkplaceFilter($query, $workplaceIds, 'shifts.workplace_id');

        $rows = $query
            ->selectRaw(
                '
                shifts.date,
                COUNT(*) as denominator,
                SUM(CASE WHEN check_ins.id IS NOT NULL THEN 1 ELSE 0 END) as shows,
                SUM(CASE WHEN check_ins.id IS NULL AND EXISTS (
                    SELECT 1 FROM shift_reports
                    WHERE shift_reports.shift_user_id = shift_users.id
                    AND shift_reports.is_absent = 1
                ) THEN 1 ELSE 0 END) as no_shows
            '
            )
            ->groupBy('shifts.date')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->date] = [
                'denominator' => (int) $row->denominator,
                'shows' => (int) $row->shows,
                'no_shows' => (int) $row->no_shows,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array{denominator:int, shows:int, no_shows:int}>  $current
     * @param  array<string, array{denominator:int, shows:int, no_shows:int}>  $previous
     */
    private function buildShowUpRate(DashboardDateRange $range, array $current, array $previous): array
    {
        [$currentDenom, $currentShows] = $this->sumReliability($current, 'shows');
        [$previousDenom, $previousShows] = $this->sumReliability($previous, 'shows');

        $currentRate = $currentDenom > 0 ? round(($currentShows / $currentDenom) * 100, 1) : null;
        $previousRate = $previousDenom > 0 ? round(($previousShows / $previousDenom) * 100, 1) : null;

        $delta = $currentRate !== null && $previousRate !== null ? round($currentRate - $previousRate, 1) : null;

        $series = array_map(static function (string $date) use ($current) {
            $row = $current[$date] ?? null;
            if ($row === null || $row['denominator'] === 0) {
                return 0.0;
            }

            return round(($row['shows'] / $row['denominator']) * 100, 1);
        }, $range->eachDate());

        return ['value' => $currentRate, 'delta' => $delta, 'series' => $series];
    }

    /**
     * @param  array<string, array{denominator:int, shows:int, no_shows:int}>  $current
     * @param  array<string, array{denominator:int, shows:int, no_shows:int}>  $previous
     */
    private function buildNoShowRisk(DashboardDateRange $range, array $current, array $previous): array
    {
        [$currentDenom, $currentNoShows] = $this->sumReliability($current, 'no_shows');
        [$previousDenom, $previousNoShows] = $this->sumReliability($previous, 'no_shows');

        $currentRate = $currentDenom > 0 ? round(($currentNoShows / $currentDenom) * 100, 1) : null;
        $previousRate = $previousDenom > 0 ? round(($previousNoShows / $previousDenom) * 100, 1) : null;

        $delta = $currentRate !== null && $previousRate !== null ? round($currentRate - $previousRate, 1) : null;

        $series = array_map(static function (string $date) use ($current) {
            $row = $current[$date] ?? null;
            if ($row === null || $row['denominator'] === 0) {
                return 0.0;
            }

            return round(($row['no_shows'] / $row['denominator']) * 100, 1);
        }, $range->eachDate());

        return ['value' => $currentRate, 'delta' => $delta, 'series' => $series];
    }

    /**
     * @param  array<string, array{denominator:int, shows:int, no_shows:int}>  $rows
     * @return array{0:int, 1:int} [denominator, numerator]
     */
    private function sumReliability(array $rows, string $numeratorKey): array
    {
        $denom = 0;
        $numerator = 0;
        foreach ($rows as $row) {
            $denom += $row['denominator'];
            $numerator += $row[$numeratorKey];
        }

        return [$denom, $numerator];
    }

    /**
     * Average distinct daily check-in users across the range.
     * Headline value is integer (rounded). Series is per-day count.
     *
     * @param  array<string, int>  $currentDistinct
     * @param  array<string, int>  $priorDistinct
     */
    private function buildAvgDailyCheckIns(
        DashboardDateRange $range,
        DashboardDateRange $prior,
        array $currentDistinct,
        array $priorDistinct
    ): array {
        $dates = $range->eachDate();
        $series = array_map(static fn(string $date) => $currentDistinct[$date] ?? 0, $dates);

        $sum = array_sum($series);
        $value = count($dates) > 0 ? (int) round($sum / count($dates)) : 0;

        $priorDates = $prior->eachDate();
        $priorSeries = array_map(static fn(string $date) => $priorDistinct[$date] ?? 0, $priorDates);
        $priorSum = array_sum($priorSeries);
        $priorAvg = count($priorDates) > 0 ? (int) round($priorSum / count($priorDates)) : 0;

        $delta = $priorAvg > 0 ? $value - $priorAvg : null;

        return ['value' => $value, 'delta' => $delta, 'series' => $series];
    }

    /**
     * Per-day check-ins (distinct users) broken down by workplace, plus a
     * total per row, ready for a multi-line chart.
     *
     * Caps the breakdown at the top {@see DAILY_CHECK_INS_TOP_N} workplaces by
     * total contribution over the range. Any remaining workplaces are
     * aggregated into a synthetic "Others" bucket so the response stays small
     * and the chart stays readable when many workplaces are selected.
     */
    private function computeDailyCheckInsByWorkplace(
        ?array $workplaceIds,
        DashboardDateRange $range,
        bool $reliabilityAvailable
    ): array {
        if ($workplaceIds !== null && empty($workplaceIds)) {
            return ['workplaces' => [], 'rows' => []];
        }

        $query = DB::table('check_ins')
            ->join('shifts', 'check_ins.shift_id', '=', 'shifts.id')
            ->whereBetween('shifts.date', [$range->getStart(), $range->getEnd()]);

        $this->applyWorkplaceFilter($query, $workplaceIds, 'shifts.workplace_id');

        $rows = $query
            ->selectRaw(
                '
                shifts.date,
                shifts.workplace_id,
                COUNT(DISTINCT check_ins.user_id) as users
            '
            )
            ->groupBy('shifts.date', 'shifts.workplace_id')
            ->get();

        $workplaceTotals = [];
        $byDateAndWorkplace = [];
        foreach ($rows as $row) {
            $date = (string) $row->date;
            $wpId = (int) $row->workplace_id;
            $count = (int) $row->users;
            $byDateAndWorkplace[$date][$wpId] = $count;
            $workplaceTotals[$wpId] = ($workplaceTotals[$wpId] ?? 0) + $count;
        }

        $allWorkplaceIds = array_keys($workplaceTotals);
        $workplaceNames = !empty($allWorkplaceIds)
            ? DB::table('workplaces')->whereIn('id', $allWorkplaceIds)->pluck('name', 'id')->all()
            : [];

        // Pick the top N workplaces by total volume; aggregate the rest.
        arsort($workplaceTotals);
        $topIds = array_slice(array_keys($workplaceTotals), 0, self::DAILY_CHECK_INS_TOP_N);
        $otherIds = array_slice(array_keys($workplaceTotals), self::DAILY_CHECK_INS_TOP_N);
        $hasOthers = !empty($otherIds);
        $otherIdSet = array_flip($otherIds);

        // Build the visible workplace list (top N sorted by name, plus a
        // synthetic Others entry at the end if any rolled-up data exists).
        $workplaces = [];
        foreach ($topIds as $wpId) {
            $workplaces[] = [
                'id' => $wpId,
                'name' => $workplaceNames[$wpId] ?? '',
                'is_other' => false,
            ];
        }
        usort($workplaces, fn($a, $b) => strcmp((string) $a['name'], (string) $b['name']));

        if ($hasOthers) {
            $workplaces[] = [
                'id' => 0,
                'name' => 'Others',
                'is_other' => true,
            ];
        }

        $output = [];
        foreach ($range->eachDate() as $date) {
            $perWorkplace = $byDateAndWorkplace[$date] ?? [];
            $byWorkplaceOut = [];
            $total = 0;
            $othersTotal = 0;

            foreach ($workplaces as $wp) {
                if ($wp['is_other']) {
                    continue; // Filled below from the rolled-up bucket.
                }
                $count = (int) ($perWorkplace[$wp['id']] ?? 0);
                $byWorkplaceOut[(string) $wp['id']] = $count;
                $total += $count;
            }

            if ($hasOthers) {
                foreach ($perWorkplace as $wpId => $count) {
                    if (isset($otherIdSet[$wpId])) {
                        $othersTotal += (int) $count;
                    }
                }
                $byWorkplaceOut['0'] = $othersTotal;
                $total += $othersTotal;
            }

            $output[] = [
                'date' => $date,
                'total' => $total,
                'by_workplace' => $byWorkplaceOut,
            ];
        }

        // The per-workplace breakdown is only meaningful when the caller has
        // visibility on actual check-ins (reliability scope). When that is
        // not available we still expose the daily totals so the chart can
        // render an aggregate line.
        if (!$reliabilityAvailable) {
            $workplaces = [];
            $output = array_map(
                static fn(array $row) => [
                    'date' => $row['date'],
                    'total' => $row['total'],
                    'by_workplace' => [],
                ],
                $output
            );
        }

        return [
            'workplaces' => $workplaces,
            'rows' => $output,
        ];
    }

    private function applyWorkplaceFilter(Builder $query, ?array $workplaceIds, string $column): void
    {
        if ($workplaceIds !== null) {
            $query->whereIn($column, $workplaceIds);
        }
    }
}
