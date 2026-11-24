<?php

namespace App\Actions\Common\Recurring;

use App\Enums\RecurringCompletableType;
use App\Models\RecurringCompletion;
use App\ValueObjects\Recurring\CountableShift;
use App\ValueObjects\Recurring\RecurringAnchor;
use App\ValueObjects\Recurring\RecurringTarget;
use App\ValueObjects\Recurring\ShiftPosition;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ResolveRecurringAnchorsAction
{
    /**
     * @param list<RecurringTarget> $targets
     * @param Collection<int, list<CountableShift>> $shiftsByWorkplace
     * @return array<string, RecurringAnchor>
     */
    public function __invoke(int $userId, array $targets, Collection $shiftsByWorkplace): array
    {
        $anchors = $this->loadRecordedAnchors($userId, $targets);

        $legacyDates = $this->loadLegacyCompletionDates($userId, $targets, $anchors);

        foreach ($targets as $target) {
            $legacyDate = $legacyDates[$target->type->value][$target->id] ?? null;
            if ($legacyDate === null) {
                continue;
            }

            foreach ($target->workplaceIds as $workplaceId) {
                $key = $target->key($workplaceId);
                if (isset($anchors[$key])) {
                    continue;
                }

                $anchors[$key] = new RecurringAnchor(
                    position: $this->latestWorkedOnOrBefore($shiftsByWorkplace->get($workplaceId, []), $legacyDate),
                    lastOccurrence: 1
                );
            }
        }

        return $anchors;
    }

    /**
     * @param list<RecurringTarget> $targets
     * @return array<string, RecurringAnchor>
     */
    private function loadRecordedAnchors(int $userId, array $targets): array
    {
        $idsByType = $this->idsByType($targets);
        if (empty($idsByType)) {
            return [];
        }

        $rows = RecurringCompletion::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($idsByType) {
                foreach ($idsByType as $type => $ids) {
                    $query->orWhere(
                        fn($typeQuery) => $typeQuery->where('completable_type', $type)->whereIn('completable_id', $ids)
                    );
                }
            })
            ->orderByDesc('occurrence')
            ->get();

        $anchors = [];
        foreach ($rows as $row) {
            $key = RecurringTarget::keyFor($row->completable_type, $row->completable_id, $row->workplace_id);
            if (isset($anchors[$key])) {
                continue;
            }

            $anchors[$key] = new RecurringAnchor(
                position: new ShiftPosition(
                    shiftUserId: $row->anchor_shift_user_id,
                    date: CarbonImmutable::parse($row->anchor_date)->startOfDay(),
                    startAt: CarbonImmutable::parse($row->anchor_start_at),
                    sortId: $row->anchor_sort_id
                ),
                lastOccurrence: $row->occurrence
            );
        }

        return $anchors;
    }

    /**
     * @param list<RecurringTarget> $targets
     * @param array<string, RecurringAnchor> $anchors
     * @return array<string, array<int, CarbonImmutable>>
     */
    private function loadLegacyCompletionDates(int $userId, array $targets, array $anchors): array
    {
        $pending = array_filter(
            $targets,
            fn(RecurringTarget $target) => collect($target->workplaceIds)->contains(
                fn(int $workplaceId) => !isset($anchors[$target->key($workplaceId)])
            )
        );

        $idsByType = $this->idsByType($pending);
        $dates = [];

        $pollIds = $idsByType[RecurringCompletableType::Poll->value] ?? [];
        if (!empty($pollIds)) {
            $dates[RecurringCompletableType::Poll->value] = DB::table('poll_responses')
                ->where('user_id', $userId)
                ->whereIn('poll_id', $pollIds)
                ->whereNull('workplace_id')
                ->groupBy('poll_id')
                ->selectRaw('poll_id as item_id, MAX(created_at) as completed_on')
                ->get()
                ->mapWithKeys(fn(object $row) => [(int) $row->item_id => CarbonImmutable::parse($row->completed_on)])
                ->all();
        }

        $examIds = $idsByType[RecurringCompletableType::Exam->value] ?? [];
        if (!empty($examIds)) {
            $dates[RecurringCompletableType::Exam->value] = DB::table('exam_results')
                ->join('exams', 'exams.id', '=', 'exam_results.exam_id')
                ->where('exam_results.user_id', $userId)
                ->whereIn('exam_results.exam_id', $examIds)
                ->whereNull('exam_results.workplace_id')
                ->whereNotNull('exam_results.completed_at')
                ->whereColumn('exam_results.score', '>=', 'exams.min_score')
                ->groupBy('exam_results.exam_id')
                ->selectRaw('exam_results.exam_id as item_id, MAX(exam_results.completed_at) as completed_on')
                ->get()
                ->mapWithKeys(fn(object $row) => [(int) $row->item_id => CarbonImmutable::parse($row->completed_on)])
                ->all();
        }

        return $dates;
    }

    /**
     * @param list<CountableShift> $shifts
     */
    private function latestWorkedOnOrBefore(array $shifts, CarbonImmutable $completedOn): ?ShiftPosition
    {
        $cutoff = $completedOn->toDateString();
        $latest = null;

        foreach ($shifts as $shift) {
            if ($shift->position->date->toDateString() > $cutoff) {
                break;
            }
            if ($shift->worked) {
                $latest = $shift->position;
            }
        }

        return $latest;
    }

    /**
     * @param array<RecurringTarget> $targets
     * @return array<string, list<int>>
     */
    private function idsByType(array $targets): array
    {
        $idsByType = [];
        foreach ($targets as $target) {
            $idsByType[$target->type->value][] = $target->id;
        }

        return array_map(fn(array $ids) => array_values(array_unique($ids)), $idsByType);
    }
}
