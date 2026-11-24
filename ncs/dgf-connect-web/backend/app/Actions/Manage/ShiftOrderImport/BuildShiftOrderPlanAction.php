<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Enums\PermissionName;
use App\Models\Shift;
use App\Models\User;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

class BuildShiftOrderPlanAction
{
    use ValidatesShiftOrderPlan;

    /**
     * @param array{blocks: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>} $parsed
     * @return array{plan: array<int, array<string, mixed>>, errors: array<int, array<string, mixed>>}
     */
    public function __invoke(User $user, array $parsed, string $month): array
    {
        $errors = $parsed['errors'];
        $plan = [];

        $monthStart = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $daysInMonth = $monthStart->daysInMonth;
        $today = Carbon::today();

        $workplaces = $this->loadWorkplaces($parsed['blocks']);

        foreach ($parsed['blocks'] as $block) {
            $workplace = $workplaces->get($block['workplace_id']);
            if ($workplace === null) {
                $errors[] = [
                    'code' => 'workplace_not_found',
                    'workplace_name' => $block['workplace_name'],
                    'row' => $block['row'],
                    'cell' => $this->cellRef(ParseShiftOrderFileAction::COL_WORKPLACE_ID, $block['row']),
                    'value' => (string) $block['workplace_id'],
                ];

                continue;
            }

            if (!$user->isAbleTo(PermissionName::ShiftImportOrder->value, $workplace->id)) {
                $errors[] = [
                    'code' => 'workplace_forbidden',
                    'workplace_name' => $workplace->name,
                    'row' => $block['row'],
                    'cell' => $this->cellRef(ParseShiftOrderFileAction::COL_WORKPLACE_ID, $block['row']),
                    'value' => (string) $workplace->id,
                ];

                continue;
            }

            [$blockPlan, $blockErrors] = $this->buildBlockPlan($block, $workplace, $monthStart, $daysInMonth, $today);
            $plan = array_merge($plan, $blockPlan);
            $errors = array_merge($errors, $blockErrors);
        }

        return ['plan' => $plan, 'errors' => $errors];
    }

    /**
     * @param array<string, mixed> $block
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array<string, mixed>>}
     */
    private function buildBlockPlan(
        array $block,
        Workplace $workplace,
        Carbon $monthStart,
        int $daysInMonth,
        Carbon $today
    ): array {
        $positionsByName = $this->loadPositions($workplace->id);
        $shiftsByTime = $this->loadWorkplaceShifts($workplace->id);

        $plan = [];
        $errors = [];

        foreach ($block['groups'] as $group) {
            $shiftRow = $group['shift'];
            $timeKey = $this->shiftTimeKey($shiftRow['start_time'], $shiftRow['end_time']);
            $workplaceShift = $shiftsByTime->get($timeKey);

            if ($workplaceShift === null) {
                $errors[] = [
                    'code' => 'workplace_shift_not_found',
                    'workplace_name' => $workplace->name,
                    'row' => $shiftRow['row'],
                    'cell' => $this->cellRef(ParseShiftOrderFileAction::COL_LABEL, $shiftRow['row']),
                    'value' => $shiftRow['label'],
                ];

                continue;
            }

            [$resolvedPositions, $positionErrors] = $this->resolvePositions(
                $group['positions'],
                $positionsByName,
                $workplace->name
            );
            if ($positionErrors !== []) {
                $errors = array_merge($errors, $positionErrors);

                continue;
            }

            $existingDates = $this->loadExistingShiftDates($workplaceShift->id, $monthStart, $daysInMonth);

            foreach ($block['day_columns'] as $column => $day) {
                $fixedTotal = $shiftRow['counts'][$column] ?? 0;
                $positionsSum = 0;
                foreach ($group['positions'] as $position) {
                    $positionsSum += $position['counts'][$column] ?? 0;
                }

                $isEmpty = $fixedTotal === 0 && $positionsSum === 0;
                if ($isEmpty && !$this->hasValueInColumn($group, $column)) {
                    continue;
                }

                if ($group['positions'] !== [] && $positionsSum !== $fixedTotal) {
                    $errors[] = [
                        'code' => 'total_mismatch',
                        'workplace_name' => $workplace->name,
                        'row' => $shiftRow['row'],
                        'cell' => $this->cellRef($column, $shiftRow['row']),
                        'shift_total' => $fixedTotal,
                        'positions_total' => $positionsSum,
                        'shift_label' => $shiftRow['label'],
                    ];

                    continue;
                }

                if ($day > $daysInMonth) {
                    if ($isEmpty) {
                        continue;
                    }

                    $errors[] = [
                        'code' => 'day_not_in_month',
                        'workplace_name' => $workplace->name,
                        'row' => $shiftRow['row'],
                        'cell' => $this->cellRef($column, $shiftRow['row']),
                        'value' => (string) $day,
                    ];

                    continue;
                }

                $date = $monthStart->copy()->day($day);
                if ($date->lt($today)) {
                    if ($isEmpty) {
                        continue;
                    }

                    $errors[] = [
                        'code' => 'past_date',
                        'workplace_name' => $workplace->name,
                        'row' => $shiftRow['row'],
                        'cell' => $this->cellRef($column, $shiftRow['row']),
                        'date' => $date->format('Y-m-d'),
                        'shift_label' => $shiftRow['label'],
                    ];

                    continue;
                }

                if ($isEmpty && !isset($existingDates[$date->format('Y-m-d')])) {
                    continue;
                }

                $planPositions = [];
                $mappedTotal = 0;
                foreach ($resolvedPositions as $index => $resolved) {
                    $count = $group['positions'][$index]['counts'][$column] ?? 0;
                    if ($resolved === null) {
                        continue;
                    }
                    $mappedTotal += $count;
                    if ($count === 0) {
                        continue;
                    }

                    $planPositions[] = [
                        'workplace_position_id' => $resolved->id,
                        'name' => $resolved->name,
                        'fixed_total' => $count,
                    ];
                }

                $plan[] = [
                    'workplace_id' => $workplace->id,
                    'workplace_name' => $workplace->name,
                    'workplace_shift_id' => $workplaceShift->id,
                    'shift_label' => $shiftRow['label'],
                    'start_time' => $workplaceShift->start_time,
                    'end_time' => $workplaceShift->end_time,
                    'date' => $date->format('Y-m-d'),
                    'fixed_total' => $fixedTotal,
                    'spot_total' => $fixedTotal - $mappedTotal,
                    'positions' => $planPositions,
                ];
            }
        }

        return [$plan, $errors];
    }

