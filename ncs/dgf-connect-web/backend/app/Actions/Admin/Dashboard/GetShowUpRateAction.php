<?php

namespace App\Actions\Admin\Dashboard;

use App\Actions\ActionResult;
use App\Enums\ShiftUserStatus;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use App\Support\Dashboard\DashboardDateRange;
use Illuminate\Support\Facades\DB;

class GetShowUpRateAction
{
    private const DAY_OF_WEEK_LOW_SAMPLE_THRESHOLD = 5;

    private const SHIFT_TIME_GROUP_LOW_SAMPLE_THRESHOLD = 5;

    /**
     * Buckets matching GetDemandHeatmapAction::SLOT_GROUPS so the show-up rate
     * chart can be read against the demand heatmap for the same shift bands.
     */
    private const SLOT_GROUPS = [
        'Early Morning' => [5, 6],
        'Morning' => [7, 8, 9],
        'Midday' => [10, 11, 12],
        'Afternoon' => [13, 14, 15],
        'Evening' => [16, 17, 18],
        'Night' => [19, 20, 21, 22, 23, 0, 1, 2, 3, 4],
    ];

    public function __invoke(
        DashboardWorkplaceResolver $resolver,
        DashboardDateRange $range,
        ?array $requestedIds
    ): ActionResult {
        $reliabilityIds = $resolver->intersectWithRequested($resolver->reliability(), $requestedIds);

        if ($resolver->isEmpty($reliabilityIds)) {
            return new ActionResult(
                data: [
                    'by_workplace' => [],
                    'by_workplace_slot' => [],
                    'by_day_of_week' => $this->emptyDayOfWeekRows(),
                    'by_shift_time_group' => $this->emptyShiftTimeGroupRows(),
                ]
            );
        }

        $from = $range->getStart();
        $to = $range->getEnd();

        // One query grouped by (workplace_id, workplace_shift_id). LEFT JOIN
        // check_ins (unique on shift_user_id) is cheaper than a correlated
        // EXISTS subquery per row.
        $query = DB::table('shift_users')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereBetween('shifts.date', [$from, $to]);

        if ($reliabilityIds !== null) {
            $query->whereIn('shifts.workplace_id', $reliabilityIds);
        }

        $slotRows = $query
            ->selectRaw(
                '
            shifts.workplace_id,
            shifts.workplace_shift_id,
            COUNT(*) as assigned_count,
            SUM(CASE WHEN check_ins.id IS NOT NULL THEN 1 ELSE 0 END) as check_in_count
        '
            )
            ->groupBy('shifts.workplace_id', 'shifts.workplace_shift_id')
            ->get();

        // Fetch workplace names and slot labels
        $workplaceIds = $slotRows->pluck('workplace_id')->unique()->toArray();
        $workplaceNames = DB::table('workplaces')->whereIn('id', $workplaceIds)->pluck('name', 'id');

        $wsIds = $slotRows->pluck('workplace_shift_id')->unique()->toArray();
        $wsLabels = [];
        if (!empty($wsIds)) {
            $wsData = DB::table('workplace_shifts')
                ->whereIn('id', $wsIds)
                ->select('id', 'start_time', 'end_time')
                ->get();
            foreach ($wsData as $ws) {
                $wsLabels[$ws->id] = mb_substr($ws->start_time, 0, 5) . '–' . mb_substr($ws->end_time, 0, 5);
            }
        }

        // Roll up to by_workplace
        $byWorkplace = [];
        foreach ($slotRows as $row) {
            $wpId = $row->workplace_id;
            if (!isset($byWorkplace[$wpId])) {
                $byWorkplace[$wpId] = ['assigned_count' => 0, 'check_in_count' => 0];
            }
            $byWorkplace[$wpId]['assigned_count'] += (int) $row->assigned_count;
            $byWorkplace[$wpId]['check_in_count'] += (int) $row->check_in_count;
        }

        $byWorkplaceResult = [];
        foreach ($byWorkplace as $wpId => $data) {
            $rate =
                $data['assigned_count'] > 0
                    ? round(($data['check_in_count'] / $data['assigned_count']) * 100, 1)
                    : null;

            $byWorkplaceResult[] = [
                'workplace_id' => $wpId,
                'workplace_name' => $workplaceNames[$wpId] ?? '',
                'assigned_count' => $data['assigned_count'],
                'check_in_count' => $data['check_in_count'],
                'show_up_rate' => $rate,
                'low_sample' => $data['assigned_count'] < 5,
            ];
        }

        // Sort by show_up_rate ASC
        usort($byWorkplaceResult, fn($a, $b) => ($a['show_up_rate'] ?? 0) <=> ($b['show_up_rate'] ?? 0));

        // By workplace slot
        $bySlotResult = [];
        foreach ($slotRows as $row) {
            $assigned = (int) $row->assigned_count;
            $checkIns = (int) $row->check_in_count;
            $rate = $assigned > 0 ? round(($checkIns / $assigned) * 100, 1) : null;

            $bySlotResult[] = [
                'workplace_id' => $row->workplace_id,
                'workplace_name' => $workplaceNames[$row->workplace_id] ?? '',
                'workplace_shift_id' => $row->workplace_shift_id,
                'slot_label' => $wsLabels[$row->workplace_shift_id] ?? '',
                'assigned_count' => $assigned,
                'check_in_count' => $checkIns,
                'show_up_rate' => $rate,
                'low_sample' => $assigned < 5,
            ];
        }

        // Sort by show_up_rate ASC
        usort($bySlotResult, fn($a, $b) => ($a['show_up_rate'] ?? 0) <=> ($b['show_up_rate'] ?? 0));

        return new ActionResult(
            data: [
                'by_workplace' => $byWorkplaceResult,
                'by_workplace_slot' => $bySlotResult,
                'by_day_of_week' => $this->byDayOfWeek($reliabilityIds, $from, $to),
                'by_shift_time_group' => $this->byShiftTimeGroup($reliabilityIds, $from, $to),
            ]
        );
    }

