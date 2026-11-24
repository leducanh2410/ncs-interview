<?php

namespace App\Actions\Manage\WorkHistoryEditRequest;

use Illuminate\Support\Carbon;

/**
 * Resolves the absolute calendar date for a changed check-in/check-out time-of-day value read
 * from a bulk-import file. Columns G/H ("Giờ vào làm"/"Giờ tan làm") in
 * App\Exports\WorkHistoryDetailExport only ever carry a fraction of a day (see
 * WorkHistoryService::toDayFraction) - no date.
 *
 * check_ins.check_in_time is NOT anchored to the shift's date - a check-in can fall the evening
 * before a shift that starts just after midnight, or a few minutes after midnight on an overnight
 * shift. A new check-in time-of-day is therefore first resolved against the *live* value's own
 * calendar date, and only rolled to an adjacent day when doing so resolves what is clearly a
 * small midnight-wrap edit. When that live-day candidate sits far outside the scheduled shift
 * window (a late clock-in after midnight being corrected back to the previous evening's start),
 * the shift's own start is used as a second reference so the value lands on the shift day
 * rather than a day later, which Excel cannot otherwise express.
 *
 * A new check-out time-of-day can only land on the resolved check-in's date or the next
 * calendar day — the two dates a time-of-day fraction can fall on while still keeping
 * the pair in order. When the same-day candidate is already in order, nextDay is always
 * >= 24h after check-in (exactly 24h only when sameDay equals check-in) and is therefore
 * rejected by PreviewBulkImportWorkHistoryEditRequestsAction's MAX_PLAUSIBLE_SHIFT_HOURS
 * guard. nextDay is only preferred when the live check-out already sits more than 24h
 * after the resolved check-in (equal to the live check-in unless column G also changed)
 * — the one case where keeping sameDay would silently drop the check-out onto the
 * check-in's date even though the live value lives on the next calendar day. When the
 * new time-of-day is earlier than the resolved check-in, the in-order floor is nextDay;
 * if that same >24h condition holds, nextDay + 1 day is compared too so a pair that
 * already spans two midnights is not dropped onto the morning after check-in. For every
 * other shift (including 12–24h overnight shifts) the in-order same-day candidate is kept.
 *
 * When there is no live check-out yet, the in-order same-day candidate is used, except
 * at the exact 24h-shift boundary (new time-of-day equals check-in on a shift whose
 * scheduled window is 24h): sameDay would be a 0-hour pair, so nextDay is returned.
 */
class ResolveImportedAttendanceDatesAction
{
    /**
     * A day-rollover is only applied when it resolves what is clearly a small edit that
     * happens to cross midnight (e.g. 23:50 -> 00:05, or a check-in recorded a few minutes
     * after midnight being corrected back to just before it) - never a large intraday edit
     * that merely happens to land more than 12 hours from the live value on its own day
     * (e.g. correcting a check-in mistakenly punched at 09:00 to the intended 22:00), which
     * would otherwise "win" the naive nearest-candidate comparison and silently shift the
     * date by a full day.
     */
    private const WRAP_THRESHOLD_MINUTES = 6 * 60;

    /**
     * How far outside the scheduled shift a same-day-as-live candidate may sit before
     * reanchorToShiftIfSameDayIsFarFromWindow() considers a neighbouring day. Independent
     * of WRAP_THRESHOLD_MINUTES: that constant is a bound on "this looks like a midnight
     * wrap around the live value", this one is a bound on "this still looks like it belongs
     * to the scheduled shift".
     */
    private const SHIFT_WINDOW_PADDING_MINUTES = 6 * 60;

    /**
     * Same bound PreviewBulkImportWorkHistoryEditRequestsAction uses. When sameDay is
     * already in order, nextDay always produces a pair of at least this length, so it
     * is only considered when the live pair itself already exceeds it.
     */
    private const MAX_PLAUSIBLE_SHIFT_HOURS = 24;

