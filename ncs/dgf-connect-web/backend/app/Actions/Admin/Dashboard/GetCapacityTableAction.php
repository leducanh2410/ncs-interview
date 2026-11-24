<?php

namespace App\Actions\Admin\Dashboard;

use App\Actions\ActionResult;
use App\Enums\ShiftUserStatus;
use App\Services\Dashboard\DashboardWorkplaceResolver;
use App\Support\Dashboard\DashboardThresholds;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GetCapacityTableAction
{
    public function __invoke(DashboardWorkplaceResolver $resolver, ?array $requestedIds): ActionResult
    {
        $demandIds = $resolver->intersectWithRequested($resolver->demand(), $requestedIds);
        $reliabilityIds = $resolver->intersectWithRequested($resolver->reliability(), $requestedIds);
        $reliabilityAvailable = !$resolver->isEmpty($reliabilityIds);

        if ($resolver->isEmpty($demandIds)) {
            return new ActionResult(
                data: ['rows' => [], 'summary' => ['critical_count' => 0, 'tight_count' => 0, 'worst' => null]]
            );
        }

        $today = Carbon::now('Asia/Tokyo')->startOfDay();
        $dates = [];
        for ($i = 0; $i < 14; $i++) {
            $dates[] = $today->copy()->addDays($i)->toDateString();
        }

        $singleWorkplace = is_array($demandIds) && count($demandIds) === 1;

        // Assigned counts per workplace/date
        $assignedQuery = DB::table('shift_users')
            ->join('shifts', 'shift_users.shift_id', '=', 'shifts.id')
            ->where('shift_users.status', ShiftUserStatus::Approved->value)
            ->whereIn('shifts.date', $dates);

        if ($demandIds !== null) {
            $assignedQuery->whereIn('shifts.workplace_id', $demandIds);
        }

        $assignedRows = $assignedQuery
            ->selectRaw('shifts.workplace_id, shifts.date, COUNT(DISTINCT shift_users.user_id) as assigned')
            ->groupBy('shifts.workplace_id', 'shifts.date')
            ->get()
            ->keyBy(fn($row) => $row->workplace_id . '_' . $row->date);

        // Active pool per workplace (90-day fixed window)
        $activePoolByWorkplace = [];
        if ($reliabilityAvailable) {
            $poolStart = $today->copy()->subDays(90)->toDateString();
            $poolEnd = $today->copy()->subDay()->toDateString();

            $poolQuery = DB::table('check_ins')
                ->join('shifts', 'check_ins.shift_id', '=', 'shifts.id')
                ->whereBetween('shifts.date', [$poolStart, $poolEnd]);

            if ($reliabilityIds !== null) {
                $poolQuery->whereIn('shifts.workplace_id', $reliabilityIds);
            }

            $poolRows = $poolQuery
                ->selectRaw('shifts.workplace_id, COUNT(DISTINCT check_ins.user_id) as pool')
                ->groupBy('shifts.workplace_id')
                ->get();

            foreach ($poolRows as $row) {
                $activePoolByWorkplace[$row->workplace_id] = (int) $row->pool;
            }
        }

        // Workplace names
        $workplaceNames = DB::table('workplaces')
            ->when($demandIds !== null, fn($q) => $q->whereIn('id', $demandIds))
            ->pluck('name', 'id');

        // Build rows
        $rows = [];

        if ($singleWorkplace) {
            // Single workplace: return full 14-day series
            $wpId = $demandIds[0];
            foreach ($dates as $date) {
                $key = $wpId . '_' . $date;
                $assigned = isset($assignedRows[$key]) ? (int) $assignedRows[$key]->assigned : 0;
                $rows[] = $this->buildRow(
                    $date,
                    $wpId,
                    $workplaceNames[$wpId] ?? '',
                    $assigned,
                    $activePoolByWorkplace,
                    $reliabilityAvailable
                );
            }
        } else {
            // Multi-workplace: only rows with assigned > 0
            foreach ($assignedRows as $row) {
                $rows[] = $this->buildRow(
                    $row->date,
                    $row->workplace_id,
                    $workplaceNames[$row->workplace_id] ?? '',
                    (int) $row->assigned,
                    $activePoolByWorkplace,
                    $reliabilityAvailable
                );
            }
        }

        // Sort: severity desc, utilization desc, assigned desc, date asc
        usort($rows, function ($a, $b) {
            $tagOrder = ['critical' => 3, 'tight' => 2, 'healthy' => 1, null => 0];
            $aSev = $tagOrder[$a['capacity_tag']] ?? 0;
            $bSev = $tagOrder[$b['capacity_tag']] ?? 0;

            if ($aSev !== $bSev) {
                return $bSev - $aSev;
            }

            $aUtil = $a['utilization'] ?? -1;
            $bUtil = $b['utilization'] ?? -1;
            if ($aUtil !== $bUtil) {
                return $bUtil <=> $aUtil;
            }

            if ($a['assigned'] !== $b['assigned']) {
                return $b['assigned'] - $a['assigned'];
            }

            return strcmp($a['date'], $b['date']);
        });

        // Summary
        $criticalCount = 0;
        $tightCount = 0;
        $worst = null;

        foreach ($rows as $row) {
            if ($row['capacity_tag'] === 'critical') {
                $criticalCount++;
                if ($worst === null) {
                    $worst = $row;
                }
            } elseif ($row['capacity_tag'] === 'tight') {
                $tightCount++;
                if ($worst === null) {
                    $worst = $row;
                }
            }
        }

        return new ActionResult(
            data: [
                'rows' => $rows,
                'summary' => [
                    'critical_count' => $reliabilityAvailable ? $criticalCount : null,
                    'tight_count' => $reliabilityAvailable ? $tightCount : null,
                    'worst' => $worst,
                ],
            ]
        );
    }

    private function buildRow(
        string $date,
        int $workplaceId,
        string $workplaceName,
        int $assigned,
        array $activePoolByWorkplace,
        bool $reliabilityAvailable
    ): array {
        $activePool = $reliabilityAvailable ? $activePoolByWorkplace[$workplaceId] ?? 0 : null;
        $freeBench = $activePool !== null ? max(0, $activePool - $assigned) : null;
        $utilization = $activePool !== null && $activePool > 0 ? round($assigned / $activePool, 4) : null;

        $tagResult = DashboardThresholds::tagFor($activePool, $assigned);

        return [
            'date' => $date,
            'day_of_week' => (int) Carbon::parse($date)->dayOfWeek,
            'workplace_id' => $workplaceId,
            'workplace_name' => $workplaceName,
            'active_pool' => $activePool,
            'assigned' => $assigned,
            'free_bench' => $freeBench,
            'utilization' => $utilization,
            'capacity_tag' => $tagResult['tag'],
            'capacity_reason' => $tagResult['reason'] ?? null,
        ];
    }
}
