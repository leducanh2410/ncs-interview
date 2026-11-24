<?php

namespace App\Actions\Manage\ShiftPlanning;

use App\Enums\ShiftPlanDraftAction;
use App\Helpers\WorkHelper;
use App\Models\ShiftPlanDraft;
use App\Models\WorkplaceShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the set-aware per-date work-data map for ONE candidate draft add/update, ready to be
 * handed straight to WorkHelper::validateShiftRegister (which then layers the
 * candidate's own contribution itself).
 *
 * Map = live DB base (WorkHelper::getWorkHours, no ignore)
 *     − original live rows replaced by draft `update` rows
 *     + the user's other draft `add`/`update` rows within the validation window
 *     − draft `remove` rows committing on the target date
 *     − the candidate itself.
 *
 * Draft removes from dates other than the committing one are intentionally NOT
 * subtracted: a date must never commit by relying on an uncommitted removal elsewhere
 * (requirements §4.3).
 */
class BuildShiftPlanWorkDataAction
{
    /**
     * @param array<string,array{total:float,hours:array,ranges:array,shift_count:int}> $liveBaseForUser
     *        WorkHelper::getWorkHours()[userId] (or [] when the user has no live rows)
     * @param Collection<int,ShiftPlanDraft> $userDraftsInWindow this user's drafts within the window
     * @param ShiftPlanDraft $candidate the draft `add`/`update` row being validated
     * @param string $targetDate Y-m-d being committed
     * @param array<int,WorkplaceShift> $workplaceShiftsById
     * @param array<string,int|null> $shiftBreakByKey break_minutes keyed by "Y-m-d|workplaceShiftId"
     * @return array<string,array{total:float,hours:array,ranges:array,shift_count:int}>
     */
    public function __invoke(
        array $liveBaseForUser,
        Collection $userDraftsInWindow,
        ShiftPlanDraft $candidate,
        string $targetDate,
        array $workplaceShiftsById,
        array $shiftBreakByKey
    ): array {
        $map = $this->cloneMap($liveBaseForUser);

        foreach ($userDraftsInWindow as $draft) {
            $workData = $this->draftWorkData($draft, $workplaceShiftsById, $shiftBreakByKey);
            if ($workData === null) {
                continue;
            }
            $date = $this->draftDate($draft);

            if ($draft->action === ShiftPlanDraftAction::Update) {
                $liveWorkData = $this->liveWorkData($draft);
                if ($liveWorkData !== null) {
                    $this->subtractContribution($map, $liveWorkData['date'], $liveWorkData['work_data']);
                }

                if ($draft->id !== $candidate->id) {
                    $this->addContribution($map, $date, $workData);
                }

                continue;
            }

            if ($draft->action === ShiftPlanDraftAction::Remove) {
                // Only honour removals on the date currently committing.
                if ($date === $targetDate) {
                    $liveWorkData = $this->liveWorkData($draft);
                    if ($liveWorkData !== null) {
                        $this->subtractContribution($map, $liveWorkData['date'], $liveWorkData['work_data']);
                    } else {
                        $this->subtractContribution($map, $date, $workData);
                    }
                }

                continue;
            }

            // action = add: include every other planned add in the window, never the candidate.
            if ($draft->id === $candidate->id) {
                continue;
            }
            $this->addContribution($map, $date, $workData);
        }

        return $map;
    }

    /**
     * @return array{date:string, work_data:array{hour: float, range: array{start: Carbon, end: Carbon}}}|null
     */
    private function liveWorkData(ShiftPlanDraft $draft): ?array
    {
        $shift = $draft->shiftUser?->shift;
        $workplaceShift = $shift?->workplaceShift;
        if (!$shift || !$workplaceShift) {
            return null;
        }

        $date = Carbon::parse($shift->date)->format('Y-m-d');

        return [
            'date' => $date,
            'work_data' => WorkHelper::computeShiftWorkData(
                $date,
                $workplaceShift->start_time,
                $workplaceShift->end_time,
                $workplaceShift->start_break_time,
                $workplaceShift->end_break_time,
                $shift->break_minutes
            ),
        ];
    }

    /**
     * @return array{hour: float, range: array{start: Carbon, end: Carbon}}|null
     */
    private function draftWorkData(ShiftPlanDraft $draft, array $workplaceShiftsById, array $shiftBreakByKey): ?array
    {
        $workplaceShift = $workplaceShiftsById[$draft->workplace_shift_id] ?? null;
        if (!$workplaceShift) {
            return null;
        }
        $date = $this->draftDate($draft);

        return WorkHelper::computeShiftWorkData(
            $date,
            $workplaceShift->start_time,
            $workplaceShift->end_time,
            $workplaceShift->start_break_time,
            $workplaceShift->end_break_time,
            $shiftBreakByKey["{$date}|{$draft->workplace_shift_id}"] ?? null
        );
    }

    private function draftDate(ShiftPlanDraft $draft): string
    {
        return $draft->date instanceof Carbon
            ? $draft->date->format('Y-m-d')
            : Carbon::parse($draft->date)->format('Y-m-d');
    }

    /**
     * @param array<string,array{total:float,hours:array,ranges:array,shift_count:int}> $map
     * @param array{hour: float, range: array{start: Carbon, end: Carbon}} $workData
     */
    private function addContribution(array &$map, string $date, array $workData): void
    {
        $entry = $map[$date] ?? ['total' => 0.0, 'hours' => [], 'ranges' => [], 'shift_count' => 0];
        $entry['total'] += $workData['hour'];
        $entry['hours'][] = $workData['hour'];
        $entry['ranges'][] = $workData['range'];
        $entry['shift_count']++;
        $map[$date] = $entry;
    }

    /**
     * @param array<string,array{total:float,hours:array,ranges:array,shift_count:int}> $map
     * @param array{hour: float, range: array{start: Carbon, end: Carbon}} $workData
     */
    private function subtractContribution(array &$map, string $date, array $workData): void
    {
        if (!isset($map[$date])) {
            return;
        }
        $entry = $map[$date];

        foreach ($entry['ranges'] as $i => $range) {
            if (
                $range['start']->equalTo($workData['range']['start']) &&
                $range['end']->equalTo($workData['range']['end'])
            ) {
                unset($entry['ranges'][$i]);
                $entry['ranges'] = array_values($entry['ranges']);
                break;
            }
        }
        foreach ($entry['hours'] as $i => $hour) {
            if (abs($hour - $workData['hour']) < 0.0001) {
                unset($entry['hours'][$i]);
                $entry['hours'] = array_values($entry['hours']);
                break;
            }
        }
        $entry['total'] = max(0.0, $entry['total'] - $workData['hour']);
        $entry['shift_count'] = max(0, $entry['shift_count'] - 1);
        $map[$date] = $entry;
    }

    /**
     * Deep-copy the per-date arrays so building one candidate's map never mutates the
     * shared live base reused across the other candidates. Carbon range instances are
     * read-only here, so they can be shared safely.
     *
     * @param array<string,array{total:float,hours:array,ranges:array,shift_count:int}> $map
     * @return array<string,array{total:float,hours:array,ranges:array,shift_count:int}>
     */
    private function cloneMap(array $map): array
    {
        $out = [];
        foreach ($map as $date => $entry) {
            $out[$date] = [
                'total' => $entry['total'],
                'hours' => $entry['hours'],
                'ranges' => $entry['ranges'],
                'shift_count' => $entry['shift_count'],
            ];
        }

        return $out;
    }
}
