<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\ApproveWorkHistoryEditRequestAction;
use App\Actions\Manage\WorkHistoryEditRequest\CreateWorkHistoryEditRequestAction;
use App\Actions\Manage\WorkHistoryEditRequest\PreviewBulkImportWorkHistoryEditRequestsAction;
use App\Enums\PermissionName;
use App\Enums\ShiftUserStatus;
use App\Enums\WorkHistoryEditRequestStatus;
use App\Exports\WorkHistoryDetailExport;
use App\Imports\WorkHistoryDetailImport;
use App\Models\CheckIn;
use App\Models\Shift;
use App\Models\ShiftUser;
use App\Models\User;
use App\Models\WorkHistoryEditRequest;
use App\Models\Workplace;
use App\Models\WorkplacePosition;
use App\Models\WorkplaceShift;
use App\Models\WorkplaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\GrantsWorkplacePermissions;
use Tests\TestCase;

class PreviewBulkImportWorkHistoryEditRequestsActionTest extends TestCase
{
    use GrantsWorkplacePermissions;
    use RefreshDatabase;

    private const COL_CHECK_IN_TIME = 6;

    private const COL_CHECK_OUT_TIME = 7;

    private const COL_BREAK_TIME = 10;

    private const COL_CHECK_IN_ID = 16;

    private PreviewBulkImportWorkHistoryEditRequestsAction $action;

    private User $manager;

