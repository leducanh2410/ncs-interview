<?php

namespace App\Services\Admin;

use App\Enums\AdminAttendanceEventType;
use App\Enums\ErrorCode;
use App\Enums\PermissionName;
use App\Helpers\WorkHelper;
use App\Models\AdminAttendanceEvent;
use App\Models\AdminAttendanceSession;
use App\Models\User;
use App\Models\Workplace;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

class AdminAttendanceService
{
    /**
     * Reason recorded on `invalidated_reason` when a Stale_Session is auto-resolved because its
     * Valid_Checkout_Window elapsed before a checkout (or a fresh check-in) arrived.
     */
    public const INVALIDATED_REASON_LATE_CHECKOUT = 'late_checkout';

    /**
     * Resolve whether a check-in/out submission is a check-in or a check-out, and the
     * workplace/session it applies to — the backend decides so the mobile client's single
     * button never has to state which action it means.
     *
     * Among the admin's assigned workplaces within range, prefer the nearest one that already
     * has an open Current_Session: the submission is a check-out closing it, exactly as
     * `findOpenSession()` (used by the read-only `current-session` endpoint) would report. This
     * avoids closing (or missing) the wrong session when two assigned workplaces are both within
     * range of the admin's current position.
     *
     * If none of the nearby workplaces has an open session, the submission is a check-in.
     * `persistCheckIn()` already detects and invalidates a Stale_Session at whichever workplace
     * it's called for (Requirement 3), so this method picks the nearest workplace that actually
     * has one, using the same candidate order as the open-session search above — falling back to
     * the plain nearest workplace only when none of the candidates has a Stale_Session either.
     * Otherwise a Stale_Session at a farther-but-in-range workplace would never be found (the
     * check-in would land on the nearer workplace instead) and would dangle indefinitely.
     *
     * @return array{error?: ErrorCode, action?: 'check_in'|'check_out', workplace?: Workplace, session?: AdminAttendanceSession}
     */
    public function resolveCheckInOrOutTarget(User $admin, float $latitude, float $longitude): array
    {
        $result = $this->assignedWorkplacesWithinRange($admin, $latitude, $longitude);
        if (isset($result['error'])) {
            return $result;
        }

        foreach ($result['workplaces'] as $candidate) {
            $session = $this->findOpenSession($admin->id, $candidate['workplace']->id);
            if ($session) {
                return ['action' => 'check_out', 'workplace' => $candidate['workplace'], 'session' => $session];
            }
        }

        // Read-only lookup made outside the caller's DB transaction, so it must not lock rows.
        foreach ($result['workplaces'] as $candidate) {
            $staleSession = $this->findStaleOpenSession($admin->id, $candidate['workplace']->id, null, lock: false);
            if ($staleSession) {
                return ['action' => 'check_in', 'workplace' => $candidate['workplace']];
            }
        }

        return ['action' => 'check_in', 'workplace' => $result['workplaces'][0]['workplace']];
    }

    /**
     * Find the most recent still-open session (last event is a check-in) for (admin, workplace)
     * whose Valid_Checkout_Window has not yet elapsed — i.e. its Current_Session (Requirement 1).
     * Looks back a bounded window rather than strictly "today" so a session opened just before
     * midnight can still be closed after midnight (e.g. a night-shift spot check), but a session
     * whose window has elapsed is no longer "open" from this method's perspective — see
     * `findStaleOpenSession()` for that case.
     */
    public function findOpenSession(int $adminId, int $workplaceId, ?Carbon $now = null): ?AdminAttendanceSession
    {
        $now = $now ?? now();
        $sessions = $this->recentSessions($adminId, $workplaceId, $now);

        foreach ($sessions as $session) {
            $openCheckInAt = $session->openCheckInAt();
            if ($openCheckInAt && $this->isWithinValidCheckoutWindow($openCheckInAt, $now)) {
                return $session;
            }
        }

        return null;
    }

