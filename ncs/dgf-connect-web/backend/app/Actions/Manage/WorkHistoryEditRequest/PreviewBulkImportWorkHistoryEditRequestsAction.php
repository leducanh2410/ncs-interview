<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\ActionResult;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Exports\WorkHistoryDetailExport;
use App\Models\CheckIn;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Services\Manage\WorkHistoryService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Turns a raw parsed grid (App\Imports\WorkHistoryDetailImport) into a validated bulk-import
 * preview: which Check_Ins changed, and what changed on them. Read-only - never writes to the
 * database. See .agents/specs/20260825-bulk-work-history-edit-import/design.md, Component 3.
 */
class PreviewBulkImportWorkHistoryEditRequestsAction
{
    private const COL_DATE = 1;

    private const COL_STAFF_NO = 3;

    private const COL_NAME = 4;

    private const COL_CHECK_IN_TIME = 6;

    private const COL_CHECK_OUT_TIME = 7;

    private const COL_BREAK_TIME = 10;

    private const COL_CHECK_IN_ID = 16;

    /**
     * Independent sanity bound on the other side of ResolveImportedAttendanceDatesAction's own
     * nearest-day-within-a-threshold heuristic: whatever threshold that heuristic uses, it is
     * still a guess based on temporal proximity to the live value, not on the shift's actual
     * length, so it can misfire on a large edit and land the resolved pair a full day off without
     * ever inverting their order (see resolveOrderError()'s docblock). No single work session
     * realistically spans more than a day, so this catches a misfire regardless of where that
     * threshold is tuned.
     */
    private const MAX_PLAUSIBLE_SHIFT_HOURS = 24;

    public function __construct(
        private readonly WorkHistoryService $workHistoryService,
        private readonly ResolveImportedAttendanceDatesAction $resolveDates
    ) {}

