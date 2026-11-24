<?php

namespace Tests\Unit\Actions\Manage\WorkHistoryEditRequest;

use App\Actions\Manage\WorkHistoryEditRequest\ResolveImportedAttendanceDatesAction;
use Tests\TestCase;

class ResolveImportedAttendanceDatesActionTest extends TestCase
{
    private ResolveImportedAttendanceDatesAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        $this->action = new ResolveImportedAttendanceDatesAction();
    }

    public function testAnchorsCheckInToTheLiveCheckInsOwnDate(): void
    {
        $result = ($this->action)('2026-08-20 09:00:00', (9 * 60) / 1440, null, null);

        $this->assertSame('2026-08-20 09:00:00', $result['check_in']->toDateTimeString());
        $this->assertNull($result['check_out']);
    }

    public function testKeepsCheckOutOnTheSameDayForANormalShift(): void
    {
        $result = ($this->action)('2026-08-20 09:00:00', (9 * 60) / 1440, '2026-08-20 18:00:00', (18 * 60) / 1440);

        $this->assertSame('2026-08-20 09:00:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-08-20 18:00:00', $result['check_out']->toDateTimeString());
    }

    public function testRollsCheckOutToTheNextDayForAnOvernightShiftWhenNoLiveCheckOutExists(): void
    {
        // check-in 22:00, check-out being set for the first time at 01:00 the following morning.
        $result = ($this->action)('2026-08-20 22:00:00', (22 * 60) / 1440, null, (1 * 60) / 1440);

        $this->assertSame('2026-08-20 22:00:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-08-21 01:00:00', $result['check_out']->toDateTimeString());
    }

    public function testKeepsTheSameDayWhenNewFractionExactlyEqualsTheLiveFraction(): void
    {
        // Boundary case: an unchanged fraction must never be treated as a date rollover.
        $result = ($this->action)('2026-08-20 09:00:00', (9 * 60) / 1440, '2026-08-20 09:00:00', (9 * 60) / 1440);

        $this->assertSame('2026-08-20 09:00:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-08-20 09:00:00', $result['check_out']->toDateTimeString());
    }

    public function testResolvesOnlyCheckInWhenCheckOutFractionIsNull(): void
    {
        $result = ($this->action)('2026-08-20 22:00:00', (22 * 60) / 1440, '2026-08-21 01:00:00', null);

        $this->assertSame('2026-08-20 22:00:00', $result['check_in']->toDateTimeString());
        $this->assertNull($result['check_out']);
    }

    public function testKeepsCheckOutOnItsLiveDateWhenOnlyItsMinutesAreEdited(): void
    {
        // Bug scenario 1: shift 00:30-08:30 on 2026-05-10, live check-in 2026-05-09 23:30 (before
        // the shift's own date), live check-out 2026-05-10 08:30. Editing only the check-out's
        // minutes (08:30 -> 08:45) must land on 2026-05-10: the new time-of-day is anchored to
        // the resolved check-in's date and rolled forward one day because 08:45 is before 23:30.
        $result = ($this->action)(
            '2026-05-09 23:30:00',
            (23 * 60 + 30) / 1440,
            '2026-05-10 08:30:00',
            (8 * 60 + 45) / 1440
        );

        $this->assertSame('2026-05-10 08:45:00', $result['check_out']->toDateTimeString());
    }

    public function testKeepsCheckInOnItsLiveDateWhenItFallsAfterMidnightRelativeToTheShift(): void
    {
        // Bug scenario 2: an overnight shift dated 2026-08-01, live check-in recorded by the
        // real clock-in time at 2026-08-02 00:05 (after midnight). Editing only the minutes
        // (00:05 -> 00:15) must stay on 2026-08-02, not be anchored back to the shift's own date.
        $result = ($this->action)('2026-08-02 00:05:00', (0 * 60 + 15) / 1440, null, null);

        $this->assertSame('2026-08-02 00:15:00', $result['check_in']->toDateTimeString());
    }

    public function testRollsCheckInToThePreviousDayWhenTheEditedTimeIsFarFromTheLiveValue(): void
    {
        // A small edit that happens to wrap past midnight (00:05 -> 23:30 the day before, a mere
        // 35-minute difference) is still resolved to whichever calendar day keeps the absolute
        // time closest to the live value.
        $result = ($this->action)('2026-05-10 00:05:00', (23 * 60 + 30) / 1440, null, null);

        $this->assertSame('2026-05-09 23:30:00', $result['check_in']->toDateTimeString());
    }

    public function testKeepsTheLiveDayForALargeIntradayEditThatIsNotAMidnightWrap(): void
    {
        // Regression: a night-shift check-in wrongly clocked in the morning (09:00 instead of the
        // intended 22:00) corrected via the G column must land on the *same* calendar day as the
        // live value, not the day before. The naive "nearest of the 3 candidates" comparison used
        // to prefer 2026-07-31 22:00 (11h away) over 2026-08-01 22:00 (13h away) purely because
        // 11 < 13 - even though this is a large same-day correction, not a midnight wrap.
        $result = ($this->action)('2026-08-01 09:00:00', (22 * 60) / 1440, null, null);

        $this->assertSame('2026-08-01 22:00:00', $result['check_in']->toDateTimeString());
    }

    public function testKeepsTheLiveDayForAnotherLargeIntradayEditThatIsNotAMidnightWrap(): void
    {
        $result = ($this->action)('2026-08-01 08:00:00', (21 * 60) / 1440, null, null);

        $this->assertSame('2026-08-01 21:00:00', $result['check_in']->toDateTimeString());
    }

    public function testRollsCheckOutForwardWhenAnchoringItIndependentlyWouldPlaceItBeforeCheckIn(): void
    {
        // Regression: a normal day shift (live 09:00-18:00) is corrected to record an overnight
        // stay by editing only the H column (18:00 -> 06:00). Anchoring to the check-in's date
        // lands 06:00 before 09:00, so the invariant rolls it to the next day.
        $result = ($this->action)('2026-05-10 09:00:00', (9 * 60) / 1440, '2026-05-10 18:00:00', (6 * 60) / 1440);

        $this->assertSame('2026-05-11 06:00:00', $result['check_out']->toDateTimeString());
    }

    public function testAnchorsAnEarlyNightShiftCheckOutToTheCheckInDateInsteadOfTheLiveCheckOutDate(): void
    {
        // AutoCheckOut on a night shift (20:00-05:00(+1)) stamps check-out at 05:00 the next
        // morning. Correcting H 05:00 -> 22:00 is 17h from that live value, so nearest-to-live
        // would keep 2026-05-11 22:00 (~26h). Anchoring to the resolved check-in date gives
        // 2026-05-10 22:00, the actual same-evening departure.
        $result = ($this->action)(
            '2026-05-10 19:55:00',
            (19 * 60 + 55) / 1440,
            '2026-05-11 05:00:00',
            (22 * 60) / 1440
        );

        $this->assertSame('2026-05-10 19:55:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-05-10 22:00:00', $result['check_out']->toDateTimeString());
    }

    public function testStillRollsANightShiftCheckOutForwardWhenTheNewTimeOfDayIsBeforeCheckIn(): void
    {
        $result = ($this->action)('2026-05-10 19:55:00', (19 * 60 + 55) / 1440, '2026-05-11 05:00:00', (4 * 60) / 1440);

        $this->assertSame('2026-05-11 04:00:00', $result['check_out']->toDateTimeString());
    }

    public function testReanchorsCheckInToTheShiftDayWhenTheLiveDayCandidateIsFarFromTheShift(): void
    {
        // Overnight shift 18:00-02:00(+1) dated 2026-08-01; the employee clocked in late at
        // 2026-08-02 00:05. Correcting G 00:05 -> 18:00 is 17h55 from the live value (and 6h05
        // from the previous evening), so nearest-to-live keeps 2026-08-02 18:00 - after the
        // live check-out, with no G/H pair that can express 2026-08-01 18:00. The shift window
        // is the second reference that recovers the scheduled start.
        $result = ($this->action)(
            '2026-08-02 00:05:00',
            (18 * 60) / 1440,
            '2026-08-02 07:00:00',
            null,
            '2026-08-01 18:00:00',
            '2026-08-02 02:00:00'
        );

        $this->assertSame('2026-08-01 18:00:00', $result['check_in']->toDateTimeString());
    }

    public function testKeepsTheLiveDayForALargeIntradayEditEvenWhenShiftTimesAreProvided(): void
    {
        // Same 09:00 -> 22:00 correction as testKeepsTheLiveDayForALargeIntradayEditThatIsNotAMidnightWrap,
        // but with the day-shift window passed in. 22:00 is only 4h past 18:00, inside the padded
        // window, so the shift-day fallback must not pull it to the previous evening.
        $result = ($this->action)(
            '2026-08-01 09:00:00',
            (22 * 60) / 1440,
            null,
            null,
            '2026-08-01 09:00:00',
            '2026-08-01 18:00:00'
        );

        $this->assertSame('2026-08-01 22:00:00', $result['check_in']->toDateTimeString());
    }

    public function testDoesNotRollCheckOutForwardWhenSettingItForTheFirstTimeAlreadyKeepsOrder(): void
    {
        // The no-live-check-out branch's own date (the newly-resolved check-in's date) combined
        // with the invariant must not double-roll a check-out that is already in order.
        $result = ($this->action)('2026-08-20 09:00:00', (9 * 60) / 1440, null, (18 * 60) / 1440);

        $this->assertSame('2026-08-20 18:00:00', $result['check_out']->toDateTimeString());
    }

    public function testWrapsWhenTheDifferenceIsExactlyAtTheWrapThreshold(): void
    {
        // Documents the exact boundary: WRAP_THRESHOLD_MINUTES is inclusive, so a candidate
        // exactly 6 hours from the live value still wraps. Check-out no longer uses this
        // heuristic (it keeps the resolved check-in date unless that inverts the pair, or
        // the live pair already exceeds 24h); check-in still does, with the shift window
        // as a second reference when the live-day candidate is far away.
        $result = ($this->action)('2026-05-10 05:00:00', (23 * 60) / 1440, null, null);

        $this->assertSame('2026-05-09 23:00:00', $result['check_in']->toDateTimeString());
    }

    public function testDoesNotWrapWhenTheDifferenceIsJustOverTheWrapThreshold(): void
    {
        $result = ($this->action)('2026-05-10 05:00:00', (22 * 60 + 59) / 1440, null, null);

        $this->assertSame('2026-05-10 22:59:00', $result['check_in']->toDateTimeString());
    }

    public function testKeepsCheckOutOnItsLiveDateWhenCheckInIsOnThePreviousCalendarDayAndTheNewTimeOfDayIsLaterThanCheckIn(): void
    {
        // Live pair already spans a calendar day (an approved single edit-request set
        // check_in to 2026-05-09 08:00, AutoCheckOut left check-out at 2026-05-10 18:00).
        // Editing only H 18:00 -> 17:30 must stay on 2026-05-10: anchoring to the check-in
        // date would produce 2026-05-09 17:30 because 17:30 is later than 08:00, so the
        // "roll forward if inverted" invariant never fires.
        $result = ($this->action)('2026-05-09 08:00:00', (8 * 60) / 1440, '2026-05-10 18:00:00', (17 * 60 + 30) / 1440);

        $this->assertSame('2026-05-09 08:00:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-05-10 17:30:00', $result['check_out']->toDateTimeString());
    }

    public function testKeepsCheckOutOnTheCheckInDateWhenALongOvernightShiftIsSentHomeEarly(): void
    {
        // 13h overnight shift 18:00-07:00(+1). Clocked in 55 min early (within
        // check_in_before_hour), AutoCheckOut at 07:00. Editing H 07:00 -> 18:00 is
        // "sent home early the same evening". Nearest-to-live used to prefer
        // 2026-05-11 18:00 (11h from live 07:00 vs 13h for 2026-05-10 18:00) because
        // the live pair is 13.9h > 12h; that pair is 24.9h and preview then rejects
        // the whole file as IMPLAUSIBLE_DURATION. nextDay is only considered when
        // the live pair already exceeds 24h, so sameDay is kept.
        $result = ($this->action)('2026-05-10 17:05:00', (17 * 60 + 5) / 1440, '2026-05-11 07:00:00', (18 * 60) / 1440);

        $this->assertSame('2026-05-10 17:05:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-05-10 18:00:00', $result['check_out']->toDateTimeString());
    }

    public function testKeepsCheckOutOnTheCheckInDateForASmallEditOnALongOvernightShift(): void
    {
        // Same 13.9h live pair as above; a small H edit (07:00 -> 17:15) is still
        // closer to nextDay's 2026-05-11 17:15 than to sameDay, so nearest-to-live
        // used to roll it forward into an IMPLAUSIBLE_DURATION pair too.
        $result = ($this->action)(
            '2026-05-10 17:05:00',
            (17 * 60 + 5) / 1440,
            '2026-05-11 07:00:00',
            (17 * 60 + 15) / 1440
        );

        $this->assertSame('2026-05-10 17:15:00', $result['check_out']->toDateTimeString());
    }

    public function testKeepsCheckOutOnItsLiveDateWhenCheckInIsOnThePreviousCalendarDayAndTheNewTimeOfDayIsEarlierThanCheckIn(): void
    {
        // Symmetric half of testKeepsCheckOutOnItsLiveDateWhenCheckInIsOnThePreviousCalendarDayAndTheNewTimeOfDayIsLaterThanCheckIn:
        // live pair already spans two midnights (approved single edit-request set check-in to
        // 2026-05-09 22:00, check-out 2026-05-11 06:00). Editing only H 06:00 -> 05:00 used
        // to early-return nextDay (2026-05-10 05:00) because 05:00 is before 22:00, dropping
        // the check-out a full day and producing a 7h pair that sailed through both guards.
        $result = ($this->action)('2026-05-09 22:00:00', (22 * 60) / 1440, '2026-05-11 06:00:00', (5 * 60) / 1440);

        $this->assertSame('2026-05-09 22:00:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-05-11 05:00:00', $result['check_out']->toDateTimeString());
    }

    public function testRollsAFirstCheckOutToTheNextDayOnATwentyFourHourShift(): void
    {
        // start_time == end_time is a 24h shift (WorkHelper::getShiftTimes rolls end +1 day).
        // First-time H = 09:00 matching check-in 09:00 would be a 0-hour pair on sameDay;
        // the scheduled end is 24h later, so nextDay is the 24h checkout.
        $result = ($this->action)(
            '2026-05-10 09:00:00',
            (9 * 60) / 1440,
            null,
            (9 * 60) / 1440,
            '2026-05-10 09:00:00',
            '2026-05-11 09:00:00'
        );

        $this->assertSame('2026-05-10 09:00:00', $result['check_in']->toDateTimeString());
        $this->assertSame('2026-05-11 09:00:00', $result['check_out']->toDateTimeString());
    }

    public function testDoesNotPickNextDayForAFirstCheckoutOnALongOvernightShiftJustBecauseShiftEndIsCloser(): void
    {
        // 13h overnight 18:00-07:00(+1). First-time H = 18:00 (no live checkout) must stay
        // 2026-05-10 18:00. Restoring nearest-to-shiftEnd would prefer 2026-05-11 18:00
        // (11h from 07:00 vs 13h) and produce an IMPLAUSIBLE_DURATION pair.
        $result = ($this->action)(
            '2026-05-10 17:05:00',
            (17 * 60 + 5) / 1440,
            null,
            (18 * 60) / 1440,
            '2026-05-10 18:00:00',
            '2026-05-11 07:00:00'
        );

        $this->assertSame('2026-05-10 18:00:00', $result['check_out']->toDateTimeString());
    }

    public function testDoesNotReanchorASmallSameDayEditJustBecauseItSitsOutsideThePaddedShiftWindow(): void
    {
        // Day shift 09:00-18:00 on 2026-05-10; live check-in is 2026-05-11 02:00 (placed
        // there by a previously-approved single edit-request). Editing G 02:00 -> 03:00 is
        // a 1-hour same-day correction. 03:00 sits after paddedEnd (18:00 + 6h = 00:00 the
        // next calendar morning), so the shift-window fallback used to pick 2026-05-10 03:00
        // purely because that is closer to the scheduled start. It must keep 2026-05-11 03:00.
        $result = ($this->action)(
            '2026-05-11 02:00:00',
            (3 * 60) / 1440,
            '2026-05-10 18:00:00',
            null,
            '2026-05-10 09:00:00',
            '2026-05-10 18:00:00'
        );

        $this->assertSame('2026-05-11 03:00:00', $result['check_in']->toDateTimeString());
    }
}