    /**
     * Find an open session (last event is a check-in) for (admin, workplace) whose
     * Valid_Checkout_Window HAS elapsed and which has not yet been invalidated — a Stale_Session
     * (Requirement 3). Excludes already-invalidated sessions so a resolved one is not re-detected
     * and re-warned on every subsequent request (invalidation deliberately never writes
     * `last_check_out_at`, so without this filter it would still look open forever).
     *
     * Deliberately not bounded by a "recent" time window like `findOpenSession()`: a Stale_Session
     * must stay findable no matter how long it has been dangling (e.g. a check-in right before an
     * admin's day off, discovered days later), so this queries by `invalidated_reason` alone. Only
     * the single most recent not-yet-invalidated session per (admin, workplace) is checked —
     * `persistCheckIn()` always invalidates a prior open session before creating the next one, so
     * an older not-yet-invalidated row is guaranteed already closed and cannot itself be the
     * Stale_Session.
     *
     * @param bool $lock true for the mutating check-in/check-out paths (locks the row so
     *   concurrent requests serialize); false for the read-only current-session endpoint or a
     *   lookup made outside a transaction.
     */
    public function findStaleOpenSession(
        int $adminId,
        int $workplaceId,
        ?Carbon $now = null,
        bool $lock = true
    ): ?AdminAttendanceSession {
        $now = $now ?? now();

        $query = AdminAttendanceSession::query()
            ->where('user_id', $adminId)
            ->where('workplace_id', $workplaceId)
            ->whereNull('invalidated_reason')
            ->orderByDesc('first_check_in_at');

        if ($lock) {
            $query->lockForUpdate();
        }

        $session = $query->first();
        $openCheckInAt = $session?->openCheckInAt();

        if ($openCheckInAt && !$this->isWithinValidCheckoutWindow($openCheckInAt, $now)) {
            return $session;
        }

        return null;
    }

    /**
     * Mark a Stale_Session invalidated: no longer eligible to be closed or credited. Does not
     * touch `work_minutes`, `last_check_out_at`, or the event log — the session's own Work_Date
     * was already processed by `recomputeDay()` when it was last touched, and this never
     * re-triggers `recomputeDay()` for a past Work_Date. Set exactly once; never cleared.
     */
    public function invalidateStaleSession(AdminAttendanceSession $session, string $reason): void
    {
        $session->update(['invalidated_reason' => $reason]);
    }

    /**
     * The structured Stale_Session_Warning body (Requirement 3.4), used by both branches of
     * `CheckInOutAction`. Message rendering is left to whichever client consumes this later.
     *
     * `invalidated_check_in_at` is the check-in that actually went stale (`openCheckInAt()`), not
     * necessarily the session's `first_check_in_at` — e.g. after a same-day Checkpoint_Commit
     * re-check-in, that's the one the admin needs to know about.
     */
    public function staleSessionWarningPayload(AdminAttendanceSession $session): array
    {
        $checkInAt = $session->openCheckInAt() ?? Carbon::parse($session->first_check_in_at);

        return [
            'code' => 'stale_session_invalidated',
            'invalidated_session_id' => $session->id,
            'invalidated_check_in_at' => $checkInAt->toIso8601String(),
            'workplace_id' => $session->workplace_id,
        ];
    }

    /**
     * Whether $checkInAt qualifies for the overnight (08:00 next day) branch of the
     * Valid_Checkout_Window rule, rather than the same-day-only (00:00 next day) branch.
     *
     * Extracted from `checkoutDeadlineFor()` so `AdminAttendanceSession::is_overnight` can expose
     * this decision directly to clients — no client needs to infer it by comparing
     * `checkout_deadline_at` against a check-in date, or know that 20:00 is the cutoff at all.
     */
    public static function isOvernightCheckIn(Carbon $checkInAt): bool
    {
        return $checkInAt->hour >= 20;
    }

    /**
     * The Valid_Checkout_Window rule (Requirement 2): pure function of the check-in time only.
     * If check-in occurred at or after 20:00, the window stays open until 08:00 the following
     * calendar day (overnight shift). Otherwise it closes at 00:00 the following calendar day —
     * i.e. checkout must occur the same calendar day as check-in.
     *
     * Static so `AdminAttendanceSession::checkout_deadline_at` can reuse the identical arithmetic
     * without instantiating the service — one rule, one implementation.
     */
    public static function checkoutDeadlineFor(Carbon $checkInAt): Carbon
    {
        $followingDay = $checkInAt->copy()->addDay()->startOfDay();

        return self::isOvernightCheckIn($checkInAt) ? $followingDay->setTime(8, 0) : $followingDay;
    }