    /**
     * @param Collection<int, Collection<int, mixed>> $rows raw grid from WorkHistoryDetailImport
     */
    public function __invoke(User $user, Collection $rows): ActionResult
    {
        if ($rows->isEmpty()) {
            return $this->invalidFormat('empty_file');
        }

        $expectedHeadings = array_slice((new WorkHistoryDetailExport([]))->headings()[0], 0, self::COL_CHECK_IN_ID + 1);
        $headerMismatch = $this->findHeaderMismatch($expectedHeadings, $rows->get(0) ?? collect());
        if ($headerMismatch) {
            return $this->invalidFormat('header_mismatch', $headerMismatch);
        }

        // Header row aside, only a row with a Check_In_ID value is a data row - workplace-name
        // header rows and blank separator rows (the existing per-workplace grouping) are skipped.
        // Collection::slice() preserves original keys, so $excelRow = $key + 1 stays correct.
        $dataRows = $rows->slice(1)->filter(fn(Collection $row) => $this->hasValue($row->get(self::COL_CHECK_IN_ID)));
        if ($dataRows->isEmpty()) {
            return $this->invalidFormat('no_data_rows');
        }

        [$parsedRows, $errors] = $this->parseAndValidateCells($dataRows);
        if (!empty($errors)) {
            return $this->rowErrorsResult($errors);
        }

        $checkIns = CheckIn::query()
            ->whereIn('id', array_keys($parsedRows))
            ->with(['shift.workplace', 'shift.workplaceShift', 'user'])
            ->get()
            ->keyBy('id');

        $errors = $this->validateResolvedCheckIns($parsedRows, $checkIns);
        if (!empty($errors)) {
            return $this->rowErrorsResult($errors);
        }

        $workplaceId = $checkIns->first()->shift->workplace_id;
        if (!$user->isAbleTo(PermissionName::WorkHistoryEditRequestProcess->value, $workplaceId)) {
            return new ActionResult(error: ErrorCode::Forbidden, status: 403);
        }

        $errors = $this->validateNotAlreadyPending($parsedRows, $checkIns);
        if (!empty($errors)) {
            return $this->rowErrorsResult($errors);
        }

        $previewRows = [];
        $orderErrors = [];
        foreach ($parsedRows as $checkInId => $parsed) {
            $checkIn = $checkIns[$checkInId];
            $items = $this->reconcileRow($checkIn, $parsed);
            if (empty($items)) {
                continue;
            }

            // An edit to only one of check_in_time / check_out_time can still leave the *other*,
            // carried-over-as-is field inverted or implausibly far from it - e.g. correcting only
            // the check-in to a time later than an untouched live check-out. ApproveWorkHistoryEditRequestAction
            // would catch an inverted pair at commit time and abort the whole batch with an
            // unexplained 409 (and an implausible-but-not-inverted pair wouldn't be caught at
            // all); catching both here instead reports exactly which row, why, and what to fix,
            // before the admin ever gets to Confirm. A live pair that is already >24h and that
            // this import did not touch is skipped for the duration check (Excel cannot re-date
            // it, and blocking would only prevent an unrelated edit such as break). An already-
            // inverted live pair is still reported: Approve would 409 the whole batch.
            $orderError = $this->resolveOrderError($checkIn, $items);
            if ($orderError !== null) {
                $orderErrors[] = $this->rowError(
                    $parsed['excel_row'],
                    $parsed['employee_name'],
                    $parsed['date'],
                    $orderError
                );

                continue;
            }

            $previewRows[] = [
                'check_in_id' => $checkInId,
                'user_id' => $checkIn->user->id,
                'employee_name' => $checkIn->user->name,
                'employee_avatar' => $checkIn->user->profile_picture,
                'workplace_id' => $checkIn->shift->workplace_id,
                'workplace_name' => $checkIn->shift->workplace->name,
                'date' => Carbon::parse($checkIn->shift->date)->format('Y/n/j'),
                'items' => $items,
            ];
        }

        if (!empty($orderErrors)) {
            return $this->rowErrorsResult($orderErrors);
        }

        if (empty($previewRows)) {
            return new ActionResult(error: ErrorCode::WorkHistoryEditRequestImportNoChanges, status: 422);
        }

        return new ActionResult(data: ['rows' => $previewRows]);
    }

    /**
     * Checks the *final* effective check-in/check-out pair for this row - the resolved new value
     * for a field this import changed, else the field's own untouched live value - for two
     * distinct ways it can be wrong: the check-out ending up before the check-in, or the two
     * ending up implausibly far apart. Each gets its own ErrorCode (rather than reusing
     * ImportRowInvalidValue) because both values here are perfectly valid times - the problem is
     * how they relate to each other - and telling the admin that would otherwise leave them with
     * no idea what to actually change in the file.
     *
     * The inverted-order check always runs: ApproveWorkHistoryEditRequestAction would catch
     * an inverted pair at commit time and abort the whole batch with an unexplained 409,
     * even when this import only changed break minutes and carried the live times through.
     * The implausible-duration check is skipped when this import did not touch check_in_time
     * or check_out_time - a live pair that is already >24h (an approved single edit-request
     * can set check_in_date to any calendar day) is not something columns G/H can re-date,
     * and blocking the whole file would stop the admin from applying an unrelated edit.
     */
    private function resolveOrderError(CheckIn $checkIn, array $items): ?ErrorCode
    {
        $checkInItem = collect($items)->firstWhere('field', 'check_in_time');
        $checkOutItem = collect($items)->firstWhere('field', 'check_out_time');

        $finalCheckIn = Carbon::parse($checkInItem['new_value'] ?? $checkIn->check_in_time);

        if ($checkOutItem) {
            if ($checkOutItem['new_value'] === null) {
                // Clearing the check-out entirely can't be "inverted" or "too long".
                return null;
            }
            $finalCheckOut = Carbon::parse($checkOutItem['new_value']);
        } elseif ($checkIn->check_out_time) {
            $finalCheckOut = Carbon::parse($checkIn->check_out_time);
        } else {
            return null;
        }

        if ($finalCheckOut->lt($finalCheckIn)) {
            return ErrorCode::WorkHistoryEditRequestImportRowCheckOutBeforeCheckIn;
        }

        if ($checkInItem === null && $checkOutItem === null) {
            return null;
        }

        // abs() because Carbon 3 defaults diffInHours() to a signed result - $finalCheckOut is
        // already known to be >= $finalCheckIn at this point, but this keeps the comparison
        // correct regardless.
        if (abs($finalCheckOut->diffInHours($finalCheckIn)) > self::MAX_PLAUSIBLE_SHIFT_HOURS) {
            return ErrorCode::WorkHistoryEditRequestImportRowImplausibleDuration;
        }

        return null;
    }