    /**
     * Reliability bucketed by day-of-week index (0=Sun .. 6=Sat).
     *
     * Index choice matches the frontend's `dayLabelKeys = ['Sun','Mon',…,'Sat']`
     * (see CapacityTable.tsx) so the chart can index into it directly.
     *
     * MySQL DAYOFWEEK returns 1=Sun..7=Sat, hence `dow - 1`.
     *
     * @return array<int, array{
     *     day_of_week:int,
     *     assigned_count:int,
     *     check_in_count:int,
     *     show_up_rate:float|null,
     *     low_sample:bool
     * }>
     */
    private function byDayOfWeek(?array $reliabilityIds, string $from, string $to): array
    {
        $query = DB::table('shift_users')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereBetween('shifts.date', [$from, $to]);

        if ($reliabilityIds !== null) {
            $query->whereIn('shifts.workplace_id', $reliabilityIds);
        }

        $rows = $query
            ->selectRaw(
                '
                DAYOFWEEK(shifts.date) as mysql_dow,
                COUNT(*) as assigned_count,
                SUM(CASE WHEN check_ins.id IS NOT NULL THEN 1 ELSE 0 END) as check_in_count
            '
            )
            ->groupBy(DB::raw('DAYOFWEEK(shifts.date)'))
            ->get();

        $byDay = [];
        foreach ($rows as $row) {
            // MySQL DAYOFWEEK returns 1=Sun..7=Sat — convert to 0=Sun..6=Sat.
            $dayIndex = ((int) $row->mysql_dow) - 1;
            $byDay[$dayIndex] = [
                'assigned_count' => (int) $row->assigned_count,
                'check_in_count' => (int) $row->check_in_count,
            ];
        }

        $result = [];
        for ($d = 0; $d < 7; $d++) {
            $assigned = $byDay[$d]['assigned_count'] ?? 0;
            $checkIns = $byDay[$d]['check_in_count'] ?? 0;
            $rate = $assigned > 0 ? round(($checkIns / $assigned) * 100, 1) : null;
            $result[] = [
                'day_of_week' => $d,
                'assigned_count' => $assigned,
                'check_in_count' => $checkIns,
                'show_up_rate' => $rate,
                'low_sample' => $assigned < self::DAY_OF_WEEK_LOW_SAMPLE_THRESHOLD,
            ];
        }

        return $result;
    }

