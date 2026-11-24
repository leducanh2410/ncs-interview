<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Enums\ReconciliationCheckInStatus;
use App\Enums\ReconciliationCompareStatus;
use App\Enums\ReconciliationDecision;
use App\Enums\ReconciliationMatchMethod;
use App\Enums\ReconciliationMatchStatus;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Models\CheckIn;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Services\Reconciliation\CanonicalRow;
use App\Services\Reconciliation\ReconciliationFieldCompare;
use App\Services\Reconciliation\ReconciliationNameMatcher;
use App\Services\Reconciliation\ReconciliationNameNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ApplyReconciliationMatchAction
{
    /** @var Collection<int, User>|null */
    private ?Collection $staffNoIndexSource = null;

    /** @var array<string, list<User>> */
    private array $staffNoIndex = [];

    public function __construct(
        private readonly ReconciliationNameMatcher $nameMatcher,
        private readonly ShouldShowTestWorkplacesAction $shouldShowTestWorkplaces
    ) {}

    /**
     * @param Collection<int, User> $users
     * @param array<int, true> $priorityUserIds
     * @param array<string, list<int>> $duplicateKeys normalizedName|date => source indexes
     * @param Collection<int|string, Collection<int, CheckIn>>|null $checkInsByUser
     * @param array<int, true>|null $pendingCheckInIds
     */
    public function apply(
        ReconciliationRow $row,
        CanonicalRow $canonical,
        User $actor,
        Collection $users,
        array $priorityUserIds,
        array $duplicateKeys,
        ?int $forcedUserId = null,
        ?int $forcedCheckInId = null,
        ?Collection $checkInsByUser = null,
        ?array $pendingCheckInIds = null
    ): void {
        $isDuplicate = count($duplicateKeys[$canonical->duplicateKey()] ?? []) > 1;

        if ($forcedUserId !== null) {
            $user = $users->get($forcedUserId) ?? User::query()->find($forcedUserId);
            $row->user_id = $user?->id;
            $row->match_method = ReconciliationMatchMethod::Manual->value;
            $row->match_status = ReconciliationMatchStatus::Exact->value;
            $row->candidate_user_ids = null;
        } elseif ($isDuplicate) {
            $row->user_id = null;
            $row->match_method = null;
            $row->match_status = ReconciliationMatchStatus::DuplicateExternal->value;
            $row->candidate_user_ids = null;
            $user = null;
        } else {
            $match = $this->matchUser($canonical, $users, $priorityUserIds);
            $row->match_status = $match['status']->value;
            $row->match_method = $match['method']?->value;
            $row->user_id = $match['user']?->id;
            $row->candidate_user_ids = $match['candidate_ids'] ?: null;
            $user = $match['user'];
        }

        $this->attachCheckIn(
            $row,
            $user,
            $canonical->workDate,
            $actor,
            $forcedCheckInId,
            $checkInsByUser,
            $pendingCheckInIds
        );
        $this->applyComparison($row, $canonical);
    }

    /**
     * @param Collection<int|string, Collection<int, CheckIn>>|null $checkInsByUser
     * @param array<int, true>|null $pendingCheckInIds
     */
    public function attachCheckIn(
        ReconciliationRow $row,
        ?User $user,
        string $workDate,
        User $actor,
        ?int $forcedCheckInId = null,
        ?Collection $checkInsByUser = null,
        ?array $pendingCheckInIds = null
    ): void {
        $row->check_in_id = null;
        $row->workplace_id = null;
        $row->has_pending_edit_request = false;

        if ($user === null) {
            $row->compare_status = ReconciliationCompareStatus::NotComparable->value;

            return;
        }

        $checkIns =
            $checkInsByUser !== null
                ? $this->preferShiftDate($checkInsByUser->get($user->id, collect()), $workDate)
                : $this->findCheckIns($user->id, $workDate, ($this->shouldShowTestWorkplaces)($actor));

        if ($forcedCheckInId !== null) {
            $picked = $checkIns->firstWhere('id', $forcedCheckInId);
            if ($picked === null) {
                $row->compare_status = ReconciliationCompareStatus::NotComparable->value;

                return;
            }
            $this->bindCheckIn($row, $picked, $pendingCheckInIds);

            return;
        }

        if ($checkIns->count() === 1) {
            $this->bindCheckIn($row, $checkIns->first(), $pendingCheckInIds);

            return;
        }

        $row->compare_status = ReconciliationCompareStatus::NotComparable->value;
    }

    public function applyComparison(ReconciliationRow $row, CanonicalRow $canonical): void
    {
        if (!$row->check_in_id) {
            $this->resetToNotComparable($row);

            return;
        }

        $checkIn = $row->relationLoaded('checkIn') ? $row->checkIn : CheckIn::query()->find($row->check_in_id);
        if ($checkIn === null) {
            $this->resetToNotComparable($row);

            return;
        }

        $differs = ReconciliationFieldCompare::differs($canonical, $checkIn);
        $anyDiffers = $differs['check_in'] || $differs['check_out'] || $differs['break'];
        $row->compare_status = $anyDiffers
            ? ReconciliationCompareStatus::Differs->value
            : ReconciliationCompareStatus::Matched->value;

        $keep = ReconciliationDecision::Dgf->value;
        $row->decision_check_in = $differs['check_in'] ? null : $keep;
        $row->decision_check_out = $differs['check_out'] ? null : $keep;
        $row->decision_break = $differs['break'] ? null : $keep;

        // `snapshot_dgf_*` records the live DGF values compare_status/decisions
        // were just computed against, so refreshComparisonAgainstLiveCheckIn()
        // can later tell "DGF changed since we last looked" from "admin decided
        // to keep DGF" on a per-field basis. Confirm leaves these in sync with
        // CheckIn (see below), which is also what Export's staleness check reads.
        $row->snapshot_dgf_check_in = $checkIn->check_in_time;
        $row->snapshot_dgf_check_out = $checkIn->check_out_time;
        $row->snapshot_effective_break = ReconciliationFieldCompare::effectiveBreakMinutes($checkIn);
        $row->snapshot_break_column = $checkIn->break_minutes;
        $row->snapshot_dgf_paid = ReconciliationFieldCompare::paidSnapshot($checkIn);
    }

    /**
     * Re-checks a row's DGF fields against the live CheckIn right before
     * Confirm, so a real Amazon/DGF discrepancy introduced after decisions were
     * last computed (e.g. an approved edit request that changed the DGF
     * check-in) isn't silently confirmed.
     *
     * Runs per field, for every row with a check-in — not gated on row-level
     * `compare_status` — because a `differs` row can carry fields that were
     * auto-filled `dgf` (nothing differed for them at the time) sitting right
     * next to a field the admin genuinely decided; both need protecting the
     * same way a fully `matched` row does. The baseline for "did DGF change" is
     * `snapshot_dgf_*`, the DGF values decisions were last computed against
     * (set by applyComparison()) — not the external/Amazon values, so a field
     * an admin already resolved is only reopened when DGF itself moves again.
     *
     * A drifted field is re-compared against Amazon (`$canonical`): if the new
     * DGF value now matches Amazon (e.g. an edit request brought DGF back to
     * the punched time), the decision is auto-filled `dgf` instead of being
     * nulled — mirroring applyComparison() — since there both already agree.
     * Only a field that still genuinely differs from Amazon is reset to "needs
     * decision". `compare_status` is derived from whether any decision ends up
     * null, so it always matches what the FE needs a control for. The snapshot
     * is always resynced to the live values — even when nothing "drifted" by
     * the checks below — because `snapshot_break_column` tracks the *raw*
     * CheckIn column (what Export's isStale() compares against), which can
     * move independently of the *effective* break used for drift detection
     * here (e.g. the column going from NULL to an explicit value equal to the
     * shift-default fallback: effectiveBreakMinutes() is unchanged, but the
     * raw column did change). Leaving snapshot_break_column stale in that case
     * would make a freshly Confirmed batch immediately (and permanently, since
     * a Confirmed batch never re-runs this check) fail Export's staleness
     * check.
     *
     * A row without a live check-in — never bound because the employee had no
     * attendance on the work date at upload time, or bound to a check-in that
     * has since been hard-deleted (CheckIn has no SoftDeletes and
     * `check_in_id` is `nullOnDelete`, so the column empties underneath the
     * row) — is re-bound against DGF as it stands now. Doing nothing in either
     * case is what let Confirm accept a row Export would then drop without a
     * word: attendance entered while the batch was still Draft (a late
     * check-in, the user added to the shift) never reached the row, and a
     * deleted check-in left `compare_status`/decisions describing data that no
     * longer exists. attachCheckIn() only binds when exactly one check-in
     * exists, so 0 leaves the row `not_comparable` and >1 leaves it for
     * Confirm's Multiple check to stop.
     */
    public function refreshComparisonAgainstLiveCheckIn(
        ReconciliationRow $row,
        CanonicalRow $canonical,
        ?User $actor = null,
        ?Collection $preloadedCheckIns = null
    ): void {
        $checkIn = $row->check_in_id
            ? ($row->relationLoaded('checkIn')
                ? $row->checkIn
                : CheckIn::query()->find($row->check_in_id))
            : null;

        if ($checkIn === null) {
            $row->check_in_id = null;
            $row->workplace_id = null;

            if ($row->user_id && $actor !== null) {
                $user = $row->relationLoaded('user') ? $row->user : User::query()->find($row->user_id);
                if ($user !== null) {
                    $this->attachCheckIn(
                        $row,
                        $user,
                        Carbon::parse($row->work_date)->toDateString(),
                        $actor,
                        null,
                        // preferShiftDate() is idempotent, so handing back an
                        // already-date-filtered collection is safe and keeps
                        // callers that batch-load (Confirm, bulk-decide) off a
                        // per-row query.
                        $preloadedCheckIns !== null ? collect([$user->id => $preloadedCheckIns]) : null
                    );
                }
            }

            if ($row->check_in_id) {
                $this->applyComparison($row, $canonical);

                return;
            }

            // Unconditional (resetToNotComparable() is idempotent): a failed
            // attachCheckIn() above already set compare_status, so testing it
            // here would leave decisions/snapshots from a previous binding.
            $this->resetToNotComparable($row);

            return;
        }

        $liveCheckIn = $checkIn->check_in_time;
        $liveCheckOut = $checkIn->check_out_time;
        $liveBreak = ReconciliationFieldCompare::effectiveBreakMinutes($checkIn);
        $liveBreakColumn = $checkIn->break_minutes;

        $checkInDrifted = !ReconciliationFieldCompare::timesMatch(
            optional($row->snapshot_dgf_check_in)->toDateTimeString(),
            $liveCheckIn
        );
        $checkOutDrifted = !ReconciliationFieldCompare::timesMatch(
            optional($row->snapshot_dgf_check_out)->toDateTimeString(),
            $liveCheckOut
        );
        $breakDrifted = (int) $row->snapshot_effective_break !== $liveBreak;
        $snapshotPaid = $row->snapshot_dgf_paid;
        if ($snapshotPaid !== null) {
            $checkInDrifted =
                $checkInDrifted ||
                !ReconciliationFieldCompare::timesMatch(
                    $snapshotPaid['check_in'] ?? null,
                    ReconciliationFieldCompare::paidCheckIn($checkIn)
                );
            $checkOutDrifted =
                $checkOutDrifted ||
                !ReconciliationFieldCompare::timesMatch(
                    $snapshotPaid['check_out'] ?? null,
                    ReconciliationFieldCompare::paidCheckOut($checkIn)
                );
        }

        if ($checkInDrifted || $checkOutDrifted || $breakDrifted) {
            $differs = ReconciliationFieldCompare::differs($canonical, $checkIn);
            $keep = ReconciliationDecision::Dgf->value;

            if ($checkInDrifted) {
                $row->decision_check_in = $differs['check_in'] ? null : $keep;
            }
            if ($checkOutDrifted) {
                $row->decision_check_out = $differs['check_out'] ? null : $keep;
            }
            if ($breakDrifted) {
                $row->decision_break = $differs['break'] ? null : $keep;
            }

            $row->compare_status =
                $row->decision_check_in === null || $row->decision_check_out === null || $row->decision_break === null
                    ? ReconciliationCompareStatus::Differs->value
                    : ReconciliationCompareStatus::Matched->value;
        }

        $row->snapshot_dgf_check_in = $liveCheckIn;
        $row->snapshot_dgf_check_out = $liveCheckOut;
        $row->snapshot_effective_break = $liveBreak;
        $row->snapshot_break_column = $liveBreakColumn;
        $row->snapshot_dgf_paid = ReconciliationFieldCompare::paidSnapshot($checkIn);
    }

    public function showTestWorkplaces(User $actor): bool
    {
        return ($this->shouldShowTestWorkplaces)($actor);
    }

    /**
     * @param list<int> $checkInIds
     * @return array<int, true>
     */
    public function pendingCheckInIds(array $checkInIds): array
    {
        if ($checkInIds === []) {
            return [];
        }

        return array_fill_keys(
            WorkHistoryEditRequest::query()
                ->whereIn('check_in_id', $checkInIds)
                ->where('status', WorkHistoryEditRequestStatus::Pending->value)
                ->pluck('check_in_id')
                ->all(),
            true
        );
    }

    /**
     * @param list<string> $workDates
     * @return array<int, true> ids of users with a CheckIn on any of the given work dates
     */
    public function priorityUserIds(array $workDates, User $actor): array
    {
        $showTest = ($this->shouldShowTestWorkplaces)($actor);
        $ids = CheckIn::query()
            ->whereHas('shift', function ($query) use ($workDates, $showTest) {
                $query->where(function ($inner) use ($workDates) {
                    $inner->whereIn('date', $workDates)->orWhereIn('adjusted_date', $workDates);
                });
                if (!$showTest) {
                    $query->whereHas('workplace', fn($workplace) => $workplace->where('is_test', false));
                }
            })
            ->pluck('user_id')
            ->unique()
            ->all();

        return array_fill_keys($ids, true);
    }

    /**
     * @return Collection<int, CheckIn>
     */
    public function findCheckIns(int $userId, string $workDate, bool $showTestWorkplaces): Collection
    {
        return $this->preferShiftDate(
            $this->findCheckInsForUsers([$userId], [$workDate], $showTestWorkplaces),
            $workDate
        );
    }

    /**
     * @param list<int> $userIds
     * @param list<string> $workDates
     * @return Collection<int, CheckIn>
     */
    public function findCheckInsForUsers(array $userIds, array $workDates, bool $showTestWorkplaces): Collection
    {
        if ($userIds === [] || $workDates === []) {
            return collect();
        }

        return $this->checkInsOnDatesQuery($workDates, $showTestWorkplaces)->whereIn('user_id', $userIds)->get();
    }

    /**
     * @param list<string> $workDates
     * @return Collection<int, CheckIn>
     */
    public function findCheckInsOnDates(array $workDates, bool $showTestWorkplaces): Collection
    {
        if ($workDates === []) {
            return collect();
        }

        return $this->checkInsOnDatesQuery($workDates, $showTestWorkplaces)->get();
    }

    /**
     * @param Collection<int, CheckIn> $checkIns
     * @param array<int, array<string, true>> $needed userId => [workDate => true]
     * @return array<int, array<string, Collection<int, CheckIn>>>
     */
    public function indexCheckInsByUserAndDate(Collection $checkIns, array $needed): array
    {
        $loaded = [];
        $byUser = $checkIns->groupBy('user_id');
        foreach ($needed as $userId => $dates) {
            $userCheckIns = $byUser->get($userId, collect());
            foreach (array_keys($dates) as $workDate) {
                $loaded[$userId][$workDate] = $this->preferShiftDate($userCheckIns, $workDate);
            }
        }

        return $loaded;
    }

    /**
     * @param Collection<int, CheckIn> $checkIns
     * @return Collection<int, CheckIn>
     */
    public function preferShiftDate(Collection $checkIns, string $workDate): Collection
    {
        $byDate = $checkIns->filter(
            fn(CheckIn $checkIn) => Carbon::parse($checkIn->shift->date)->toDateString() === $workDate
        );
        if ($byDate->isNotEmpty()) {
            return $byDate->values();
        }

        return $checkIns
            ->filter(
                fn(CheckIn $checkIn) => Carbon::parse($checkIn->shift->adjusted_date)->toDateString() === $workDate
            )
            ->values();
    }

    /**
     * Check-ins for the rows that are mapped to a user but not bound to a
     * check-in, indexed userId => workDate => check-ins. Both the Confirm and
     * bulk-decide flows need exactly this set — to re-bind a check-in entered
     * after upload, and to tell "one check-in" from "several" — so it is loaded
     * once per request instead of per row.
     *
     * @param Collection<int, ReconciliationRow> $rows
     * @return array<int, array<string, Collection<int, CheckIn>>>
     */
    public function loadUnattachedCheckIns(Collection $rows, User $actor): array
    {
        $needed = [];
        foreach ($rows as $row) {
            if ($row->skipped || !$row->user_id || $row->check_in_id) {
                continue;
            }
            $needed[(int) $row->user_id][Carbon::parse($row->work_date)->toDateString()] = true;
        }
        if ($needed === []) {
            return [];
        }

        $dates = [];
        foreach ($needed as $dateMap) {
            foreach (array_keys($dateMap) as $date) {
                $dates[$date] = true;
            }
        }

        return $this->indexCheckInsByUserAndDate(
            $this->findCheckInsForUsers(
                array_keys($needed),
                array_keys($dates),
                ($this->shouldShowTestWorkplaces)($actor)
            ),
            $needed
        );
    }

    /**
     * @param array<int, array<string, Collection<int, CheckIn>>> $unattachedCheckIns
     * @return Collection<int, CheckIn>|null
     */
    public function unattachedCheckInsFor(ReconciliationRow $row, array $unattachedCheckIns): ?Collection
    {
        if (!$row->user_id || $row->check_in_id) {
            return null;
        }

        // A missing entry means the row wasn't unattached when the batch load
        // ran (its check-in has been hard-deleted since), so fall back to a
        // per-row lookup instead of reporting a confident "no attendance".
        return $unattachedCheckIns[(int) $row->user_id][Carbon::parse($row->work_date)->toDateString()] ?? null;
    }

    /**
     * A row mapped to a user but not bound to a check-in still reports `single`
     * when exactly one check-in exists for that user/date. That state only
     * arises when DGF attendance is entered after upload, and
     * refreshComparisonAgainstLiveCheckIn() binds it on the next Confirm /
     * bulk-decide / decision PATCH, so the status describes what the admin will
     * get rather than inventing a fifth state the FE would have to interpret.
     *
     * @param Collection<int, CheckIn>|null $preloadedCheckIns
     */
    public function checkInStatus(
        ReconciliationRow $row,
        User $actor,
        ?Collection $preloadedCheckIns = null
    ): ReconciliationCheckInStatus {
        if ($row->check_in_id) {
            return ReconciliationCheckInStatus::Single;
        }
        if (!$row->user_id) {
            return ReconciliationCheckInStatus::None;
        }
        $count =
            $preloadedCheckIns !== null
                ? $preloadedCheckIns->count()
                : $this->findCheckIns(
                    $row->user_id,
                    Carbon::parse($row->work_date)->toDateString(),
                    ($this->shouldShowTestWorkplaces)($actor)
                )->count();

        return match (true) {
            $count === 0 => ReconciliationCheckInStatus::None,
            $count === 1 => ReconciliationCheckInStatus::Single,
            default => ReconciliationCheckInStatus::Multiple,
        };
    }

    /**
     * @param Collection<int, User> $users
     * @param array<int, true> $priorityUserIds
     * @return array{
     *     status: ReconciliationMatchStatus,
     *     method: ?ReconciliationMatchMethod,
     *     user: ?User,
     *     candidate_ids: list<int>
     * }
     */
    private function matchUser(CanonicalRow $canonical, Collection $users, array $priorityUserIds): array
    {
        $staffNo = ReconciliationNameNormalizer::staffNo($canonical->staffNo);
        if ($staffNo === '') {
            return $this->nameMatcher->match($canonical->externalName, $users, $priorityUserIds);
        }

        $staffNoUsers = $this->staffNoIndex($users)[$staffNo] ?? [];
        if (count($staffNoUsers) === 1) {
            return [
                'status' => ReconciliationMatchStatus::Exact,
                'method' => ReconciliationMatchMethod::StaffNo,
                'user' => $staffNoUsers[0],
                'candidate_ids' => [],
            ];
        }
        if (count($staffNoUsers) > 1) {
            return [
                'status' => ReconciliationMatchStatus::Ambiguous,
                'method' => null,
                'user' => null,
                'candidate_ids' => array_map(fn(User $user) => $user->id, $staffNoUsers),
            ];
        }

        $match = $this->nameMatcher->match($canonical->externalName, $users, $priorityUserIds);
        if ($match['status'] !== ReconciliationMatchStatus::Exact || $match['user'] === null) {
            return $match;
        }

        return [
            'status' => ReconciliationMatchStatus::Similar,
            'method' => null,
            'user' => null,
            'candidate_ids' => [$match['user']->id],
        ];
    }

    /**
     * @param Collection<int, User> $users
     * @return array<string, list<User>>
     */
    private function staffNoIndex(Collection $users): array
    {
        if ($this->staffNoIndexSource !== $users) {
            $index = [];
            foreach ($users as $user) {
                $staffNo = ReconciliationNameNormalizer::staffNo($user->staff_no);
                if ($staffNo !== '') {
                    $index[$staffNo][] = $user;
                }
            }
            $this->staffNoIndexSource = $users;
            $this->staffNoIndex = $index;
        }

        return $this->staffNoIndex;
    }

    /**
     * @param list<string> $workDates
     * @return Builder<CheckIn>
     */
    private function checkInsOnDatesQuery(array $workDates, bool $showTestWorkplaces): Builder
    {
        return CheckIn::query()
            ->with(['shift.workplace', 'shift.workplaceShift', 'shiftUser'])
            ->whereHas('shift', function ($query) use ($workDates, $showTestWorkplaces) {
                $query->where(function ($inner) use ($workDates) {
                    $inner->whereIn('date', $workDates)->orWhereIn('adjusted_date', $workDates);
                });
                if (!$showTestWorkplaces) {
                    $query->whereHas('workplace', fn($workplace) => $workplace->where('is_test', false));
                }
            });
    }

    private function resetToNotComparable(ReconciliationRow $row): void
    {
        $row->compare_status = ReconciliationCompareStatus::NotComparable->value;
        $row->decision_check_in = null;
        $row->decision_check_out = null;
        $row->decision_break = null;
        $row->snapshot_dgf_check_in = null;
        $row->snapshot_dgf_check_out = null;
        $row->snapshot_effective_break = null;
        $row->snapshot_break_column = null;
        $row->snapshot_dgf_paid = null;
    }

    /**
     * @param array<int, true>|null $pendingCheckInIds
     */
    private function bindCheckIn(ReconciliationRow $row, CheckIn $checkIn, ?array $pendingCheckInIds = null): void
    {
        $row->setRelation('checkIn', $checkIn);
        $row->check_in_id = $checkIn->id;
        $row->workplace_id = $checkIn->shift->workplace_id;
        $row->has_pending_edit_request =
            $pendingCheckInIds !== null
                ? isset($pendingCheckInIds[$checkIn->id])
                : WorkHistoryEditRequest::query()
                    ->where('check_in_id', $checkIn->id)
                    ->where('status', WorkHistoryEditRequestStatus::Pending->value)
                    ->exists();
    }
}