    /**
     * First pass over the raw rows: parse each row's Check_In_ID and editable-field cells, and
     * collect (not short-circuit) every parse-level problem - a missing/duplicate id, a blank
     * check-in time, or a non-numeric value in a cell that must be numeric.
     *
     * @return array{0: array<int, array{excel_row: int, employee_name: string, date: string,
     *   check_in_time: float, check_out_time: ?float, break_time: float}>, 1: array<int, array>}
     */
    private function parseAndValidateCells(Collection $dataRows): array
    {
        $parsedRows = [];
        $errors = [];
        $seenIds = [];

        foreach ($dataRows as $key => $row) {
            $excelRow = $key + 1;
            $employeeName = (string) ($row->get(self::COL_NAME) ?? ($row->get(self::COL_STAFF_NO) ?? ''));
            $date = $this->formatDateCell($row->get(self::COL_DATE));

            $rawId = $row->get(self::COL_CHECK_IN_ID);
            if (!is_numeric($rawId)) {
                $errors[] = $this->rowError(
                    $excelRow,
                    $employeeName,
                    $date,
                    ErrorCode::WorkHistoryEditRequestImportRowIdMissing
                );

                continue;
            }
            $checkInId = (int) $rawId;
            if (isset($seenIds[$checkInId])) {
                $errors[] = $this->rowError(
                    $excelRow,
                    $employeeName,
                    $date,
                    ErrorCode::WorkHistoryEditRequestImportRowIdDuplicate
                );

                continue;
            }
            $seenIds[$checkInId] = true;

            // Unlike check_out_time (a blank H cell is a deliberate "clear the check-out") and
            // break_time (a blank K cell means "no break", see below), a blank check_in_time has
            // no valid meaning: check_ins.check_in_time is NOT NULL, so the cell being empty can
            // only be an accidental deletion. Left unchecked, a null here reaches
            // ResolveImportedAttendanceDatesAction's non-nullable float parameter and throws a
            // TypeError deep inside the request instead of surfacing as a row error.
            $checkInTime = $this->toFloatOrInvalid($row->get(self::COL_CHECK_IN_TIME));
            $checkOutTime = $this->toFloatOrInvalid($row->get(self::COL_CHECK_OUT_TIME));
            $rawBreakTime = $this->toFloatOrInvalid($row->get(self::COL_BREAK_TIME));
            if ($checkInTime === null || $checkInTime === false || $checkOutTime === false || $rawBreakTime === false) {
                $errors[] = $this->rowError(
                    $excelRow,
                    $employeeName,
                    $date,
                    ErrorCode::WorkHistoryEditRequestImportRowInvalidValue
                );

                continue;
            }
            // A blank K cell is treated as "no break" (0 minutes), not "leave unchanged" - the
            // export always writes a concrete break value (see
            // WorkHistoryService::buildDetailExportData), so an untouched file never has this
            // cell blank in the first place.
            $breakTime = $rawBreakTime ?? 0.0;

            $parsedRows[$checkInId] = [
                'excel_row' => $excelRow,
                'employee_name' => $employeeName,
                'date' => $date,
                'check_in_time' => $checkInTime,
                'check_out_time' => $checkOutTime,
                'break_time' => $breakTime,
            ];
        }

        return [$parsedRows, $errors];
    }

