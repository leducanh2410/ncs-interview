<?php

namespace Tests\Feature\Admin\Attendance;

use App\Models\AdminAttendanceSession;
use App\Models\User;
use App\Models\Workplace;
use App\Services\Admin\AdminAttendanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers the interval/interruption state machine from spec Requirement 8 (design.md's
 * "Addendum (2026-07-05): Interval-Based Worked-Hours Redesign" test-case matrix, rows 1-8).
 * Exercises AdminAttendanceService::persistCheckIn/persistCheckOut directly (bypassing GPS
 * resolution, which is already covered by AdminAttendanceCheckInTest) so each sequence can be
 * driven with exact, controlled timestamps.
 */
class AdminAttendanceIntervalCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private AdminAttendanceService $service;

    private User $admin;

    private Workplace $workplaceA;

    private Workplace $workplaceB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(AdminAttendanceService::class);
        $this->admin = User::factory()->create();
        $this->workplaceA = Workplace::create([
            'name' => 'Workplace A',
            'address' => 'Address A',
            'latitude' => 35.0,
            'longitude' => 139.0,
        ]);
        $this->workplaceB = Workplace::create([
            'name' => 'Workplace B',
            'address' => 'Address B',
            'latitude' => 34.0,
            'longitude' => 135.0,
        ]);
    }

    public function testRow1SequentialVisitsSameWorkplaceSumMinutes(): void
    {
        // A CI 09:00 -> A CO 10:00 -> A CI 11:00 -> A CO 12:00 => A = 120 (60 + 60)
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkOut($this->workplaceA, $day->copy()->setTime(10, 0));
        $this->checkIn($this->workplaceA, $day->copy()->setTime(11, 0));
        $this->checkOut($this->workplaceA, $day->copy()->setTime(12, 0));

        $session = $this->sessionFor($this->workplaceA, $day);
        $this->assertSame(120, $session->work_minutes);
        $this->assertFalse($session->has_missing_checkout);
        $this->assertCount(2, $session->intervals);
        $this->assertSame([60, 60], $session->intervals->pluck('minutes')->all());
    }

    public function testRow2ForeignCompleteRoundTripInterruptsOpenWorkplace(): void
    {
        // A CI 09:00 -> B CI 10:00 -> B CO 14:00 -> A CO 18:00 => only B = 240, A's late
        // check-out is audit-only (0 credited, no warning left since it did resolve to closed).
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(10, 0));
        $this->checkOut($this->workplaceB, $day->copy()->setTime(14, 0));
        $this->checkOut($this->workplaceA, $day->copy()->setTime(18, 0));

        $sessionA = $this->sessionFor($this->workplaceA, $day);
        $sessionB = $this->sessionFor($this->workplaceB, $day);

        $this->assertNull($sessionA->work_minutes);
        $this->assertFalse($sessionA->has_missing_checkout);
        $this->assertCount(0, $sessionA->intervals);

        $this->assertSame(240, $sessionB->work_minutes);
        $this->assertFalse($sessionB->has_missing_checkout);

        $this->assertDatabaseHas('admin_attendance_events', [
            'session_id' => $sessionA->id,
            'type' => 'check_out',
        ]);
    }

    public function testRow3ReturnCheckInToOpenWorkplaceIsCheckpointNotInterruption(): void
    {
        // A CI 09:00 -> B CI 10:00 -> A CI 11:00 => A = 120 (checkpoint), no warning (A is
        // Current_Focus); B is left open and unresolved => warned.
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(10, 0));
        $this->checkIn($this->workplaceA, $day->copy()->setTime(11, 0));

        $sessionA = $this->sessionFor($this->workplaceA, $day);
        $sessionB = $this->sessionFor($this->workplaceB, $day);

        $this->assertSame(120, $sessionA->work_minutes);
        $this->assertFalse($sessionA->has_missing_checkout);

        $this->assertNull($sessionB->work_minutes);
        $this->assertTrue($sessionB->has_missing_checkout);
    }

    public function testRow4ForeignClosureInterruptsOpenWorkplaceEvenWithoutReturn(): void
    {
        // A CI 09:00 -> B CI 10:00 -> B CO 11:00 (day ends there) => A is interrupted and never
        // resolved; since the last event is a check-out, no Current_Focus exemption applies.
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(10, 0));
        $this->checkOut($this->workplaceB, $day->copy()->setTime(11, 0));

        $sessionA = $this->sessionFor($this->workplaceA, $day);
        $sessionB = $this->sessionFor($this->workplaceB, $day);

        $this->assertNull($sessionA->work_minutes);
        $this->assertTrue($sessionA->has_missing_checkout);

        $this->assertSame(60, $sessionB->work_minutes);
        $this->assertFalse($sessionB->has_missing_checkout);
    }

    public function testRow5CheckOutOnFirstCheckedInWorkplaceAlsoInterruptsSecondOpenWorkplace(): void
    {
        // A CI 09:00 -> B CI 10:00 -> A CO 11:00 (day ends there) — mirror of row 4 with the
        // check-out on the FIRST-checked-in workplace instead of the second: A closes normally
        // and credits its span, while B (checked in later, never checked out) is left open and
        // interrupted; since the last event is a check-out, no Current_Focus exemption applies.
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(10, 0));
        $this->checkOut($this->workplaceA, $day->copy()->setTime(11, 0));

        $sessionA = $this->sessionFor($this->workplaceA, $day);
        $sessionB = $this->sessionFor($this->workplaceB, $day);

        $this->assertSame(120, $sessionA->work_minutes);
        $this->assertFalse($sessionA->has_missing_checkout);

        $this->assertNull($sessionB->work_minutes);
        $this->assertTrue($sessionB->has_missing_checkout);
    }

    public function testRow6ReturningAfterInterruptionDiscardsStaleIntervalAndStartsFresh(): void
    {
        // A CI 09:00 -> B CI 10:00 -> B CO 11:00 -> A CI 13:00 => A's stale 09:00 interval was
        // interrupted and is discarded (0 credit); the return check-in opens a clean interval
        // from 13:00 and A becomes Current_Focus, so it is not warned despite still being open.
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(10, 0));
        $this->checkOut($this->workplaceB, $day->copy()->setTime(11, 0));
        $this->checkIn($this->workplaceA, $day->copy()->setTime(13, 0));

        $sessionA = $this->sessionFor($this->workplaceA, $day);
        $sessionB = $this->sessionFor($this->workplaceB, $day);

        $this->assertNull($sessionA->work_minutes);
        $this->assertFalse($sessionA->has_missing_checkout);
        $this->assertCount(0, $sessionA->intervals);

        $this->assertSame(60, $sessionB->work_minutes);
        $this->assertFalse($sessionB->has_missing_checkout);
    }

    public function testRow7CheckpointCommitDoesNotInterruptOtherOpenWorkplace(): void
    {
        // A CI 09:00 -> B CI 10:00 -> A CI 11:00 -> B CI 12:00 => A's checkpoint at 11:00 does
        // NOT interrupt B, so B's own return check-in at 12:00 credits its full 10:00->12:00
        // span (not discarded). Only A (not Current_Focus at the end) is left warned.
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(10, 0));
        $this->checkIn($this->workplaceA, $day->copy()->setTime(11, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(12, 0));

        $sessionA = $this->sessionFor($this->workplaceA, $day);
        $sessionB = $this->sessionFor($this->workplaceB, $day);

        $this->assertSame(120, $sessionA->work_minutes);
        $this->assertTrue($sessionA->has_missing_checkout);

        $this->assertSame(120, $sessionB->work_minutes);
        $this->assertFalse($sessionB->has_missing_checkout);
    }

    public function testRow8CurrentFocusExemptionDoesNotApplyOnceTheDayIsPast(): void
    {
        // Same sequence as row 3 (A CI 09:00 -> B CI 10:00 -> A CI 11:00), all while Work_Date is
        // still "today" => A is exempted as Current_Focus, same as row 3 asserts.
        $day = Carbon::parse('2026-07-05');
        $this->checkIn($this->workplaceA, $day->copy()->setTime(9, 0));
        $this->checkIn($this->workplaceB, $day->copy()->setTime(10, 0));
        $this->checkIn($this->workplaceA, $day->copy()->setTime(11, 0));

        $sessionA = $this->sessionFor($this->workplaceA, $day);
        $this->assertFalse($sessionA->has_missing_checkout);

        // Reviewed on a later day (e.g. a manual recompute/backfill re-running for this day):
        // the Current_Focus exemption no longer applies once Work_Date is in the past, so A's
        // still-open interval (from 11:00) is now flagged too, even though credited hours (120)
        // are unchanged.
        $this->service->recomputeDay($this->admin->id, $day->format('Y-m-d'), $day->copy()->addDay()->setTime(9, 0));

        $sessionA->refresh();
        $sessionB = $this->sessionFor($this->workplaceB, $day);

        $this->assertSame(120, $sessionA->work_minutes);
        $this->assertTrue($sessionA->has_missing_checkout);
        $this->assertTrue($sessionB->has_missing_checkout);
    }

    private function checkIn(Workplace $workplace, Carbon $at): AdminAttendanceSession
    {
        return $this->service->persistCheckIn($this->admin->id, $workplace->id, '35.0,139.0', $at)['session'];
    }

    private function checkOut(Workplace $workplace, Carbon $at): AdminAttendanceSession
    {
        $session = $this->sessionFor($workplace, $at);

        return $this->service->persistCheckOut($session, '35.0,139.0', $at);
    }

    private function sessionFor(Workplace $workplace, Carbon $day): AdminAttendanceSession
    {
        return AdminAttendanceSession::query()
            ->where('user_id', $this->admin->id)
            ->where('workplace_id', $workplace->id)
            ->where('work_date', $day->format('Y-m-d'))
            ->firstOrFail();
    }
}
