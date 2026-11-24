<?php

namespace App\Actions\Admin\Dashboard;

use App\Actions\ActionResult;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use App\Support\Dashboard\DashboardDateRange;
use Illuminate\Support\Facades\DB;

class GetDemandHeatmapAction
{
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
        string $mode,
        int $limit,
        ?array $requestedIds
    ): ActionResult {
        $reliabilityIds = $resolver->intersectWithRequested($resolver->reliability(), $requestedIds);

        if ($resolver->isEmpty($reliabilityIds)) {
            return new ActionResult(data: ['mode' => $mode, 'slots' => [], 'cells' => []]);
        }

        $from = $range->getStart();
        $to = $range->getEnd();

        if ($mode === 'top_slots') {
            return $this->topSlots($reliabilityIds, $from, $to, $limit);
        }

        return $this->groupedSlots($reliabilityIds, $from, $to);
    }

    private function groupedSlots(?array $workplaceIds, string $from, string $to): ActionResult
    {
        // Get per-shift check-in counts with day_of_week and workplace_shift start_time hour
        $query = DB::table('shifts')
            ->join('workplace_shifts', 'shifts.workplace_shift_id', '=', 'workplace_shifts.id')
            ->leftJoin('check_ins', 'check_ins.shift_id', '=', 'shifts.id')
            ->whereBetween('shifts.date', [$from, $to]);

        if ($workplaceIds !== null) {
            $query->whereIn('shifts.workplace_id', $workplaceIds);
        }

        $rows = $query
            ->selectRaw(
                '
            DAYOFWEEK(shifts.date) as dow,
            HOUR(workplace_shifts.start_time) as start_hour,
            shifts.id as shift_id,
            COUNT(check_ins.id) as ci_count
        '
            )
            ->groupBy('shifts.id', DB::raw('DAYOFWEEK(shifts.date)'), DB::raw('HOUR(workplace_shifts.start_time)'))
            ->get();

        // Map start_hour to group
        $hourToGroup = [];
        foreach (self::SLOT_GROUPS as $group => $hours) {
            foreach ($hours as $h) {
                $hourToGroup[$h] = $group;
            }
        }

        // Aggregate: for each (group, dow), compute avg check-ins per shift occurrence
        // dow: MySQL DAYOFWEEK returns 1=Sun...7=Sat, we convert to 0=Mon...6=Sun
        $data = []; // [group][dowIndex] => [total_ci, shift_count]

        foreach ($rows as $row) {
            $group = $hourToGroup[(int) $row->start_hour] ?? 'Night';
            $dowIndex = $this->mysqlDowToIndex((int) $row->dow);

            if (!isset($data[$group][$dowIndex])) {
                $data[$group][$dowIndex] = ['total' => 0, 'count' => 0];
            }
            $data[$group][$dowIndex]['total'] += (int) $row->ci_count;
            $data[$group][$dowIndex]['count']++;
        }

        // Build output
        $slots = array_keys(self::SLOT_GROUPS);
        $cells = [];
        foreach ($slots as $group) {
            $row = [];
            for ($d = 0; $d < 7; $d++) {
                if (isset($data[$group][$d]) && $data[$group][$d]['count'] > 0) {
                    $row[] = (int) round($data[$group][$d]['total'] / $data[$group][$d]['count']);
                } else {
                    $row[] = null;
                }
            }
            $cells[$group] = $row;
        }

        // Remove slots with all nulls
        $slots = array_filter($slots, fn($s) => !empty(array_filter($cells[$s], fn($v) => $v !== null)));
        $cells = array_filter($cells, fn($row) => !empty(array_filter($row, fn($v) => $v !== null)));

        return new ActionResult(
            data: [
                'mode' => 'groups',
                'slots' => array_values($slots),
                'cells' => $cells,
            ]
        );
    }

    private function topSlots(?array $workplaceIds, string $from, string $to, int $limit): ActionResult
    {
        // Get per-shift check-in counts with exact slot label
        $query = DB::table('shifts')
            ->join('workplace_shifts', 'shifts.workplace_shift_id', '=', 'workplace_shifts.id')
            ->leftJoin('check_ins', 'check_ins.shift_id', '=', 'shifts.id')
            ->whereBetween('shifts.date', [$from, $to]);

        if ($workplaceIds !== null) {
            $query->whereIn('shifts.workplace_id', $workplaceIds);
        }

        $rows = $query
            ->selectRaw(
                "
            CONCAT(TIME_FORMAT(workplace_shifts.start_time, '%H:%i'), '–', TIME_FORMAT(workplace_shifts.end_time, '%H:%i')) as slot_label,
            DAYOFWEEK(shifts.date) as dow,
            shifts.id as shift_id,
            COUNT(check_ins.id) as ci_count
        "
            )
            ->groupBy('shifts.id', 'slot_label', DB::raw('DAYOFWEEK(shifts.date)'))
            ->get();

        // Determine top slots by total occurrence count
        $slotOccurrences = [];
        $slotData = []; // [label][dowIndex] => [total, count]

        foreach ($rows as $row) {
            $label = $row->slot_label;
            $dowIndex = $this->mysqlDowToIndex((int) $row->dow);

            if (!isset($slotOccurrences[$label])) {
                $slotOccurrences[$label] = 0;
            }
            $slotOccurrences[$label]++;

            if (!isset($slotData[$label][$dowIndex])) {
                $slotData[$label][$dowIndex] = ['total' => 0, 'count' => 0];
            }
            $slotData[$label][$dowIndex]['total'] += (int) $row->ci_count;
            $slotData[$label][$dowIndex]['count']++;
        }

        // Sort by occurrence desc, take top $limit
        arsort($slotOccurrences);
        $topLabels = array_slice(array_keys($slotOccurrences), 0, $limit);

        $cells = [];
        foreach ($topLabels as $label) {
            $row = [];
            for ($d = 0; $d < 7; $d++) {
                if (isset($slotData[$label][$d]) && $slotData[$label][$d]['count'] > 0) {
                    $row[] = (int) round($slotData[$label][$d]['total'] / $slotData[$label][$d]['count']);
                } else {
                    $row[] = null;
                }
            }
            $cells[$label] = $row;
        }

        return new ActionResult(
            data: [
                'mode' => 'top_slots',
                'slots' => $topLabels,
                'cells' => $cells,
            ]
        );
    }

    /**
     * MySQL DAYOFWEEK: 1=Sunday, 2=Monday, ..., 7=Saturday
     * Output index: 0=Monday, 1=Tuesday, ..., 6=Sunday
     */
    private function mysqlDowToIndex(int $dow): int
    {
        return $dow === 1 ? 6 : $dow - 2;
    }
}
