<?php

namespace App\Actions\Manage\Reconciliation;

use App\Actions\Workplace\ShouldShowTestWorkplacesAction;
use App\Enums\ReconciliationCheckInStatus;
use App\Models\CheckIn;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationRow;
use App\Models\User;
use App\Services\Reconciliation\ReconciliationExportEligibility;
use App\Services\Reconciliation\ReconciliationFieldCompare;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SerializeReconciliationBatchAction
{
    public function __construct(
        private readonly ApplyReconciliationMatchAction $applyMatch,
        private readonly ShouldShowTestWorkplacesAction $shouldShowTestWorkplaces,
        private readonly ProjectReconciliationRoundingAction $projectRounding
    ) {}

    public function __invoke(ReconciliationBatch $batch, User $actor, bool $includeRows = true): array
    {
        $batch->loadMissing(['uploadedBy', 'confirmedBy', 'exportedBy']);

        $payload = [
            'id' => $batch->id,
            'source' => $batch->source,
            'original_filename' => $batch->original_filename,
            'company_label' => $batch->company_label,
            'status' => $batch->status,
            'punch_count' => $batch->punch_count,
            'skipped_non_punch_count' => $batch->skipped_non_punch_count,
            'matched_count' => $batch->matched_count,
            'differs_count' => $batch->differs_count,
            'unmatched_count' => $batch->unmatched_count,
            'manual_map_count' => $batch->manual_map_count,
            'uploaded_by' => $this->actor($batch->uploadedBy),
            'created_at' => optional($batch->created_at)?->toDateTimeString(),
            'confirmed_by' => $this->actor($batch->confirmedBy),
            'confirmed_at' => optional($batch->confirmed_at)?->toDateTimeString(),
            'exported_by' => $this->actor($batch->exportedBy),
            'exported_at' => optional($batch->exported_at)?->toDateTimeString(),
        ];

        if ($includeRows) {
            $batch->loadMissing([
                'rows.user',
                'rows.checkIn.shift.workplaceShift',
                'rows.checkIn.shift.workplace',
                'rows.checkIn.shiftUser',
                'rows.workplace',
            ]);
            $candidateUsers = $this->loadCandidateUsers($batch->rows);
            $candidateCheckIns = $this->loadCandidateCheckIns($batch->rows, $actor);
            $payload['rows'] = $batch->rows
                ->map(
                    fn(ReconciliationRow $row) => $this->serializeRow(
                        $row,
                        $actor,
                        $candidateUsers,
                        $candidateCheckIns,
                        $batch->isConfirmed()
                    )
                )
                ->all();
        }

        return $payload;
    }

    /**
     * @param Collection<int, ReconciliationRow> $rows
     * @return Collection<int, User>
     */
    private function loadCandidateUsers(Collection $rows): Collection
    {
        $ids = $rows->flatMap(fn(ReconciliationRow $row) => $row->candidate_user_ids ?? [])->unique()->values()->all();
        if ($ids === []) {
            return collect();
        }

        return User::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /**
     * @param Collection<int, ReconciliationRow> $rows
     * @return array<int, array<string, Collection<int, CheckIn>>>
     */
    private function loadCandidateCheckIns(Collection $rows, User $actor): array
    {
        $needed = [];
        foreach ($rows as $row) {
            $workDate = Carbon::parse($row->work_date)->toDateString();
            foreach ($row->candidate_user_ids ?? [] as $userId) {
                $needed[(int) $userId][$workDate] = true;
            }
            if ($row->user_id) {
                $needed[(int) $row->user_id][$workDate] = true;
            }
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

        return $this->applyMatch->indexCheckInsByUserAndDate(
            $this->applyMatch->findCheckInsForUsers(
                array_keys($needed),
                array_keys($dates),
                ($this->shouldShowTestWorkplaces)($actor)
            ),
            $needed
        );
    }

    /**
     * @param Collection<int, User> $candidateUsers
     * @param array<int, array<string, Collection<int, CheckIn>>> $candidateCheckIns
     */
    private function serializeRow(
        ReconciliationRow $row,
        User $actor,
        Collection $candidateUsers,
        array $candidateCheckIns,
        bool $confirmed
    ): array {
        $workDate = Carbon::parse($row->work_date)->toDateString();
        $mappedCheckIns = $row->user_id ? $candidateCheckIns[(int) $row->user_id][$workDate] ?? collect() : collect();
        $checkInStatus = $this->applyMatch->checkInStatus($row, $actor, $mappedCheckIns);
        $options =
            $checkInStatus === ReconciliationCheckInStatus::Multiple
                ? $mappedCheckIns->map(fn(CheckIn $checkIn) => $this->checkInOption($checkIn))->all()
                : [];

        [$dgfCheckInTime, $dgfCheckOutTime, $dgfBreak, $dgfSummary] = $this->dgfDisplay($row, $confirmed);
        $externalSummary = isset($row->raw['summary']) ? $this->summaryOf($row->raw['summary']) : null;

        return [
            'id' => $row->id,
            'external_name' => $row->external_name,
            'external_staff_no' => $row->raw['staff_no'] ?? null,
            'work_date' => $workDate,
            'external_check_in' => optional($row->external_check_in)?->toDateTimeString(),
            'external_check_out' => optional($row->external_check_out)?->toDateTimeString(),
            'external_break_minutes' => $row->external_break_minutes,
            'work_time_range' => $row->raw['work_time_range'] ?? null,
            'time_basis' => $row->holdsLegacyPunchTimes() ? 'punch' : 'paid',
            'external_summary' => $externalSummary,
            'match_status' => $row->match_status,
            'match_method' => $row->match_method,
            'skipped' => $row->skipped,
            'user' => $this->userSummary($row->user),
            'candidates' => collect($row->candidate_user_ids ?? [])
                ->map(
                    fn($id) => $this->userSummary(
                        $candidateUsers->get($id),
                        ($candidateCheckIns[(int) $id][$workDate] ?? collect())
                            ->map(fn(CheckIn $checkIn) => $this->checkInOption($checkIn))
                            ->all()
                    )
                )
                ->filter()
                ->values()
                ->all(),
            'check_in_status' => $checkInStatus->value,
            // The confirmed-batch escape hatch, decided here rather than
            // re-derived on the client: only a row that lost its check-in *and*
            // carries an accepted Amazon change blocks the export, and only
            // that row is one UpdateReconciliationRowAction will let through on
            // a frozen batch. Deliberately not gated on `skipped`, so the
            // control the screen just offered stays on screen — disabled and
            // labelled "Skipped" — instead of disappearing the moment it works.
            'can_skip' =>
                $confirmed &&
                $row->check_in_id === null &&
                ReconciliationExportEligibility::hasAcceptedAmazonChange($row),
            'check_ins_options' => $options,
            'check_in_id' => $row->check_in_id,
            'workplace' => $row->workplace ? ['id' => $row->workplace->id, 'name' => $row->workplace->name] : null,
            'compare_status' => $row->compare_status,
            'has_pending_edit_request' => $row->has_pending_edit_request,
            'dgf_check_in' => $dgfCheckInTime,
            'dgf_check_out' => $dgfCheckOutTime,
            'dgf_break_minutes' => $dgfBreak,
            'dgf_summary' => $dgfSummary,
            'summary_differs' => ReconciliationFieldCompare::summaryDiffers($externalSummary, $dgfSummary),
            'rounding_warnings' =>
                !$confirmed && $row->checkIn !== null ? ($this->projectRounding)($row, $row->checkIn) : [],
            'decisions' => [
                'check_in_time' => $row->decision_check_in,
                'check_out_time' => $row->decision_check_out,
                'break_minutes' => $row->decision_break,
            ],
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?int, 3: ?array<string, ?int>}
     */
    private function dgfDisplay(ReconciliationRow $row, bool $confirmed): array
    {
        if ($confirmed) {
            $paid = $row->snapshot_dgf_paid;
            if ($paid === null) {
                return [
                    optional($row->snapshot_dgf_check_in)?->toDateTimeString(),
                    optional($row->snapshot_dgf_check_out)?->toDateTimeString(),
                    $row->snapshot_effective_break,
                    null,
                ];
            }

            return [
                $paid['check_in'] ?? null,
                $paid['check_out'] ?? null,
                $row->snapshot_effective_break,
                $this->summaryOf($paid),
            ];
        }

        $dgfCheckIn = $row->checkIn;
        if ($dgfCheckIn === null) {
            return [null, null, null, null];
        }

        $paid = ReconciliationFieldCompare::paidSnapshot($dgfCheckIn);

        return [
            $paid['check_in'],
            $paid['check_out'],
            ReconciliationFieldCompare::effectiveBreakMinutes($dgfCheckIn),
            $this->summaryOf($paid),
        ];
    }

    /**
     * @param array<string, mixed> $paid
     * @return array<string, ?int>
     */
    private function summaryOf(array $paid): array
    {
        $summary = [];
        foreach (ReconciliationFieldCompare::SUMMARY_FIELDS as $field) {
            $summary[$field] = $paid[$field] ?? null;
        }

        return $summary;
    }

    private function checkInOption(CheckIn $checkIn): array
    {
        $shift = $checkIn->shift;
        $template = $shift->workplaceShift;
        $start = $template?->start_time;
        $end = $template?->end_time;

        return [
            'id' => $checkIn->id,
            'workplace_id' => $shift->workplace_id,
            'workplace_name' => $shift->workplace->name,
            'shift_time' => mb_trim(($start ?? '') . '-' . ($end ?? ''), '-'),
            'check_in_time' => $checkIn->check_in_time,
            'check_out_time' => $checkIn->check_out_time,
        ];
    }

    private function actor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->short_name ?: $user->name,
            'staff_no' => $user->staff_no,
            'profile_picture' => $user->profile_picture,
        ];
    }

    /**
     * @param list<array<string, mixed>> $checkIns
     */
    private function userSummary(?User $user, array $checkIns = []): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'staff_no' => $user->staff_no,
            'name' => $user->short_name ?: $user->name,
            'name_kana' => $user->name_kana,
            'profile_picture' => $user->profile_picture,
            'check_ins' => $checkIns,
        ];
    }
}
