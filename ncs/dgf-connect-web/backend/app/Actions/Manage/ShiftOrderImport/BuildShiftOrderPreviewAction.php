<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Enums\ShiftUserStatus;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\WorkplacePosition;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildShiftOrderPreviewAction
{
    public const STATUS_NEW = 'new';

    public const STATUS_MATCH = 'match';

    public const STATUS_DIFF = 'diff';

    /**
     * @param array<int, array<string, mixed>> $plan
     * @return array<string, mixed>
     */
    public function __invoke(array $plan): array
    {
        $existingShifts = $this->loadExistingShifts($plan);
        $assignedCounts = $this->loadAssignedCounts($existingShifts->pluck('id')->all());
        $positionNames = $this->loadPositionNames($existingShifts, $assignedCounts);

        $workplaces = [];
        $summary = [self::STATUS_NEW => 0, self::STATUS_MATCH => 0, self::STATUS_DIFF => 0];

        foreach ($plan as $item) {
            $existing = $existingShifts->get($this->planKey($item));
            $entry = $this->buildEntry($item, $existing, $assignedCounts, $positionNames);
            $summary[$entry['status']]++;

            $workplaceKey = $item['workplace_id'];
            $shiftKey = $item['workplace_shift_id'];

            $workplaces[$workplaceKey] ??= [
                'workplace_id' => $item['workplace_id'],
                'workplace_name' => $item['workplace_name'],
                'shifts' => [],
            ];
            $workplaces[$workplaceKey]['shifts'][$shiftKey] ??= [
                'workplace_shift_id' => $item['workplace_shift_id'],
                'shift_label' => $item['shift_label'],
                'start_time' => $item['start_time'],
                'end_time' => $item['end_time'],
                'days' => [],
            ];
            $workplaces[$workplaceKey]['shifts'][$shiftKey]['days'][] = $entry;
        }

        $workplaces = array_values(
            array_map(function (array $workplace) {
                $workplace['shifts'] = array_values($workplace['shifts']);

                return $workplace;
            }, $workplaces)
        );

        return [
            'summary' => [
                'new_count' => $summary[self::STATUS_NEW],
                'match_count' => $summary[self::STATUS_MATCH],
                'diff_count' => $summary[self::STATUS_DIFF],
                'total_count' => count($plan),
            ],
            'workplaces' => $workplaces,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @param array<int, array<int, int>> $assignedCounts
     * @param array<int, string> $positionNames
     * @return array<string, mixed>
     */
    private function buildEntry(array $item, ?Shift $existing, array $assignedCounts, array $positionNames): array
    {
        $entry = [
            'date' => $item['date'],
            'workplace_shift_id' => $item['workplace_shift_id'],
            'fixed_total' => $item['fixed_total'],
            'spot_total' => $item['spot_total'],
            'positions' => $item['positions'],
            'existing' => null,
            'differences' => [],
            'status' => self::STATUS_NEW,
        ];

        if ($existing === null) {
            return $entry;
        }

        $assigned = $assignedCounts[$existing->id] ?? [];
        $existingTotals = $existing->positionTotals
            ->map(
                fn($total) => [
                    'workplace_position_id' => $total->workplace_position_id,
                    'name' => $total->workplacePosition?->name,
                    'fixed_total' => $total->fixed_total,
                ]
            )
            ->values()
            ->all();

        $existingPositions = [];
        foreach ($existingTotals as $total) {
            $existingPositions[] = [...$total, 'assigned_count' => $assigned[$total['workplace_position_id']] ?? 0];
        }

        foreach ($assigned as $positionId => $count) {
            if ($positionId === 0 || $this->hasPosition($existingTotals, $positionId)) {
                continue;
            }

            $existingPositions[] = [
                'workplace_position_id' => $positionId,
                'name' => $positionNames[$positionId] ?? null,
                'fixed_total' => 0,
                'assigned_count' => $count,
            ];
        }

        $entry['existing'] = [
            'shift_id' => $existing->id,
            'fixed_total' => $existing->fixed_total,
            'temp_total' => $existing->temp_total,
            'assigned_count' => (int) ($existing->assigned_count ?? 0),
            'unassigned_position_count' => $assigned[0] ?? 0,
            'positions' => $existingPositions,
        ];

        $differences = [];
        if ($existing->fixed_total !== $item['fixed_total']) {
            $differences[] = 'fixed_total';
        }
        if ($this->positionMap($item['positions']) !== $this->positionMap($existingTotals)) {
            $differences[] = 'positions';
        }

        $entry['differences'] = $differences;
        $entry['status'] = $differences === [] ? self::STATUS_MATCH : self::STATUS_DIFF;

        return $entry;
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     */
    private function hasPosition(array $positions, int $positionId): bool
    {
        foreach ($positions as $position) {
            if ((int) $position['workplace_position_id'] === $positionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, int> $shiftIds
     * @return array<int, array<int, int>> shift id => (workplace position id, 0 when none) => count
     */
    private function loadAssignedCounts(array $shiftIds): array
    {
        if ($shiftIds === []) {
            return [];
        }

        $rows = ShiftUser::query()
            ->select('shift_id', 'workplace_position_id', DB::raw('COUNT(*) as assigned'))
            ->whereIn('shift_id', $shiftIds)
            ->where('status', ShiftUserStatus::Approved)
            ->groupBy('shift_id', 'workplace_position_id')
            ->get();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row->shift_id][(int) $row->workplace_position_id] = (int) $row->assigned;
        }

        return $counts;
    }

    /**
     * @param Collection<string, Shift> $shifts
     * @param array<int, array<int, int>> $assignedCounts
     * @return array<int, string>
     */
    private function loadPositionNames(Collection $shifts, array $assignedCounts): array
    {
        $knownIds = $shifts
            ->flatMap(fn(Shift $shift) => $shift->positionTotals->pluck('workplace_position_id'))
            ->map(fn($id) => (int) $id)
            ->all();

        $missingIds = [];
        foreach ($assignedCounts as $positions) {
            foreach (array_keys($positions) as $positionId) {
                if ($positionId === 0 || in_array($positionId, $knownIds, true)) {
                    continue;
                }
                $missingIds[] = $positionId;
            }
        }

        if ($missingIds === []) {
            return [];
        }

        return WorkplacePosition::query()->whereIn('id', array_unique($missingIds))->pluck('name', 'id')->all();
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     * @return array<int, int>
     */
    private function positionMap(array $positions): array
    {
        $map = [];
        foreach ($positions as $position) {
            $map[(int) $position['workplace_position_id']] = (int) $position['fixed_total'];
        }
        ksort($map);

        return $map;
    }

    /**
     * @param array<int, array<string, mixed>> $plan
     * @return Collection<string, Shift>
     */
    private function loadExistingShifts(array $plan): Collection
    {
        if ($plan === []) {
            return collect();
        }

        return Shift::query()
            ->with(['positionTotals.workplacePosition'])
            ->withCount([
                'shiftUsers as assigned_count' => fn(Builder $query) => $query->where(
                    'status',
                    ShiftUserStatus::Approved
                ),
            ])
            ->whereIn('workplace_shift_id', array_unique(array_column($plan, 'workplace_shift_id')))
            ->whereIn('date', array_unique(array_column($plan, 'date')))
            ->get()
            ->keyBy(fn(Shift $shift) => $shift->workplace_shift_id . '|' . $shift->date);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function planKey(array $item): string
    {
        return $item['workplace_shift_id'] . '|' . $item['date'];
    }
}
