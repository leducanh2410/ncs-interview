<?php

namespace App\Actions\Manage\ShiftOrderImport;

use App\Actions\ActionResult;
use App\Actions\Manage\Shift\CreateShiftAction;
use App\Enums\ErrorCode;
use App\Enums\ImportBatchStatus;
use App\Enums\PermissionName;
use App\Models\ImportBatch;
use App\Models\Shift;
use App\Models\ShiftPositionTotal;
use App\Models\User;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConfirmShiftOrderImportAction
{
    public const SOURCE_FILE = 'file';

    public const SOURCE_SYSTEM = 'system';

    public function __construct(
        private readonly BuildShiftOrderPreviewAction $buildPreview,
        private readonly CreateShiftAction $createShift
    ) {}

    /**
     * @param array<int, array{workplace_shift_id: int, date: string, source: string}> $decisions
     */
    public function __invoke(User $user, ImportBatch $batch, array $decisions): ActionResult
    {
        if (!$batch->isShiftOrder() || $batch->imported_by !== $user->id) {
            return new ActionResult(error: ErrorCode::NotFound, status: 404);
        }

        if (!$user->hasPermission(PermissionName::ShiftImportOrder->value)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        if (!$batch->isPending()) {
            return new ActionResult(error: ErrorCode::ShiftOrderBatchNotPending, status: 400);
        }

        $plan = $batch->meta['plan'] ?? [];
        $decisionMap = $this->buildDecisionMap($decisions);
        $preview = ($this->buildPreview)($plan);

        $missingDecisions = $this->findMissingDecisions($preview, $decisionMap);
        if ($missingDecisions !== []) {
            return new ActionResult(
                error: ErrorCode::ShiftOrderFileHasErrors,
                status: 422,
                data: ['errors' => $missingDecisions]
            );
        }

        $errors = $this->validateStillApplicable($user, $plan);
        if ($errors !== []) {
            return new ActionResult(
                error: ErrorCode::ShiftOrderFileHasErrors,
                status: 422,
                data: ['errors' => $errors]
            );
        }

        $workplaceShifts = $this->loadWorkplaceShifts($plan);
        $createdCount = 0;
        $updatedCount = 0;
        $unchangedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use (
            $user,
            $batch,
            $plan,
            $decisionMap,
            $workplaceShifts,
            &$createdCount,
            &$updatedCount,
            &$unchangedCount,
            &$skippedCount
        ) {
            foreach ($plan as $item) {
                $key = $item['workplace_shift_id'] . '|' . $item['date'];
                if (($decisionMap[$key] ?? self::SOURCE_FILE) === self::SOURCE_SYSTEM) {
                    $skippedCount++;

                    continue;
                }

                $shift = Shift::query()
                    ->where('workplace_shift_id', $item['workplace_shift_id'])
                    ->where('date', $item['date'])
                    ->lockForUpdate()
                    ->first();

                if ($shift === null) {
                    if ($this->clearsTheShift($item)) {
                        $unchangedCount++;

                        continue;
                    }

                    $shift = ($this->createShift)(
                        $workplaceShifts->get($item['workplace_shift_id']),
                        $item['date'],
                        $user->id,
                        $item['fixed_total'],
                        0,
                        null,
                        null
                    );
                    $createdCount++;
                    $this->syncPositionTotals($shift, $item['positions']);

                    continue;
                }

                $changed =
                    $shift->fixed_total !== $item['fixed_total'] ||
                    $this->positionTotalsDiffer($shift, $item['positions']);
                $shift->update(['fixed_total' => $item['fixed_total']]);
                $this->syncPositionTotals($shift, $item['positions']);

                if ($changed) {
                    $updatedCount++;

                    continue;
                }

                $unchangedCount++;
            }

            $batch->update([
                'status' => ImportBatchStatus::Completed,
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'meta' => [
                    ...$batch->meta,
                    'unchanged_count' => $unchangedCount,
                    'skipped_count' => $skippedCount,
                    'decisions' => $decisionMap,
                ],
            ]);
        });

        return new ActionResult(
            data: [
                'batch' => $batch->refresh(),
                'created_count' => $createdCount,
                'updated_count' => $updatedCount,
                'unchanged_count' => $unchangedCount,
                'skipped_count' => $skippedCount,
            ]
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private function clearsTheShift(array $item): bool
    {
        return $item['fixed_total'] === 0 && $item['positions'] === [];
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     */
    private function positionTotalsDiffer(Shift $shift, array $positions): bool
    {
        $current = $shift
            ->positionTotals()
            ->pluck('fixed_total', 'workplace_position_id')
            ->map(fn($total) => (int) $total)
            ->sortKeys()
            ->all();

        $incoming = [];
        foreach ($positions as $position) {
            $incoming[(int) $position['workplace_position_id']] = (int) $position['fixed_total'];
        }
        ksort($incoming);

        return $current !== $incoming;
    }

    /**
     * @param array<int, array<string, mixed>> $positions
     */
    private function syncPositionTotals(Shift $shift, array $positions): void
    {
        $keptIds = [];
        foreach ($positions as $position) {
            ShiftPositionTotal::query()->updateOrCreate(
                [
                    'shift_id' => $shift->id,
                    'workplace_position_id' => $position['workplace_position_id'],
                ],
                [
                    'fixed_total' => $position['fixed_total'],
                    'temp_total' => 0,
                ]
            );
            $keptIds[] = $position['workplace_position_id'];
        }

        ShiftPositionTotal::query()
            ->where('shift_id', $shift->id)
            ->when($keptIds !== [], fn($query) => $query->whereNotIn('workplace_position_id', $keptIds))
            ->delete();
    }

    /**
     * @param array<int, array{workplace_shift_id: int, date: string, source: string}> $decisions
     * @return array<string, string>
     */
    private function buildDecisionMap(array $decisions): array
    {
        $map = [];
        foreach ($decisions as $decision) {
            $map[$decision['workplace_shift_id'] . '|' . $decision['date']] = $decision['source'];
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $preview
     * @param array<string, string> $decisionMap
     * @return array<int, array<string, mixed>>
     */
    private function findMissingDecisions(array $preview, array $decisionMap): array
    {
        $missing = [];
        foreach ($preview['workplaces'] as $workplace) {
            foreach ($workplace['shifts'] as $shift) {
                foreach ($shift['days'] as $day) {
                    if ($day['status'] !== BuildShiftOrderPreviewAction::STATUS_DIFF) {
                        continue;
                    }

                    $key = $day['workplace_shift_id'] . '|' . $day['date'];
                    if (isset($decisionMap[$key])) {
                        continue;
                    }

                    $missing[] = [
                        'code' => 'decision_required',
                        'workplace_name' => $workplace['workplace_name'],
                        'shift_label' => $shift['shift_label'],
                        'date' => $day['date'],
                    ];
                }
            }
        }

        return $missing;
    }

    /**
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, array<string, mixed>>
     */
    private function validateStillApplicable(User $user, array $plan): array
    {
        $errors = [];
        $workplaceShifts = $this->loadWorkplaceShifts($plan);
        $positionIds = WorkplacePosition::query()
            ->whereIn('id', $this->planPositionIds($plan))
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $today = Carbon::today();
        $checkedWorkplaces = [];
        foreach ($plan as $item) {
            if (Carbon::parse($item['date'])->lt($today)) {
                $errors[] = [
                    'code' => 'past_date',
                    'workplace_name' => $item['workplace_name'],
                    'date' => $item['date'],
                    'shift_label' => $item['shift_label'],
                ];

                continue;
            }

            if (!isset($checkedWorkplaces[$item['workplace_id']])) {
                $checkedWorkplaces[$item['workplace_id']] = $user->isAbleTo(
                    PermissionName::ShiftImportOrder->value,
                    $item['workplace_id']
                );
            }

            if (!$checkedWorkplaces[$item['workplace_id']]) {
                $errors[] = [
                    'code' => 'workplace_forbidden',
                    'workplace_name' => $item['workplace_name'],
                    'value' => (string) $item['workplace_id'],
                ];

                continue;
            }

            $workplaceShift = $workplaceShifts->get($item['workplace_shift_id']);
            if ($workplaceShift === null || !$workplaceShift->is_active) {
                $errors[] = [
                    'code' => 'workplace_shift_not_found',
                    'workplace_name' => $item['workplace_name'],
                    'value' => $item['shift_label'],
                ];

                continue;
            }

            foreach ($item['positions'] as $position) {
                if (in_array($position['workplace_position_id'], $positionIds, true)) {
                    continue;
                }

                $errors[] = [
                    'code' => 'position_not_found',
                    'workplace_name' => $item['workplace_name'],
                    'value' => $position['name'],
                ];
            }
        }

        return $this->uniqueErrors($errors);
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     * @return array<int, array<string, mixed>>
     */
    private function uniqueErrors(array $errors): array
    {
        $seen = [];
        $unique = [];
        foreach ($errors as $error) {
            $key = json_encode($error);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $error;
        }

        return $unique;
    }

    /**
     * @param array<int, array<string, mixed>> $plan
     * @return array<int, int>
     */
    private function planPositionIds(array $plan): array
    {
        $ids = [];
        foreach ($plan as $item) {
            foreach ($item['positions'] as $position) {
                $ids[] = (int) $position['workplace_position_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<int, array<string, mixed>> $plan
     * @return Collection<int, WorkplaceShift>
     */
    private function loadWorkplaceShifts(array $plan): Collection
    {
        return WorkplaceShift::query()
            ->whereIn('id', array_unique(array_column($plan, 'workplace_shift_id')))
            ->get()
            ->keyBy('id');
    }
}