    /**
     * @return array{check_in: Carbon, check_out: ?Carbon}
     */
    public function __invoke(
        string $liveCheckIn,
        float $checkInFraction,
        ?string $liveCheckOut,
        ?float $checkOutFraction,
        ?string $shiftStart = null,
        ?string $shiftEnd = null
    ): array {
        $checkIn = $this->anchorNearest($liveCheckIn, $checkInFraction);

        if ($shiftStart !== null) {
            $checkIn = $this->reanchorToShiftIfSameDayIsFarFromWindow(
                $liveCheckIn,
                $checkInFraction,
                $checkIn,
                $shiftStart,
                $shiftEnd
            );
        }

        $checkOut = null;
        if ($checkOutFraction !== null) {
            $checkOut = $this->resolveCheckOut($checkIn, $checkOutFraction, $liveCheckOut, $shiftStart, $shiftEnd);
        }

        return ['check_in' => $checkIn, 'check_out' => $checkOut];
    }

    /**
     * When the same-calendar-day-as-live candidate sits far outside the scheduled shift window,
     * pick whichever of the shift day and its two neighbours puts the new time-of-day closest
     * to the scheduled start. Leaves an unchanged time-of-day (and a same-day candidate that
     * is still near the shift) on the live-based result from anchorNearest().
     */
    private function reanchorToShiftIfSameDayIsFarFromWindow(
        string $liveCheckIn,
        float $fraction,
        Carbon $resolved,
        string $shiftStart,
        ?string $shiftEnd
    ): Carbon {
        $live = Carbon::parse($liveCheckIn);
        $liveMinutes = $live->hour * 60 + $live->minute;
        $fractionMinutes = (int) round($fraction * 1440);
        if ($liveMinutes === $fractionMinutes) {
            return $resolved;
        }

        $start = Carbon::parse($shiftStart);
        $end = $shiftEnd !== null ? Carbon::parse($shiftEnd) : $start->copy();
        $sameDay = $this->anchor($live->toDateString(), $fraction);
        if (!$this->isOutsidePaddedShiftWindow($sameDay, $start, $end)) {
            return $resolved;
        }

        $best = $resolved;
        $bestDiff = abs($resolved->diffInSeconds($start));
        $resolvedLiveDiff = abs($resolved->diffInSeconds($live));
        foreach ([-1, 0, 1] as $dayOffset) {
            $candidate = $this->anchor($start->copy()->addDays($dayOffset)->toDateString(), $fraction);
            $diff = abs($candidate->diffInSeconds($start));
            // Do not pick a neighbour that is farther from the live value than the already-
            // resolved (same-day) candidate. Without this, a 1-hour edit whose live day sits
            // just outside the padded window (e.g. live 02:00 the morning after a 09:00-18:00
            // shift, edited to 03:00) would be pulled onto the shift day purely because that
            // lands closer to $start, silently shifting the date by a full day.
            $liveDiff = abs($candidate->diffInSeconds($live));
            if ($diff < $bestDiff && $liveDiff <= $resolvedLiveDiff) {
                $bestDiff = $diff;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function isOutsidePaddedShiftWindow(Carbon $candidate, Carbon $start, Carbon $end): bool
    {
        $paddedStart = $start->copy()->subMinutes(self::SHIFT_WINDOW_PADDING_MINUTES);
        $paddedEnd = $end->copy()->addMinutes(self::SHIFT_WINDOW_PADDING_MINUTES);

        return $candidate->lt($paddedStart) || $candidate->gt($paddedEnd);
    }

    /**
     * Picks the resolved check-in's date, or the next calendar day when that is the only
     * way to keep the pair in order. nextDay is also considered when the live check-out
     * already sits more than MAX_PLAUSIBLE_SHIFT_HOURS after the resolved check-in (equal
     * to the live check-in unless column G also changed): in that case sameDay would
     * silently drop the check-out onto the check-in's date (the new time-of-day is later
     * than the check-in, so the inverted-order roll-forward never fires), even though the
     * live value lives on the next calendar day. When the new time-of-day is earlier than
     * check-in, the in-order floor is nextDay; if that same >24h condition holds, nextDay
     * + 1 day is compared too so the check-out is not dropped a full day earlier than the
     * live value. For every other live pair — including 12–24h overnight shifts — nextDay
     * would produce a pair >= 24h that preview then rejects as IMPLAUSIBLE_DURATION, so
     * sameDay is kept.
     *
     * With no live check-out, the in-order same-day candidate is used, except when the
     * new time-of-day equals check-in on a 24h shift: sameDay would be 0 hours, so
     * nextDay (exactly 24h) is returned instead.
     */
    private function resolveCheckOut(
        Carbon $checkIn,
        float $checkOutFraction,
        ?string $liveCheckOut,
        ?string $shiftStart,
        ?string $shiftEnd
    ): Carbon {
        $sameDay = $this->anchor($checkIn->toDateString(), $checkOutFraction);
        $nextDay = $sameDay->copy()->addDay();

        if ($sameDay->lt($checkIn)) {
            if ($liveCheckOut === null) {
                return $nextDay;
            }

            $live = Carbon::parse($liveCheckOut);
            $dayAfterNext = $nextDay->copy()->addDay();
            if (
                abs($live->diffInHours($checkIn)) > self::MAX_PLAUSIBLE_SHIFT_HOURS &&
                abs($dayAfterNext->diffInSeconds($live)) < abs($nextDay->diffInSeconds($live))
            ) {
                return $dayAfterNext;
            }

            return $nextDay;
        }

        if ($liveCheckOut === null) {
            if ($sameDay->eq($checkIn) && $this->isTwentyFourHourShift($shiftStart, $shiftEnd)) {
                return $nextDay;
            }

            return $sameDay;
        }

        $live = Carbon::parse($liveCheckOut);
        if ($live->lt($checkIn) || abs($live->diffInHours($checkIn)) <= self::MAX_PLAUSIBLE_SHIFT_HOURS) {
            return $sameDay;
        }

        return abs($nextDay->diffInSeconds($live)) < abs($sameDay->diffInSeconds($live)) ? $nextDay : $sameDay;
    }

    private function isTwentyFourHourShift(?string $shiftStart, ?string $shiftEnd): bool
    {
        if ($shiftStart === null || $shiftEnd === null) {
            return false;
        }

        return abs(Carbon::parse($shiftEnd)->diffInHours(Carbon::parse($shiftStart))) >=
            self::MAX_PLAUSIBLE_SHIFT_HOURS;
    }

    /**
     * Applies $fraction as a time-of-day on whichever of the live day and its two neighbors ends
     * up closest to $liveValue, but only ever prefers a neighboring day when that candidate is
     * both closer than the live day's own candidate *and* still close in absolute terms (within
     * WRAP_THRESHOLD_MINUTES) - i.e. an actual midnight wrap, not a large same-day edit that
     * happens to exceed the 12-hour halfway point between two calendar days.
     */
    private function anchorNearest(string $liveValue, float $fraction): Carbon
    {
        $live = Carbon::parse($liveValue);
        $sameDay = $this->anchor($live->toDateString(), $fraction);

        $best = $sameDay;
        $bestDiff = abs($sameDay->diffInSeconds($live));

        foreach ([-1, 1] as $dayOffset) {
            $candidate = $this->anchor($live->copy()->addDays($dayOffset)->toDateString(), $fraction);
            // Carbon 3 defaults diffInSeconds() to a *signed* result, so this must be wrapped in
            // abs() explicitly - otherwise the day-before candidate (always "earlier", hence
            // negative) would look smaller than a same-day diff and win every time.
            $diff = abs($candidate->diffInSeconds($live));
            if ($diff < $bestDiff && $diff <= self::WRAP_THRESHOLD_MINUTES * 60) {
                $bestDiff = $diff;
                $best = $candidate;
            }
        }

        return $best;
    }

    private function anchor(string $date, float $fraction): Carbon
    {
        return Carbon::parse($date)->startOfDay()->addMinutes((int) round($fraction * 1440));
    }
}
