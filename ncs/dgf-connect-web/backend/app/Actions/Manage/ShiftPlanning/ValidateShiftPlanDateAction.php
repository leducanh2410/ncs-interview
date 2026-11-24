<?php

namespace App\Actions\Manage\ShiftPlanning;

use App\Actions\Manage\Shift\ValidatesShiftUser;
use App\Enums\ErrorCode;
use App\Enums\ShiftPlanDraftAction;
use App\Enums\ShiftUserStatus;
use App\Helpers\WorkHelper;
use App\Models\Shift;
use App\Models\ShiftPlanDraft;
use App\Models\ShiftUser;
use App\Models\SystemConfig;
use App\Models\User;
use App\Models\UserConfig;
use App\Models\Workplace;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Validates every planned change committing on ONE physical date for a workplace,
 * re-reading current live state and layering saved draft deltas (requirements §4.3).
 * Returns a flat list of per-cell errors; an empty list means the date is safe to
 * commit. Stateless and side-effect free so finalize can call it immediately before
 * the date's transaction.
 */
class ValidateShiftPlanDateAction
{
    use ValidatesShiftUser;

    public function __construct(private readonly BuildShiftPlanWorkDataAction $buildWorkData) {}

    /**
     * @return list<array{user_id:int, workplace_shift_id:int, date:string, code:string, extra:array|null}>
     */
    public function __invoke(Workplace $workplace, string $date): array
    {
        $target = Carbon::parse($date)->startOfDay();
        $rangeStart = $target->clone()->subDays(6)->startOfDay();
        $rangeEnd = $target->clone()->addDays(6)->endOfDay();

        $windowDrafts = ShiftPlanDraft::query()
            ->with(['shiftUser.shift.workplaceShift'])
            ->where('workplace_id', $workplace->id)
            ->whereBetween('date', [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')])
            ->get();

        $candidates = $windowDrafts
            ->filter(fn(ShiftPlanDraft $d) => $this->validatingAction($d) && $this->draftDate($d) === $date)
            ->values();

        if ($candidates->isEmpty()) {
            // Removal-only date never fails validation (it only frees capacity / hours).
            return [];
        }

        $errors = [];

        // --- shared lookups -------------------------------------------------
        $workplaceShiftsById = WorkplaceShift::query()
            ->where('workplace_id', $workplace->id)
            ->get()
            ->keyBy('id')
            ->all();

        $shifts = Shift::query()
            ->with(['workplace', 'workplaceShift'])
            ->where('workplace_id', $workplace->id)
            ->whereBetween('date', [$rangeStart->format('Y-m-d'), $rangeEnd->format('Y-m-d')])
            ->get();
        $shiftsByKey = [];
        $shiftBreakByKey = [];
        foreach ($shifts as $shift) {
            $key = $this->key(Carbon::parse($shift->date)->format('Y-m-d'), $shift->workplace_shift_id);
            $shiftsByKey[$key] = $shift;
            $shiftBreakByKey[$key] = $shift->break_minutes;
        }

        $userIds = $candidates->pluck('user_id')->unique()->values()->all();
        $liveBase = WorkHelper::getWorkHours($userIds, $rangeStart, $rangeEnd);
        $draftsByUser = $windowDrafts->groupBy('user_id');

        $systemConfig = SystemConfig::query()->first();
        $userConfigs = UserConfig::query()->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $users = User::query()->with('info')->whereIn('id', $userIds)->get()->keyBy('id');
        $memberIds = WorkplaceUser::query()
            ->where('workplace_id', $workplace->id)
            ->whereIn('user_id', $userIds)
            ->pluck('user_id')
            ->all();
        $memberIds = array_flip($memberIds);

        // --- per-candidate add/update validation ----------------------------
        foreach ($candidates as $candidate) {
            $shiftKey = $this->key($date, $candidate->workplace_shift_id);
            $shift = $shiftsByKey[$shiftKey] ?? null;
            if (!$shift) {
                $errors[] = $this->error($candidate, $date, ErrorCode::ShiftPlanningShiftMissing);

                continue;
            }

            if (!isset($memberIds[$candidate->user_id])) {
                $errors[] = $this->error($candidate, $date, ErrorCode::ScheduleUserNotMember);

                continue;
            }

            $user = $users->get($candidate->user_id);
            if ($user) {
                $expirationError = $this->checkUserExpiration($user, $target);
                if ($expirationError) {
                    $errors[] = $this->error($candidate, $date, $expirationError);

                    continue;
                }
            }

            $map = ($this->buildWorkData)(
                $liveBase[$candidate->user_id] ?? [],
                $draftsByUser->get($candidate->user_id, collect()),
                $candidate,
                $date,
                $workplaceShiftsById,
                $shiftBreakByKey
            );

            $workHourError = WorkHelper::validateShiftRegister(
                $shift,
                $rangeStart,
                $map,
                $systemConfig,
                $userConfigs->get($candidate->user_id)
            );
            if ($workHourError) {
                $errors[] = $this->error(
                    $candidate,
                    $date,
                    $workHourError['error'],
                    isset($workHourError['extraData']) ? ['over_hours' => $workHourError['extraData']] : null
                );
            }
        }

        // --- capacity per concrete shift slot on the target date ------------
        foreach ($this->capacityErrors($workplace, $date, $candidates, $windowDrafts, $shiftsByKey) as $error) {
            $errors[] = $error;
        }

        return $errors;
    }

    /**
     * Projected approved count per slot = live approved rows + draft adds/updates − rows
     * removed/replaced by drafts. Splits the planner's own contribution from
     * pre-existing live rows so the admin knows what to act on (requirements §3.3).
     *
     * @param Collection<int,ShiftPlanDraft> $candidates draft adds/updates on this date
     * @param Collection<int,ShiftPlanDraft> $windowDrafts all drafts in the window
     * @param array<string,Shift> $shiftsByKey
     * @return list<array{user_id:int, workplace_shift_id:int, date:string, code:string, extra:array|null}>
     */
    private function capacityErrors(
        Workplace $workplace,
        string $date,
        Collection $candidates,
        Collection $windowDrafts,
        array $shiftsByKey
    ): array {
        $errors = [];
        $plannerByShift = $candidates->groupBy('workplace_shift_id');
        if ($plannerByShift->isEmpty()) {
            return $errors;
        }

        $ignoredLiveUserIdsByShiftId = $this->ignoredLiveUserIdsByShiftId($windowDrafts, $date, $shiftsByKey);

        foreach ($plannerByShift as $workplaceShiftId => $plannerRows) {
            $shift = $shiftsByKey[$this->key($date, $workplaceShiftId)] ?? null;
            if (!$shift) {
                continue; // already reported as ShiftPlanningShiftMissing per candidate
            }

            $liveApproved = ShiftUser::query()
                ->where('shift_id', $shift->id)
                ->where('status', ShiftUserStatus::Approved->value)
                ->get(['user_id', 'is_fixed']);
            $ignoredLiveUserIds = $ignoredLiveUserIdsByShiftId[$shift->id] ?? [];

            foreach ([true, false] as $isFixed) {
                $preExisting = $liveApproved
                    ->filter(
                        fn($row) => (bool) $row->is_fixed === $isFixed && !isset($ignoredLiveUserIds[$row->user_id])
                    )
                    ->count();
                $plannerMatches = $plannerRows->filter(fn(ShiftPlanDraft $d) => (bool) $d->is_fixed === $isFixed);
                $planner = $plannerMatches->count();
                $capacity = $isFixed ? $shift->fixed_total : $shift->temp_total;
                $projected = $preExisting + $planner;

                if ($projected > $capacity) {
                    foreach ($plannerMatches as $plannerRow) {
                        $errors[] = $this->error($plannerRow, $date, ErrorCode::ScheduleCapacityExceeded, [
                            'is_fixed' => $isFixed,
                            'capacity' => $capacity,
                            'pre_existing' => $preExisting,
                            'planner' => $planner,
                            'projected' => $projected,
                        ]);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @param Collection<int,ShiftPlanDraft> $windowDrafts
     * @param array<string,Shift> $shiftsByKey
     * @return array<int,array<int,true>>
     */
    private function ignoredLiveUserIdsByShiftId(Collection $windowDrafts, string $date, array $shiftsByKey): array
    {
        $ignored = [];

        foreach ($windowDrafts as $draft) {
            if ($draft->action === ShiftPlanDraftAction::Remove && $this->draftDate($draft) !== $date) {
                continue;
            }

            if (!in_array($draft->action, [ShiftPlanDraftAction::Remove, ShiftPlanDraftAction::Update], true)) {
                continue;
            }

            $shift =
                $draft->shiftUser?->shift ??
                ($shiftsByKey[$this->key($this->draftDate($draft), $draft->workplace_shift_id)] ?? null);
            if (!$shift || Carbon::parse($shift->date)->format('Y-m-d') !== $date) {
                continue;
            }

            $ignored[$shift->id][$draft->user_id] = true;
        }

        return $ignored;
    }

    /**
     * @return array{user_id:int, workplace_shift_id:int, date:string, code:string, extra:array|null}
     */
    private function error(ShiftPlanDraft $draft, string $date, ErrorCode $code, ?array $extra = null): array
    {
        return [
            'user_id' => $draft->user_id,
            'workplace_shift_id' => $draft->workplace_shift_id,
            'date' => $date,
            'code' => $code->value,
            'extra' => $extra,
        ];
    }

    private function draftDate(ShiftPlanDraft $draft): string
    {
        return $draft->date instanceof Carbon
            ? $draft->date->format('Y-m-d')
            : Carbon::parse($draft->date)->format('Y-m-d');
    }

    private function validatingAction(ShiftPlanDraft $draft): bool
    {
        return in_array($draft->action, [ShiftPlanDraftAction::Add, ShiftPlanDraftAction::Update], true);
    }

    private function key(string $date, int $workplaceShiftId): string
    {
        return "{$date}|{$workplaceShiftId}";
    }
}