    /**
     * Whether `$now` still falls within the Valid_Checkout_Window opened by `$checkInAt`.
     */
    public function isWithinValidCheckoutWindow(Carbon $checkInAt, Carbon $now): bool
    {
        return $now->lt(self::checkoutDeadlineFor($checkInAt));
    }

    /**
     * Persist a check-in: open (or reuse) today's session and append a check-in event.
     * Must be called inside a DB transaction.
     *
     * First detects and invalidates a Stale_Session at this (admin, workplace) if one exists
     * (Requirement 3) — this is the single place invalidation happens; `CheckInOutAction` routes
     * here whenever `resolveCheckInOrOutTarget()` found no open session nearby, whether that's
     * because the admin never checked in or because their session just went stale, instead of
     * duplicating the detect-and-invalidate logic.
     *
     * @return array{session: AdminAttendanceSession, invalidatedSession: ?AdminAttendanceSession}
     */
    public function persistCheckIn(int $adminId, int $workplaceId, string $coordinate, ?Carbon $now = null): array
    {
        $now = $now ?? now();
        $workDate = $now->format('Y-m-d');

        $staleSession = $this->findStaleOpenSession($adminId, $workplaceId, $now);
        if ($staleSession) {
            $this->invalidateStaleSession($staleSession, self::INVALIDATED_REASON_LATE_CHECKOUT);
        }

        $session = AdminAttendanceSession::query()
            ->where('user_id', $adminId)
            ->where('workplace_id', $workplaceId)
            ->where('work_date', $workDate)
            ->lockForUpdate()
            ->first();

        if (!$session) {
            $session = AdminAttendanceSession::query()->create([
                'user_id' => $adminId,
                'workplace_id' => $workplaceId,
                'work_date' => $workDate,
                'first_check_in_at' => $now->format('Y-m-d H:i:s'),
            ]);
        }

        $this->appendEvent($session, AdminAttendanceEventType::CheckIn, $now, $coordinate);
        $this->recomputeDay($adminId, $workDate, $now);

        return ['session' => $session->refresh(), 'invalidatedSession' => $staleSession];
    }

    /**
     * Persist a check-out: append a check-out event and recompute worked-hours for the whole day.
     * Must be called inside a DB transaction.
     */
    public function persistCheckOut(
        AdminAttendanceSession $session,
        string $coordinate,
        ?Carbon $now = null
    ): AdminAttendanceSession {
        $now = $now ?? now();

        $session = AdminAttendanceSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();

        $this->appendEvent($session, AdminAttendanceEventType::CheckOut, $now, $coordinate);
        $this->recomputeDay($session->user_id, $session->work_date->format('Y-m-d'), $now);

        return $session->refresh();
    }

    /**
     * Level 1 — paginated daily summary: one row per (admin, work_date) with the number of
     * workplaces visited, total worked-hours, and whether any session is missing a check-out.
     *
     * @param array $conditions dates[], user_id, page, page_size
     * @param int|null $ownerUserId when set, restrict to this admin's own summary (mobile self view)
     */
    public function getDailySummary(array $conditions, ?int $ownerUserId = null): LengthAwarePaginator
    {
        $dates = $conditions['dates'] ?? null;

        $paginator = AdminAttendanceSession::query()
            ->selectRaw('user_id, work_date')
            ->selectRaw('COUNT(*) as workplace_count')
            ->selectRaw('SUM(work_minutes) as total_minutes')
            ->selectRaw(
                'SUM(CASE WHEN has_missing_checkout AND invalidated_reason IS NULL THEN 1 ELSE 0 END) as missing_checkout'
            )
            ->selectRaw('SUM(CASE WHEN invalidated_reason IS NOT NULL THEN 1 ELSE 0 END) as invalidated_count')
            ->with('user:id,first_name,last_name,short_name,staff_no,profile_picture')
            ->when($ownerUserId !== null, fn($query) => $query->where('user_id', $ownerUserId))
            ->when(!empty($conditions['user_id']), fn($query) => $query->where('user_id', $conditions['user_id']))
            ->when($dates, function ($query) use ($dates) {
                $query->where('work_date', '>=', $dates[0]);
                if (isset($dates[1])) {
                    $query->where('work_date', '<=', $dates[1]);
                }
            })
            ->groupBy('user_id', 'work_date')
            ->orderByDesc('work_date')
            ->orderBy('user_id')
            ->paginate($conditions['page_size'] ?? null, ['*'], 'page', $conditions['page'] ?? 1);

        return $paginator->through(
            fn(AdminAttendanceSession $row) => [
                'user_id' => $row->user_id,
                'work_date' => $row->work_date->format('Y-m-d'),
                'user' => $row->user,
                'workplace_count' => (int) $row->workplace_count,
                'total_hours' => number_format(((int) $row->total_minutes) / 60, 2),
                'has_missing_checkout' => ((int) $row->missing_checkout) > 0,
                'has_invalidated_session' => ((int) $row->invalidated_count) > 0,
            ]
        );
    }