    /**
     * Second pass: every parsed id must resolve to an existing Check_In, and every resolved
     * Check_In must belong to the same workplace (the minority rows are reported when they
     * don't - Requirement 2.4/5.3).
     */
    private function validateResolvedCheckIns(array $parsedRows, Collection $checkIns): array
    {
        $errors = [];
        foreach ($parsedRows as $checkInId => $parsed) {
            if (!$checkIns->has($checkInId)) {
                $errors[] = $this->rowError(
                    $parsed['excel_row'],
                    $parsed['employee_name'],
                    $parsed['date'],
                    ErrorCode::WorkHistoryEditRequestImportRowIdNotFound
                );
            }
        }
        if (!empty($errors)) {
            return $errors;
        }

        $workplaceCounts = $checkIns->countBy(fn(CheckIn $checkIn) => $checkIn->shift->workplace_id);
        if ($workplaceCounts->count() <= 1) {
            return [];
        }

        $primaryWorkplaceId = $workplaceCounts->sortDesc()->keys()->first();
        foreach ($parsedRows as $checkInId => $parsed) {
            if ($checkIns[$checkInId]->shift->workplace_id !== $primaryWorkplaceId) {
                $errors[] = $this->rowError(
                    $parsed['excel_row'],
                    $parsed['employee_name'],
                    $parsed['date'],
                    ErrorCode::WorkHistoryEditRequestImportRowCrossWorkplace
                );
            }
        }

        return $errors;
    }

    private function validateNotAlreadyPending(array $parsedRows, Collection $checkIns): array
    {
        $pendingIds = WorkHistoryEditRequest::query()
            ->whereIn('check_in_id', array_keys($parsedRows))
            ->where('status', WorkHistoryEditRequestStatus::Pending->value)
            ->pluck('check_in_id')
            ->flip();

        $errors = [];
        foreach ($parsedRows as $checkInId => $parsed) {
            if ($pendingIds->has($checkInId)) {
                $errors[] = $this->rowError(
                    $parsed['excel_row'],
                    $parsed['employee_name'],
                    $parsed['date'],
                    ErrorCode::WorkHistoryEditRequestImportRowAlreadyPending
                );
            }
        }

        return $errors;
    }