    /**
     * @param array<int, array<string, mixed>> $positionRows
     * @param Collection<string, WorkplacePosition> $positionsByName
     * @return array{0: array<int, WorkplacePosition|null>, 1: array<int, array<string, mixed>>}
     */
    private function resolvePositions(array $positionRows, Collection $positionsByName, string $workplaceName): array
    {
        $resolved = [];
        $errors = [];
        $seenPositionIds = [];

        foreach ($positionRows as $index => $positionRow) {
            $name = $positionRow['name'];
            if ($this->isSpotPosition($name)) {
                $resolved[$index] = null;

                continue;
            }

            $position = $positionsByName->get($this->positionKey($name));
            if ($position === null) {
                $errors[] = [
                    'code' => 'position_not_found',
                    'workplace_name' => $workplaceName,
                    'row' => $positionRow['row'],
                    'cell' => $this->cellRef(ParseShiftOrderFileAction::COL_LABEL, $positionRow['row']),
                    'value' => $name,
                ];

                continue;
            }

            if (isset($seenPositionIds[$position->id])) {
                $errors[] = [
                    'code' => 'duplicate_position',
                    'workplace_name' => $workplaceName,
                    'row' => $positionRow['row'],
                    'cell' => $this->cellRef(ParseShiftOrderFileAction::COL_LABEL, $positionRow['row']),
                    'value' => $name,
                ];

                continue;
            }

            $seenPositionIds[$position->id] = true;
            $resolved[$index] = $position;
        }

        return [$resolved, $errors];
    }

    /**
     * @param array<string, mixed> $group
     */
    private function hasValueInColumn(array $group, int $column): bool
    {
        if ($group['shift']['provided'][$column] ?? false) {
            return true;
        }

        foreach ($group['positions'] as $position) {
            if ($position['provided'][$column] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, true>
     */
    private function loadExistingShiftDates(int $workplaceShiftId, Carbon $monthStart, int $daysInMonth): array
    {
        return Shift::query()
            ->where('workplace_shift_id', $workplaceShiftId)
            ->whereBetween('date', [
                $monthStart->format('Y-m-d'),
                $monthStart->copy()->day($daysInMonth)->format('Y-m-d'),
            ])
            ->pluck('date')
            ->mapWithKeys(fn($date) => [Carbon::parse($date)->format('Y-m-d') => true])
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return Collection<int, Workplace>
     */
    private function loadWorkplaces(array $blocks): Collection
    {
        $ids = array_values(array_unique(array_column($blocks, 'workplace_id')));

        return Workplace::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @return Collection<string, WorkplacePosition>
     */
    private function loadPositions(int $workplaceId): Collection
    {
        return WorkplacePosition::query()
            ->where('workplace_id', $workplaceId)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn(WorkplacePosition $position) => $this->positionKey($position->name));
    }

    /**
     * @return Collection<string, WorkplaceShift>
     */
    private function loadWorkplaceShifts(int $workplaceId): Collection
    {
        return WorkplaceShift::query()
            ->where('workplace_id', $workplaceId)
            ->where('is_active', true)
            ->get()
            ->keyBy(fn(WorkplaceShift $shift) => $this->shiftTimeKey($shift->start_time, $shift->end_time));
    }

    private function positionKey(string $name): string
    {
        return mb_strtolower(preg_replace('/[\s\x{00A0}\x{3000}]+/u', '', $name) ?? $name);
    }

    private function cellRef(int $column, int $sheetRow): string
    {
        return Coordinate::stringFromColumnIndex($column + 1) . $sheetRow;
    }
}