    /**
     * Level 2 — all sessions (with their event log) for one admin on one day.
     *
     * @param int|null $ownerUserId when set, restrict to this admin's own sessions (mobile self view)
     * @return Collection<int, AdminAttendanceSession>
     */
    public function getDayDetail(int $userId, string $date, ?int $ownerUserId = null): Collection
    {
        return AdminAttendanceSession::query()
            ->with([
                'events',
                'intervals',
                'workplace:id,name,address',
                'user:id,first_name,last_name,short_name,staff_no,profile_picture',
            ])
            ->where('user_id', $userId)
            ->when($ownerUserId !== null, fn($query) => $query->where('user_id', $ownerUserId))
            ->where('work_date', $date)
            ->orderBy('first_check_in_at')
            ->get();
    }

    /**
     * Recompute Worked_Hours and the missing-checkout flag for every workplace an admin visited
     * on one Work_Date, per the interval/interruption state machine (spec Requirement 8).
     *
     * This runs at the (user, work_date) level rather than per session because a check-out at
     * one workplace can retroactively invalidate ("interrupt") an already-open interval at a
     * different workplace the same day — see the Requirement 8 addendum in design.md for the
     * full rationale and a worked test-case matrix.
     *
     * The Current_Focus no-warning exemption (see below) only applies while $workDate is still
     * the current date as of $now; a past Work_Date flags any still-open interval regardless of
     * Current_Focus, since a finished day gives no reason to assume the admin is still there.
     *
     * Public (not just an internal step of persistCheckIn/persistCheckOut) because it is also the
     * natural hook for a future manual recompute/backfill action — recomputation only otherwise
     * runs as a side effect of a new event on that exact (admin, work_date), so nothing currently
     * re-flags a past day's dangling session once no further events arrive for it.
     *
     * Must be called inside the caller's DB transaction; locks every session for the day so
     * concurrent check-in/out requests for the same admin+day serialize.
     */
    public function recomputeDay(int $adminId, string $workDate, Carbon $now): void
    {
        $isToday = $workDate === $now->format('Y-m-d');

        $sessions = AdminAttendanceSession::query()
            ->where('user_id', $adminId)
            ->where('work_date', $workDate)
            ->lockForUpdate()
            ->with('events')
            ->get()
            ->keyBy('workplace_id');

        if ($sessions->isEmpty()) {
            return;
        }

        $events = $sessions
            ->flatMap(
                fn(AdminAttendanceSession $session) => $session->events->map(
                    fn(AdminAttendanceEvent $event) => [
                        'workplace_id' => $session->workplace_id,
                        'type' => $event->type,
                        'event_time' => Carbon::parse($event->event_time),
                    ]
                )
            )
            ->sortBy('event_time')
            ->values();

        $openStart = [];
        $interrupted = [];
        $creditedMinutes = [];
        $intervals = [];
        $lastCheckOutAt = [];

        foreach ($sessions as $workplaceId => $session) {
            $openStart[$workplaceId] = null;
            $interrupted[$workplaceId] = false;
            $creditedMinutes[$workplaceId] = 0;
            $intervals[$workplaceId] = [];
            $lastCheckOutAt[$workplaceId] = null;
        }

        $lastEvent = null;

        foreach ($events as $event) {
            $workplaceId = $event['workplace_id'];

            if ($event['type'] === AdminAttendanceEventType::CheckIn) {
                if ($openStart[$workplaceId] === null) {
                    $openStart[$workplaceId] = $event['event_time'];
                    $interrupted[$workplaceId] = false;
                } else {
                    // Checkpoint_Commit: the admin returned to an already-open workplace without
                    // an intervening check-out. Close and credit the accumulated span (unless it
                    // was already Interrupted, in which case it's stale and discarded), then open
                    // a fresh interval from this same timestamp.
                    if (!$interrupted[$workplaceId]) {
                        [$creditedMinutes[$workplaceId], $intervals[$workplaceId]] = $this->creditInterval(
                            $creditedMinutes[$workplaceId],
                            $intervals[$workplaceId],
                            $openStart[$workplaceId],
                            $event['event_time']
                        );
                    }
                    $openStart[$workplaceId] = $event['event_time'];
                    $interrupted[$workplaceId] = false;
                }
            } else {
                $lastCheckOutAt[$workplaceId] = $event['event_time'];

                if ($openStart[$workplaceId] !== null) {
                    if (!$interrupted[$workplaceId]) {
                        [$creditedMinutes[$workplaceId], $intervals[$workplaceId]] = $this->creditInterval(
                            $creditedMinutes[$workplaceId],
                            $intervals[$workplaceId],
                            $openStart[$workplaceId],
                            $event['event_time']
                        );

                        // A valid closure interrupts every OTHER workplace still open right now —
                        // a person can only be in one place, so a confirmed complete visit here
                        // invalidates any other dangling open interval, not just the one most
                        // recently left.
                        foreach ($openStart as $otherWorkplaceId => $start) {
                            if ($otherWorkplaceId !== $workplaceId && $start !== null) {
                                $interrupted[$otherWorkplaceId] = true;
                            }
                        }
                    }
                    // else: the open interval was already Interrupted — this check-out is
                    // audit-only (still recorded on the event, never altered here) and credits
                    // zero minutes.
                    $openStart[$workplaceId] = null;
                }
                // else: no open interval at this workplace — an orphan/duplicate check-out,
                // audit-only, no state change.
            }

            $lastEvent = $event;
        }

        foreach ($sessions as $workplaceId => $session) {
            $isOpen = $openStart[$workplaceId] !== null;
            $isCurrentFocus =
                $isToday &&
                $isOpen &&
                $lastEvent !== null &&
                $lastEvent['type'] === AdminAttendanceEventType::CheckIn &&
                $lastEvent['workplace_id'] === $workplaceId;

            $session->intervals()->delete();
            foreach ($intervals[$workplaceId] as $interval) {
                $session->intervals()->create($interval);
            }

            $session->update([
                'work_minutes' => $creditedMinutes[$workplaceId] > 0 ? $creditedMinutes[$workplaceId] : null,
                'last_check_out_at' => $lastCheckOutAt[$workplaceId]?->format('Y-m-d H:i:s'),
                'has_missing_checkout' => $isOpen && !$isCurrentFocus,
            ]);
        }
    }