    private Workplace $workplace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = app(PreviewBulkImportWorkHistoryEditRequestsAction::class);
        $this->workplace = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Addr A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->manager = User::factory()->create();
        $this->giveWorkplaceScopedPermission(
            $this->manager,
            PermissionName::WorkHistoryEditRequestProcess,
            $this->workplace->id
        );
    }

    public function testRejectsWrongHeader(): void
    {
        $rows = collect([collect(['Wrong', 'Header'])]);

        $result = ($this->action)($this->manager, $rows);

        $this->assertNotNull($result->getError());
        $this->assertSame(422, $result->getStatus());
        $this->assertSame('header_mismatch', $result->getData()['reason']);
        $this->assertSame('A', $result->getData()['column']);
    }

    public function testRejectsEmptyFile(): void
    {
        $result = ($this->action)($this->manager, collect());

        $this->assertSame('empty_file', $result->getData()['reason']);
    }

    public function testRejectsFileWithNoDataRows(): void
    {
        $header = (new WorkHistoryDetailExport([]))->headings()[0];
        $rows = collect([collect($header)]);

        $result = ($this->action)($this->manager, $rows);

        $this->assertSame('no_data_rows', $result->getData()['reason']);
    }

    public function testUnchangedRowIsExcludedAndReturnsNoChanges(): void
    {
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_NO_CHANGES', $result->getError()->value);
        $this->assertSame(0, WorkHistoryEditRequest::query()->count());
    }

    public function testAcceptsLegacyFileWithoutAttendanceColumns(): void
    {
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn)->map(fn(Collection $row) => $row->slice(0, 17));
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60 + 45) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $this->assertCount(1, $result->getData()['rows']);
    }

    public function testDetectsChangedCheckInTime(): void
    {
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60 + 45) / 1440; // was 09:00

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $rows = $result->getData()['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame($checkIn->id, $rows[0]['check_in_id']);
        $this->assertSame($checkIn->user_id, $rows[0]['user_id']);
        $this->assertSame($checkIn->shift->workplace_id, $rows[0]['workplace_id']);
        $this->assertArrayHasKey('employee_avatar', $rows[0]);
        $this->assertCount(1, $rows[0]['items']);
        $this->assertSame('check_in_time', $rows[0]['items'][0]['field']);
        $this->assertSame('2026-05-10 09:00:00', $rows[0]['items'][0]['old_value']);
        $this->assertSame('2026-05-10 08:45:00', $rows[0]['items'][0]['new_value']);
        $this->assertSame(0, WorkHistoryEditRequest::query()->count());
    }

    public function testDetectsChangedBreakMinutesUsingEffectiveLiveValue(): void
    {
        // The check-in has a per-check-in break override (45) that differs from the shift's
        // default (60) - the fixed export formula (Phase 1) writes 45 into K, so an untouched
        // file must compare against 45, not the shift default, or this would falsely detect a
        // change on every unrelated import.
        $checkIn = $this->seedCheckIn(breakMinutes: 45);
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_BREAK_TIME] = 30 / 1440;

        $result = ($this->action)($this->manager, $grid);

        $rows = $result->getData()['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame('break_minutes', $rows[0]['items'][0]['field']);
        $this->assertSame('45', $rows[0]['items'][0]['old_value']);
        $this->assertSame('30', $rows[0]['items'][0]['new_value']);
    }

    public function testUntouchedBreakMinutesWithOverrideDoesNotFalselyReportAChange(): void
    {
        $checkIn = $this->seedCheckIn(breakMinutes: 45);
        $grid = $this->buildGrid($checkIn);
        // Only check_in_time was touched by the admin; break stays whatever the (now-correct)
        // export already wrote for it.
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60 + 45) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $rows = $result->getData()['rows'];
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['items']);
        $this->assertSame('check_in_time', $rows[0]['items'][0]['field']);
    }

    public function testResolvesOvernightCheckOutToTheNextCalendarDay(): void
    {
        $checkIn = $this->seedCheckIn(checkOutTime: '01:00:00', checkOutDate: '2026-05-11');
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (0 * 60 + 30) / 1440; // 00:30, still after midnight

        $result = ($this->action)($this->manager, $grid);

        $rows = $result->getData()['rows'];
        $item = collect($rows[0]['items'])->firstWhere('field', 'check_out_time');
        $this->assertSame('2026-05-11 00:30:00', $item['new_value']);
    }

    public function testEditingOnlyCheckOutMinutesDoesNotRollItToTheNextDayWhenCheckInPrecedesTheShiftDate(): void
    {
        // Regression: shift dated 2026-05-10, but the live check-in (23:30) precedes it, landing
        // on 2026-05-09 - a check-in fraction (0.979) larger than the check-out fraction used to
        // be (mis)read as "the shift rolled past midnight" and pushed an untouched-day edit to
        // the check-out's minutes a full day forward.
        $checkIn = $this->seedCheckIn(checkInDate: '2026-05-09', checkInTime: '23:30:00', checkOutTime: '08:30:00');
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (8 * 60 + 45) / 1440; // was 08:30

        $result = ($this->action)($this->manager, $grid);

        $item = collect($result->getData()['rows'][0]['items'])->firstWhere('field', 'check_out_time');
        $this->assertSame('2026-05-10 08:45:00', $item['new_value']);
    }

    public function testEditingCheckOutToAnEarlierTimeOfDayRecordsAnOvernightStayInsteadOfInverting(): void
    {
        // Regression: correcting a normal day shift (live 09:00-18:00) to record that the
        // employee actually stayed overnight, by editing only the H column to 06:00, must resolve
        // to the *next* day's 06:00 - not silently produce a check-out before the check-in.
        $checkIn = $this->seedCheckIn(); // live 09:00 - 18:00
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (6 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $item = collect($result->getData()['rows'][0]['items'])->firstWhere('field', 'check_out_time');
        $this->assertSame('2026-05-11 06:00:00', $item['new_value']);
    }

    public function testBlocksWholeFileWhenEditingOnlyCheckInPastAnUntouchedCheckOutInvertsTheirOrder(): void
    {
        // Regression: each field is anchored to its own live date independently, so editing only
        // the check-in past the (untouched, carried-over-as-is) live check-out is never caught by
        // ResolveImportedAttendanceDatesAction's own invariant, which only ever runs when both
        // fields are resolved together in the same call. Left unchecked this would sail through
        // preview and only fail at commit with an unexplained 409.
        $checkIn = $this->seedCheckIn(); // live 09:00 - 18:00
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (19 * 60) / 1440; // after the untouched 18:00 check-out

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR', $result->getError()->value);
        // A dedicated reason, not the generic "not a valid number" - both G's 19:00 and the
        // untouched 18:00 check-out are perfectly valid values on their own; the problem is only
        // how they relate, and the admin needs to be told that to know what to fix.
        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testEditingNightShiftCheckOutToAnEarlierEveningLandsOnTheCheckInDate(): void
    {
        // AutoCheckOut on a night shift (20:00-05:00(+1)) stamps check-out at 05:00 the next
        // morning. Correcting H 05:00 -> 22:00 must resolve to 2026-05-10 22:00 (the same evening
        // as check-in), not stay on 2026-05-11 and get rejected as an implausibly long shift.
        $checkIn = $this->seedCheckIn(
            checkInDate: '2026-05-10',
            checkInTime: '19:55:00',
            checkOutDate: '2026-05-11',
            checkOutTime: '05:00:00',
            shiftStartTime: '20:00:00',
            shiftEndTime: '05:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (22 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $item = collect($result->getData()['rows'][0]['items'])->firstWhere('field', 'check_out_time');
        $this->assertSame('2026-05-10 22:00:00', $item['new_value']);
    }

    public function testEditingLongOvernightShiftCheckOutToAnEarlierEveningLandsOnTheCheckInDate(): void
    {
        // Real clock-in + AutoCheckOut: 13h overnight shift 18:00-07:00(+1), clocked in
        // 55 min early (inside check_in_before_hour), AutoCheckOut at 07:00. Editing H
        // 07:00 -> 18:00 is "sent home early the same evening" and must resolve to
        // 2026-05-10 18:00 (0.9h pair). Nearest-to-live used to pick 2026-05-11 18:00
        // and reject the whole file as IMPLAUSIBLE_DURATION.
        $checkIn = $this->seedCheckIn(
            checkInDate: '2026-05-10',
            checkInTime: '17:05:00',
            checkOutDate: '2026-05-11',
            checkOutTime: '07:00:00',
            shiftStartTime: '18:00:00',
            shiftEndTime: '07:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (18 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $item = collect($result->getData()['rows'][0]['items'])->firstWhere('field', 'check_out_time');
        $this->assertSame('2026-05-10 18:00:00', $item['new_value']);
    }

    public function testDoesNotBlockFileWhenOnlyBreakMinutesChangeOnAnAlreadyLongLivePair(): void
    {
        // A previously-approved single edit-request can set check_in_date to any calendar day
        // (CreateEditRequestRequest only requires date_format:Y-m-d). That is how a live pair
        // can end up >24h; the real clock-in path cannot (CheckInService::isCheckInTimeValid
        // rejects more than check_in_before_hour before start). Columns G/H cannot re-date
        // that pair, so editing only break must still import; resolveOrderError() used to
        // fire IMPLAUSIBLE_DURATION from the untouched live values and reject the whole file.
        $checkIn = $this->seedCheckInThenApproveTimes(
            checkInDate: '2026-05-09',
            checkInTime: '08:00:00',
            checkOutDate: '2026-05-10',
            checkOutTime: '18:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_BREAK_TIME] = 30 / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $rows = $result->getData()['rows'];
        $this->assertCount(1, $rows);
        $this->assertCount(1, $rows[0]['items']);
        $this->assertSame('break_minutes', $rows[0]['items'][0]['field']);
        $this->assertSame('30', $rows[0]['items'][0]['new_value']);
    }

    public function testEditingOnlyCheckOutMinutesOnALongLivePairDoesNotSilentlyDropTheCheckOutOntoTheCheckInDate(): void
    {
        // Same previously-approved-edit state as the >24h pair above. Anchoring H to the
        // check-in date used to produce 2026-05-09 17:30 (17:30 is later than 08:00, so the
        // inverted-order roll-forward never fired) and then sail through both guards as a
        // 9.5h pair. The date must resolve to the live check-out's 2026-05-10; the resulting
        // pair is still >24h, so the duration guard rejects the file rather than committing
        // a silently-wrong date.
        $checkIn = $this->seedCheckInThenApproveTimes(
            checkInDate: '2026-05-09',
            checkInTime: '08:00:00',
            checkOutDate: '2026-05-10',
            checkOutTime: '18:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (17 * 60 + 30) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR', $result->getError()->value);
        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testBlocksWholeFileWhenOnlyBreakMinutesChangeOnAnAlreadyInvertedLivePair(): void
    {
        // ApproveWorkHistoryEditRequestAction still 409s an inverted live pair at commit
        // (it carries the live check-out through when the request has no check_out_time
        // item). Preview used to skip resolveOrderError() entirely when G/H were untouched,
        // so a break-only edit on an inverted pair passed preview and then aborted the
        // whole batch with "data may have changed". Current clock-in / create / approve
        // flows cannot produce an inverted pair (they all validate order); this fixture
        // stands in for leftover data that would still hit that 409.
        $checkIn = $this->seedCheckIn(
            checkInDate: '2026-05-10',
            checkInTime: '18:00:00',
            checkOutDate: '2026-05-10',
            checkOutTime: '09:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_BREAK_TIME] = 30 / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR', $result->getError()->value);
        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CHECK_OUT_BEFORE_CHECK_IN',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testReanchorsALateOvernightCheckInToTheShiftDay(): void
    {
        // Overnight shift 18:00-02:00(+1) dated 2026-08-01; live check-in is 2026-08-02 00:05
        // (clocked in after midnight). Correcting G 00:05 -> 18:00 must land on 2026-08-01 18:00
        // (the scheduled start), not 2026-08-02 18:00 after the live check-out.
        $checkIn = $this->seedCheckIn(
            date: '2026-08-01',
            checkInDate: '2026-08-02',
            checkInTime: '00:05:00',
            checkOutDate: '2026-08-02',
            checkOutTime: '07:00:00',
            shiftStartTime: '18:00:00',
            shiftEndTime: '02:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (18 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $item = collect($result->getData()['rows'][0]['items'])->firstWhere('field', 'check_in_time');
        $this->assertSame('2026-08-01 18:00:00', $item['new_value']);
    }

    public function testEditingOnlyCheckOutMinutesEarlierThanCheckInOnALongLivePairDoesNotSilentlyDropTheCheckOutADay(): void
    {
        // Symmetric half of testEditingOnlyCheckOutMinutesOnALongLivePairDoesNotSilentlyDropTheCheckOutOntoTheCheckInDate:
        // live 2026-05-09 22:00 / 2026-05-11 06:00 (32h). Editing H 06:00 -> 05:00 used to
        // early-return 2026-05-10 05:00 (7h) and commit a silently-wrong date. The date must
        // resolve to the live check-out's 2026-05-11; the resulting pair is still >24h, so
        // the duration guard rejects the file.
        $checkIn = $this->seedCheckInThenApproveTimes(
            checkInDate: '2026-05-09',
            checkInTime: '22:00:00',
            checkOutDate: '2026-05-11',
            checkOutTime: '06:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (5 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR', $result->getError()->value);
        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testFirstCheckOutOnATwentyFourHourShiftLandsOnTheNextDay(): void
    {
        // start_time == end_time is a 24h shift. Clocked in at 09:00 with no checkout yet;
        // filling H = 09:00 must become 2026-05-11 09:00 (24h), not a 0-hour same-day pair.
        $checkIn = $this->seedCheckIn(
            checkInTime: '09:00:00',
            checkOutTime: null,
            shiftStartTime: '09:00:00',
            shiftEndTime: '09:00:00'
        );
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_OUT_TIME] = (9 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $item = collect($result->getData()['rows'][0]['items'])->firstWhere('field', 'check_out_time');
        $this->assertSame('2026-05-11 09:00:00', $item['new_value']);
    }

    public function testBlocksWholeFileWhenAnEditedCheckInLeavesAnImplausiblyLongPair(): void
    {
        // The duration guard still applies when this import actually changes check-in/check-out.
        // A live check-out two days later is inside no padded shift window of a day shift, so
        // editing only G keeps the live check-out and the resulting pair is >24h.
        $checkIn = $this->seedCheckIn(checkInTime: '09:00:00', checkOutDate: '2026-05-12', checkOutTime: '18:00:00');
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60 + 45) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR', $result->getError()->value);
        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_IMPLAUSIBLE_DURATION',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testBlocksWholeFileWhenCheckInTimeCellIsBlank(): void
    {
        // check_ins.check_in_time is NOT NULL - a blank G cell can only be an accidental
        // deletion, and must be rejected as a row error rather than reaching
        // ResolveImportedAttendanceDatesAction with a null fraction (which used to throw a
        // TypeError / 500).
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = null;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR', $result->getError()->value);
        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_INVALID_VALUE',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testBlankBreakTimeCellIsTreatedAsZeroMinutes(): void
    {
        // A blank K cell means "no break", not "leave unchanged" - the export always writes a
        // concrete value there, so a legitimately untouched file is never blank in this column.
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_BREAK_TIME] = null;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $rows = $result->getData()['rows'];
        $this->assertCount(1, $rows);
        $item = collect($rows[0]['items'])->firstWhere('field', 'break_minutes');
        $this->assertNull($item['old_value']);
        $this->assertSame('60', $item['old_value_display']);
        $this->assertSame('0', $item['new_value']);
    }

    public function testBreakMinutesOldValueDisplayShowsTheEffectiveDefaultNotARawNull(): void
    {
        // The Check_In has no per-record override (break_minutes is null), so the effective
        // value the admin actually saw is the shift's workplace default (60). `old_value` itself
        // must stay the raw column (see ApproveWorkHistoryEditRequestAction::liveValueMatches),
        // but the display-only counterpart must show 60, not a confusing "-".
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_BREAK_TIME] = 30 / 1440;

        $result = ($this->action)($this->manager, $grid);

        $item = collect($result->getData()['rows'][0]['items'])->firstWhere('field', 'break_minutes');
        $this->assertNull($item['old_value']);
        $this->assertSame('60', $item['old_value_display']);
        $this->assertSame('30', $item['new_value']);
    }

    public function testBlocksWholeFileOnDuplicateCheckInId(): void
    {
        $checkInA = $this->seedCheckIn();
        $checkInB = $this->seedCheckIn(checkInTime: '10:00:00');
        $grid = $this->buildGrid($checkInA, $checkInB);
        $grid[3][self::COL_CHECK_IN_ID] = $checkInA->id; // duplicate

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ERROR', $result->getError()->value);
        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_DUPLICATE',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testRowErrorShowsDateFromExcelDateCell(): void
    {
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_ID] = 999999;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('2026/5/10', $result->getData()['errors'][0]['date']);
    }

    public function testRowErrorShowsDateFromLegacyTextDateCell(): void
    {
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][1] = '2026/5/10';
        $grid[2][self::COL_CHECK_IN_ID] = 999999;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame('2026/5/10', $result->getData()['errors'][0]['date']);
    }

    public function testBlocksWholeFileOnUnresolvableCheckInId(): void
    {
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_ID] = 999999;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ID_NOT_FOUND',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testBlocksWholeFileWhenACheckInAlreadyHasAPendingRequest(): void
    {
        $checkIn = $this->seedCheckIn();
        WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $this->workplace->id,
            'status' => WorkHistoryEditRequestStatus::Pending->value,
        ]);
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_ALREADY_PENDING',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testAllowsImportWhenAPriorRequestForTheCheckInWasRejected(): void
    {
        // Accumulated-history case: only a *pending* request blocks; a resolved one does not.
        $checkIn = $this->seedCheckIn();
        WorkHistoryEditRequest::create([
            'check_in_id' => $checkIn->id,
            'workplace_id' => $this->workplace->id,
            'status' => WorkHistoryEditRequestStatus::Rejected->value,
            'reject_reason' => 'Not applicable anymore',
        ]);
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60) / 1440;

        $result = ($this->action)($this->manager, $grid);

        $this->assertNull($result->getError());
        $this->assertCount(1, $result->getData()['rows']);
    }

    public function testBlocksWholeFileWhenRowsSpanMoreThanOneWorkplace(): void
    {
        $checkInA = $this->seedCheckIn();
        $otherWorkplace = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Addr B',
            'latitude' => 36.0,
            'longitude' => 140.0,
        ]);
        $checkInB = $this->seedCheckIn(workplace: $otherWorkplace);
        $grid = $this->buildGrid($checkInA, $checkInB);

        $result = ($this->action)($this->manager, $grid);

        $this->assertSame(
            'WORK_HISTORY_EDIT_REQUEST_IMPORT_ROW_CROSS_WORKPLACE',
            $result->getData()['errors'][0]['reason']
        );
    }

    public function testForbidsCallerWithoutProcessPermissionForTheWorkplace(): void
    {
        $outsider = User::factory()->create();
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60) / 1440;

        $result = ($this->action)($outsider, $grid);

        $this->assertSame(403, $result->getStatus());
    }

    public function testNeverWritesToTheDatabase(): void
    {
        $checkIn = $this->seedCheckIn();
        $grid = $this->buildGrid($checkIn);
        $grid[2][self::COL_CHECK_IN_TIME] = (8 * 60) / 1440;

        ($this->action)($this->manager, $grid);

        $this->assertSame(0, WorkHistoryEditRequest::query()->count());
        $this->assertSame('2026-05-10 09:00:00', $checkIn->fresh()->check_in_time);
    }

    /**
     * Reaches a Check_In whose times differ from the original clock-in by going through the
     * real Create → Approve cycle. CreateEditRequestRequest allows any check_in_date, which
     * is how a live pair can end up on a different calendar day from the shift (or span >24h).
     */
    private function seedCheckInThenApproveTimes(
        string $checkInDate,
        string $checkInTime,
        string $checkOutDate,
        string $checkOutTime
    ): CheckIn {
        Queue::fake();
        $this->giveWorkplaceScopedPermission($this->manager, PermissionName::WorkHistoryEdit, $this->workplace->id);

        $checkIn = $this->seedCheckIn();
        $submit = app(CreateWorkHistoryEditRequestAction::class)($this->manager, $checkIn->fresh(), [
            'check_in_date' => $checkInDate,
            'check_in_time' => $checkInTime,
            'check_out_date' => $checkOutDate,
            'check_out_time' => $checkOutTime,
            'check_in_reason' => 'Chấm nhầm ngày',
            'check_out_reason' => 'Chấm nhầm ngày',
        ]);
        $this->assertNull($submit->getError());

        $approve = app(ApproveWorkHistoryEditRequestAction::class)($this->manager, $submit->getData());
        $this->assertNull($approve->getError());

        $checkIn->refresh();
        $this->assertSame("{$checkInDate} {$checkInTime}", $checkIn->check_in_time);
        $this->assertSame("{$checkOutDate} {$checkOutTime}", $checkIn->check_out_time);

        return $checkIn;
    }

    private function seedCheckIn(
        ?Workplace $workplace = null,
        string $date = '2026-05-10',
        string $checkInTime = '09:00:00',
        ?string $checkOutTime = '18:00:00',
        ?string $checkOutDate = null,
        ?int $breakMinutes = null,
        ?string $checkInDate = null,
        string $shiftStartTime = '09:00:00',
        string $shiftEndTime = '18:00:00'
    ): CheckIn {
        $workplace ??= $this->workplace;
        $checkOutDate ??= $date;
        $checkInDate ??= $date;

        $employee = User::factory()->create();
        $workplaceShift = WorkplaceShift::create([
            'workplace_id' => $workplace->id,
            'start_time' => $shiftStartTime,
            'end_time' => $shiftEndTime,
            'break_duration' => 60,
            'check_in_round_type' => 1,
            'check_in_round_minutes' => 15,
            'check_out_round_type' => 1,
            'check_out_round_minutes' => 15,
        ]);
        $position = WorkplacePosition::query()->firstOrCreate(['workplace_id' => $workplace->id, 'name' => 'Staff']);
        $shift = Shift::create([
            'workplace_id' => $workplace->id,
            'workplace_shift_id' => $workplaceShift->id,
            'creator_id' => $employee->id,
            'date' => $date,
            'adjusted_date' => $date,
            'fixed_total' => 1,
            'temp_total' => 1,
        ]);
        WorkplaceUser::query()->firstOrCreate(['user_id' => $employee->id, 'workplace_id' => $workplace->id]);
        $shiftUser = ShiftUser::create([
            'shift_id' => $shift->id,
            'user_id' => $employee->id,
            'workplace_position_id' => $position->id,
            'status' => ShiftUserStatus::Approved->value,
        ]);

        return CheckIn::create([
            'shift_id' => $shift->id,
            'shift_user_id' => $shiftUser->id,
            'user_id' => $employee->id,
            'check_in_time' => "{$checkInDate} {$checkInTime}",
            'check_out_time' => $checkOutTime !== null ? "{$checkOutDate} {$checkOutTime}" : null,
            'break_minutes' => $breakMinutes,
            'work_minutes' => 480,
            'work_minutes_normal' => 480,
            'work_minutes_night' => 0,
            'work_minutes_overtime' => 0,
            'work_minutes_night_overtime' => 0,
        ]);
    }

    /**
     * Builds a real exported-then-reimported grid for the given check-ins, via the actual
     * WorkHistoryService/WorkHistoryDetailExport/WorkHistoryDetailImport pipeline (not a
     * hand-authored fixture), so the tested header/column layout is always the real one.
     */
    private function buildGrid(CheckIn ...$checkIns): Collection
    {
        $service = app(\App\Services\Manage\WorkHistoryService::class);
        $rows = [];
        foreach ($checkIns as $checkIn) {
            $checkIn->load('shift.workplace', 'shift.workplaceShift', 'user');
            $shift = $checkIn->shift;
            $rows[] = [
                'check_in_id' => $checkIn->id,
                'workplace_name' => $shift->workplace->name,
                'date' => \Illuminate\Support\Carbon::parse($shift->date)->format('Y/n/j'),
                'shift_time' => "{$shift->workplaceShift->start_time}-{$shift->workplaceShift->end_time}",
                'staff_no' => $checkIn->user->staff_no,
                'name' => $checkIn->user->short_name,
                'name_kana' => $checkIn->user->name_kana,
                'check_in_time' => $service->toDayFraction($checkIn->check_in_time),
                'check_out_time' => $service->toDayFraction($checkIn->check_out_time),
                'check_in_time_rounded' => $service->toDayFraction($checkIn->check_in_time),
                'check_out_time_rounded' => $service->toDayFraction($checkIn->check_out_time),
                'break_time' => (string) ($checkIn->break_minutes ?? $shift->workplaceShift->break_duration),
                'work_minutes' => $checkIn->work_minutes,
                'work_minutes_normal' => $checkIn->work_minutes_normal,
                'work_minutes_night' => $checkIn->work_minutes_night,
                'work_minutes_overtime' => $checkIn->work_minutes_overtime,
                'work_minutes_night_overtime' => $checkIn->work_minutes_night_overtime,
            ];
        }

        $content = Excel::raw(
            new WorkHistoryDetailExport([$checkIns[0]->shift->workplace_id => $rows]),
            ExcelFormat::XLSX
        );
        $path = tempnam(sys_get_temp_dir(), 'preview_import_test_') . '.xlsx';
        file_put_contents($path, $content);

        try {
            $import = new WorkHistoryDetailImport();
            Excel::import($import, $path);

            return $import->rows();
        } finally {
            unlink($path);
        }
    }
}