    /**
     * @return array<int, array{field: string, old_value: ?string, new_value: ?string}>
     */
    private function reconcileRow(CheckIn $checkIn, array $parsed): array
    {
        $liveCheckInFraction = $this->workHistoryService->toDayFraction($checkIn->check_in_time);
        $liveCheckOutFraction = $this->workHistoryService->toDayFraction($checkIn->check_out_time);

        // The effective break value must be resolved the same way the (now-fixed) export
        // resolves it - Check_In-level override first, else the shift/workplace-shift default,
        // else 0 when none of the three is set - so an untouched K cell never looks like a
        // change. See design.md's Component 3 step 5 and WorkHistoryService::buildDetailExportData.
        $effectiveBreakMinutes =
            $checkIn->break_minutes ??
            ($checkIn->shift->break_minutes ?? ($checkIn->shift->workplaceShift->break_duration ?? 0));
        $liveBreakFraction = ((float) $effectiveBreakMinutes) / 1440;

        $checkInChanged = !$this->fractionsMatch($liveCheckInFraction, $parsed['check_in_time']);
        $checkOutChanged = !$this->fractionsMatch($liveCheckOutFraction, $parsed['check_out_time']);
        $breakChanged = !$this->fractionsMatch($liveBreakFraction, $parsed['break_time']);

        if (!$checkInChanged && !$checkOutChanged && !$breakChanged) {
            return [];
        }

        $anchorCheckInFraction = $checkInChanged ? $parsed['check_in_time'] : $liveCheckInFraction;
        [$shiftStart, $shiftEnd] = $checkIn->shift->getTimeRange();
        $resolved = ($this->resolveDates)(
            $checkIn->check_in_time,
            $anchorCheckInFraction,
            $checkIn->check_out_time,
            $checkOutChanged ? $parsed['check_out_time'] : null,
            $shiftStart->toDateTimeString(),
            $shiftEnd->toDateTimeString()
        );

        $items = [];

        if ($checkInChanged) {
            $items[] = [
                'field' => 'check_in_time',
                'old_value' => $checkIn->check_in_time,
                'new_value' => $resolved['check_in']->toDateTimeString(),
            ];
        }

        if ($checkOutChanged) {
            $items[] = [
                'field' => 'check_out_time',
                'old_value' => $checkIn->check_out_time,
                // A blank H cell is treated as "clear the check-out", matching how
                // ApproveWorkHistoryEditRequestAction already interprets a null new_value for
                // this field in the single-record flow.
                'new_value' => $parsed['check_out_time'] === null ? null : $resolved['check_out']->toDateTimeString(),
            ];
        }

        if ($breakChanged) {
            $newBreakMinutes = (int) round($parsed['break_time'] * 1440);
            $items[] = [
                'field' => 'break_minutes',
                // `old_value` must stay the raw Check_In column - ApproveWorkHistoryEditRequestAction
                // re-validates it against the live `check_ins.break_minutes` column at commit time
                // (liveValueMatches()), so it cannot be the resolved default here without every
                // untouched-override row falsely looking "drifted" and aborting the batch.
                'old_value' => $checkIn->break_minutes !== null ? (string) $checkIn->break_minutes : null,
                'new_value' => (string) $newBreakMinutes,
                // Display-only: what the admin actually saw as the "before" value (falls through
                // to the shift/workplace-shift default when there is no per-check-in override).
                // Not one of ImportCommitRequest's validated keys, so it never reaches the DB.
                'old_value_display' => (string) $effectiveBreakMinutes,
            ];
        }

        return $items;
    }

    private function fractionsMatch(?float $a, ?float $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        // Compare at minute precision - both sides are ultimately minute-granular values, and
        // independently-computed day-fractions can differ by float noise at that scale.
        return (int) round($a * 1440) === (int) round($b * 1440);
    }

    /**
     * @return float|null|false null when blank, false when present but not numeric
     */
    private function toFloatOrInvalid(mixed $value): float|null|false
    {
        if (!$this->hasValue($value)) {
            return null;
        }

        return is_numeric($value) ? (float) $value : false;
    }

    private function hasValue(mixed $value): bool
    {
        return $value !== null && $value !== '';
    }

    private function formatDateCell(mixed $value): string
    {
        if (is_numeric($value)) {
            return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y/n/j');
        }

        return (string) $value;
    }

    private function findHeaderMismatch(array $expected, Collection $headerRow): ?array
    {
        foreach ($expected as $index => $expectedHeading) {
            $found = $headerRow->get($index);
            if ((string) $found !== $expectedHeading) {
                return [
                    'column' => chr(65 + $index),
                    'expected' => $expectedHeading,
                    'found' => $found === null ? null : (string) $found,
                ];
            }
        }

        return null;
    }

    private function invalidFormat(string $reason, ?array $extra = null): ActionResult
    {
        return new ActionResult(
            error: ErrorCode::WorkHistoryEditRequestImportInvalidFormat,
            status: 422,
            data: array_merge(['reason' => $reason], $extra ?? [])
        );
    }

    private function rowError(int $excelRow, string $employeeName, string $date, ErrorCode $reason): array
    {
        return [
            'row' => $excelRow,
            'employee_name' => $employeeName,
            'date' => $date,
            'reason' => $reason->value,
        ];
    }

    private function rowErrorsResult(array $errors): ActionResult
    {
        return new ActionResult(
            error: ErrorCode::WorkHistoryEditRequestImportRowError,
            status: 422,
            data: ['errors' => $errors]
        );
    }
}