    /**
     * Preview which of the admin's assigned workplaces is nearest to a GPS position, regardless
     * of whether it's within range — used by the mobile pre-check-in preview screen so the admin
     * can see the workplace and distance before attempting to check in/out. Read-only; persists
     * nothing.
     *
     * @return array{
     *     error?: ErrorCode,
     *     workplace?: Workplace,
     *     distanceMeters?: int,
     *     maxDistanceMeters?: int,
     *     withinRange?: bool
     * }
     */
    public function previewNearestWorkplace(User $admin, float $latitude, float $longitude): array
    {
        $result = $this->assignedWorkplaceDistances($admin, $latitude, $longitude);
        if (isset($result['error'])) {
            return $result;
        }

        // Sorted ascending by distance, so the first entry is the nearest.
        $nearest = $result['distances'][0];
        $maxDistance = (int) config('work.check_in.max_distance');

        return [
            'workplace' => $nearest['workplace'],
            'distanceMeters' => (int) round($nearest['distance']),
            'maxDistanceMeters' => $maxDistance,
            'withinRange' => $nearest['distance'] < $maxDistance,
        ];
    }

    /**
     * @return array{error?: ErrorCode, workplaces?: array<int, array{workplace: Workplace, distance: float}>}
     */
    private function assignedWorkplacesWithinRange(User $admin, float $latitude, float $longitude): array
    {
        $result = $this->assignedWorkplaceDistances($admin, $latitude, $longitude);
        if (isset($result['error'])) {
            return $result;
        }

        $maxDistance = (int) config('work.check_in.max_distance');
        $nearby = array_values(array_filter($result['distances'], fn(array $d) => $d['distance'] < $maxDistance));

        if (empty($nearby)) {
            return ['error' => ErrorCode::AdminAttendanceNoNearbyWorkplace];
        }

        // Already sorted ascending by distance by assignedWorkplaceDistances().
        return ['workplaces' => $nearby];
    }