    /**
     * Reliability bucketed by the same shift-time groups as the demand heatmap
     * (Early Morning, Morning, Midday, Afternoon, Evening, Night).
     *
     * @return array<int, array{
     *     group:string,
     *     assigned_count:int,
     *     check_in_count:int,
     *     show_up_rate:float|null,
     *     low_sample:bool
     * }>
     */
    private function byShiftTimeGroup(?array $reliabilityIds, string $from, string $to): array
    {
        $query = DB::table('shift_users')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->join('workplace_shifts', 'shifts.workplace_shift_id', '=', 'workplace_shifts.id')
            ->leftJoin('check_ins', 'check_ins.shift_user_id', '=', 'shift_users.id')
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereBetween('shifts.date', [$from, $to]);

        if ($reliabilityIds !== null) {
            $query->whereIn('shifts.workplace_id', $reliabilityIds);
        }

        $rows = $query
            ->selectRaw(
                '
                HOUR(workplace_shifts.start_time) as start_hour,
                COUNT(*) as assigned_count,
                SUM(CASE WHEN check_ins.id IS NOT NULL THEN 1 ELSE 0 END) as check_in_count
            '
            )
            ->groupBy(DB::raw('HOUR(workplace_shifts.start_time)'))
            ->get();

        $hourToGroup = [];
        foreach (self::SLOT_GROUPS as $group => $hours) {
            foreach ($hours as $h) {
                $hourToGroup[$h] = $group;
            }
        }

        $byGroup = [];
        foreach (array_keys(self::SLOT_GROUPS) as $group) {
            $byGroup[$group] = ['assigned_count' => 0, 'check_in_count' => 0];
        }

        foreach ($rows as $row) {
            $group = $hourToGroup[(int) $row->start_hour] ?? 'Night';
            $byGroup[$group]['assigned_count'] += (int) $row->assigned_count;
            $byGroup[$group]['check_in_count'] += (int) $row->check_in_count;
        }

        $result = [];
        foreach ($byGroup as $group => $data) {
            $assigned = $data['assigned_count'];
            $checkIns = $data['check_in_count'];
            $rate = $assigned > 0 ? round(($checkIns / $assigned) * 100, 1) : null;

            $result[] = [
                'group' => $group,
                'assigned_count' => $assigned,
                'check_in_count' => $checkIns,
                'show_up_rate' => $rate,
                'low_sample' => $assigned < self::SHIFT_TIME_GROUP_LOW_SAMPLE_THRESHOLD,
            ];
        }

        return $result;
    }

    private function emptyShiftTimeGroupRows(): array
    {
        $rows = [];
        foreach (array_keys(self::SLOT_GROUPS) as $group) {
            $rows[] = [
                'group' => $group,
                'assigned_count' => 0,
                'check_in_count' => 0,
                'show_up_rate' => null,
                'low_sample' => true,
            ];
        }

        return $rows;
    }

    private function emptyDayOfWeekRows(): array
    {
        $rows = [];
        for ($d = 0; $d < 7; $d++) {
            $rows[] = [
                'day_of_week' => $d,
                'assigned_count' => 0,
                'check_in_count' => 0,
                'show_up_rate' => null,
                'low_sample' => true,
            ];
        }

        return $rows;
    }
}
