<?php

namespace App\Actions\Common\Recurring;

use App\ValueObjects\Recurring\CountableShift;
use App\ValueObjects\Recurring\RecurringAnchor;
use App\ValueObjects\Recurring\RecurringTarget;
use App\ValueObjects\Recurring\RecurringTrigger;
use App\ValueObjects\Recurring\ShiftPosition;

class ResolveRecurringTriggersAction
{
    public const WORKED_SHIFTS_BETWEEN_TRIGGERS = 9;

    public const MAX_GAP_DAYS = 30;

    public function __construct(
        private readonly LoadCountableShiftsAction $loadCountableShifts,
        private readonly ResolveRecurringAnchorsAction $resolveAnchors
    ) {}

    /**
     * @param list<RecurringTarget> $targets
     * @return array<string, RecurringTrigger|null>
     */
    public function __invoke(int $userId, array $targets): array
    {
        $targets = array_values(array_filter($targets, fn(RecurringTarget $target) => !empty($target->workplaceIds)));
        if (empty($targets)) {
            return [];
        }

        $workplaceIds = collect($targets)
            ->flatMap(fn(RecurringTarget $target) => $target->workplaceIds)
            ->unique()
            ->values()
            ->all();

        $shiftsByWorkplace = ($this->loadCountableShifts)($userId, $workplaceIds);
        $anchors = ($this->resolveAnchors)($userId, $targets, $shiftsByWorkplace);

        $triggers = [];
        foreach ($targets as $target) {
            foreach ($target->workplaceIds as $workplaceId) {
                $key = $target->key($workplaceId);
                $triggers[$key] = $this->resolveForWorkplace(
                    $workplaceId,
                    $shiftsByWorkplace->get($workplaceId, []),
                    $anchors[$key] ?? null
                );
            }
        }

        return $triggers;
    }

    /**
     * @param list<CountableShift> $shifts
     */
    public function resolveForWorkplace(int $workplaceId, array $shifts, ?RecurringAnchor $anchor): ?RecurringTrigger
    {
        $anchorPosition = $anchor?->position;
        $previousTrigger = $anchorPosition;
        $workedSinceTrigger = 0;
        $gapReference = $anchorPosition?->date;
        $current = null;
        $latestWorked = null;

        foreach ($shifts as $shift) {
            if ($shift->worked) {
                $latestWorked = $shift->position;
            }

            if ($anchorPosition !== null && !$shift->position->isAfter($anchorPosition)) {
                continue;
            }

            $isTrigger =
                $previousTrigger === null ||
                $workedSinceTrigger === self::WORKED_SHIFTS_BETWEEN_TRIGGERS ||
                ($gapReference !== null && $gapReference->diffInDays($shift->position->date) > self::MAX_GAP_DAYS);

            if ($isTrigger) {
                $current = $shift->position;
                $previousTrigger = $shift->position;
                $workedSinceTrigger = 0;
            } elseif ($shift->worked) {
                $workedSinceTrigger++;
            }

            $gapReference = $shift->position->date;
        }

        if ($current === null) {
            return null;
        }

        return new RecurringTrigger(
            workplaceId: $workplaceId,
            trigger: $current,
            completionAnchor: ShiftPosition::later($current, $latestWorked),
            occurrence: ($anchor?->lastOccurrence ?? 0) + 1
        );
    }
}