    /**
     * Resolve the admin's assigned workplaces (team-scoped RBAC) and compute the distance from
     * each valid-coordinate workplace to the given GPS position, sorted ascending by distance.
     * Shared by assignedWorkplacesWithinRange() (filters to the threshold) and
     * previewNearestWorkplace() (always returns the nearest, in or out of range).
     *
     * @return array{error?: ErrorCode, distances?: array<int, array{workplace: Workplace, distance: float}>}
     */
    private function assignedWorkplaceDistances(User $admin, float $latitude, float $longitude): array
    {
        $workplaceIds = $admin->getWorkplaceIdsByPermission(PermissionName::ManagerAttendanceCheckIn->value);

        // Empty array => admin is not assigned to any workplace (null => global access to all workplaces)
        if (is_array($workplaceIds) && count($workplaceIds) === 0) {
            return ['error' => ErrorCode::AdminAttendanceNotAssignedWorkplace];
        }

        $candidates = Workplace::query()
            ->when($workplaceIds !== null, fn($query) => $query->whereIn('id', $workplaceIds))
            ->get();

        $distances = [];

        foreach ($candidates as $workplace) {
            if (!$this->hasValidCoordinates($workplace)) {
                continue;
            }

            $distance = WorkHelper::calculateDistance(
                (float) $workplace->latitude,
                (float) $workplace->longitude,
                $latitude,
                $longitude
            );

            $distances[] = ['workplace' => $workplace, 'distance' => $distance];
        }

        if (empty($distances)) {
            return ['error' => ErrorCode::AdminAttendanceNoNearbyWorkplace];
        }

        usort($distances, fn(array $a, array $b) => $a['distance'] <=> $b['distance']);

        return ['distances' => $distances];
    }

    /**
     * Candidate pool for `findOpenSession()`: every session for (admin, workplace) started since
     * the start of yesterday — a safe superset of any Valid_Checkout_Window, since no window
     * extends further back than a check-in the previous evening. The caller filters precisely
     * with `AdminAttendanceSession::openCheckInAt()` + `isWithinValidCheckoutWindow()`. Not used by
     * `findStaleOpenSession()`, which must not be bounded by recency — see its own docblock.
     *
     * Read-only: `findOpenSession()` never mutates on this lookup alone — `persistCheckOut()`
     * re-fetches and locks the session itself before writing.
     *
     * @return Collection<int, AdminAttendanceSession>
     */
    private function recentSessions(int $adminId, int $workplaceId, ?Carbon $now = null): Collection
    {
        $now = $now ?? now();

        return AdminAttendanceSession::query()
            ->where('user_id', $adminId)
            ->where('workplace_id', $workplaceId)
            ->where('first_check_in_at', '>=', $now->copy()->subDay()->startOfDay())
            ->orderByDesc('first_check_in_at')
            ->get();
    }

    private function appendEvent(
        AdminAttendanceSession $session,
        AdminAttendanceEventType $type,
        Carbon $eventTime,
        string $coordinate
    ): AdminAttendanceEvent {
        return $session->events()->create([
            'type' => $type,
            'event_time' => $eventTime->format('Y-m-d H:i:s'),
            'coordinate' => $coordinate,
        ]);
    }

    /**
     * Credit a closed Attendance_Interval [start, end): add its minutes to the running total and
     * append it to the list of intervals to persist for this workplace.
     *
     * @return array{0: int, 1: array<int, array{started_at: string, ended_at: string, minutes: int}>}
     */
    private function creditInterval(int $creditedMinutes, array $intervals, Carbon $start, Carbon $end): array
    {
        $minutes = (int) round($start->diffInMinutes($end));

        $intervals[] = [
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
            'minutes' => $minutes,
        ];

        return [$creditedMinutes + $minutes, $intervals];
    }

    private function hasValidCoordinates(Workplace $workplace): bool
    {
        $latitude = mb_trim((string) $workplace->latitude);
        $longitude = mb_trim((string) $workplace->longitude);

        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        return (float) $latitude >= -90 &&
            (float) $latitude <= 90 &&
            (float) $longitude >= -180 &&
            (float) $longitude <= 180;
    }
}
