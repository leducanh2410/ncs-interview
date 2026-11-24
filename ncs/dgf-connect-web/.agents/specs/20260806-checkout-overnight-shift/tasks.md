# Implementation Plan: Overnight Checkout Fix

## Overview

Build bottom-up: data model → core window/stale-session logic in `AdminAttendanceService` → check-in/check-out flow integration → the new read-only endpoint → tests, then the small web-reporting distinction. This spec covers the backend API and web (GlobalAdmin) reporting only, on branch `feat/DGFC_106_checkout-overnight-shift` in `dgf-connect-web`. Mobile consumption of this API (button state, warning banner) is deliberately out of scope — it will be planned as a separate spec in `dgf-connect-app-admin` once this contract has shipped and been tested.

### Implementation Strategy
- Foundation-first: the migration and `isWithinValidCheckoutWindow`/`findStaleOpenSession` primitives land before anything that calls them.
- Centralize invalidation in `persistCheckIn` (per design.md's Key Design Decisions) so `CheckInAction` and `CheckOutAction` both get Stale_Session handling from one implementation, not two.
- Defer the web-reporting badge (Requirement 4) until after the core fix is tested — it is presentation-only and has no dependents.

## Implementation Plan

### Phase 1: Backend Foundation — Data Model & Window Rule

- [x] 1. Add `invalidated_reason` to `admin_attendance_sessions`
    - [x] 1.1 Create migration `add_invalidated_reason_to_admin_attendance_sessions_table` (nullable string, default null)
        - Run the migration locally and confirm the column appears with the right nullability
        - _Requirements: 3.1_
    - [x] 1.2 Update `AdminAttendanceSession` model docblock (`@property ?string $invalidated_reason`) — no cast needed for a plain nullable string
        - _Requirements: 3.1_

- [x] 2. Implement the Valid_Checkout_Window rule in `AdminAttendanceService`
    - [x] 2.1 Add `checkoutDeadlineFor(Carbon $checkInAt): Carbon` and `isWithinValidCheckoutWindow(Carbon $checkInAt, Carbon $now): bool`
        - `$checkInAt->hour >= 20` → deadline is 08:00 the following calendar day
        - Otherwise → deadline is 00:00 the following calendar day (same Work_Date as check-in)
        - `isWithinValidCheckoutWindow` is simply `$now->lt(checkoutDeadlineFor($checkInAt))` — one rule, one implementation
        - _Requirements: 2.1, 2.2_
    - [x] 2.1b Expose `checkout_deadline_at` as an appended attribute on `AdminAttendanceSession` (alongside the existing `work_hours`), derived via `checkoutDeadlineFor($this->first_check_in_at)`
        - Never persisted; recomputed on serialization so historical rows stay correct if the rule changes
        - _Requirements: 2.6_
    - [x] 2.2 Widen `recentSessions()`'s bound from `now()->subDay()` to `now()->copy()->subDay()->startOfDay()`
        - Keep `orderByDesc('first_check_in_at')` unchanged; this only enlarges the candidate pool for precise PHP-side filtering
        - _Requirements: 2.5_
    - [x] 2.3 Make `findOpenSession()` window-aware: require `isWithinValidCheckoutWindow($session->first_check_in_at, $now)` in addition to the existing `isSessionOpen($session)` check
        - _Requirements: 1.1, 1.3, 2.3_
    - [x] 2.4 Add `findStaleOpenSession(int $adminId, int $workplaceId, ?Carbon $now = null, bool $lock = true): ?AdminAttendanceSession`
        - Same candidate pool as `findOpenSession`; returns the session where `isSessionOpen()` is true and `isWithinValidCheckoutWindow()` is false
        - **Must filter `invalidated_reason IS NULL`** — invalidation deliberately never writes `last_check_out_at`, so an already-resolved session still looks open and would be re-warned forever without this
        - `$lock` toggles `lockForUpdate`: true for the mutating check-in/out paths, false for the read-only endpoint
        - _Requirements: 2.4, 3.1_
    - [x] 2.5 Add `invalidateStaleSession(AdminAttendanceSession $session, string $reason): void`
        - Sets `invalidated_reason`; does not touch `work_minutes`, `last_check_out_at`, or events
        - _Requirements: 3.1_
    - [x] 2.6 Add `staleSessionWarningPayload(AdminAttendanceSession $session): array`
        - Returns `{ code: 'stale_session_invalidated', invalidated_session_id, invalidated_check_in_at, workplace_id }`
        - _Requirements: 3.4_

- [x] 3. Checkpoint - Run existing `AdminAttendanceCheckInTest`/`AdminAttendanceHistoryTest` suites
    - Confirm on-time (same-day) check-in/out scenarios are unaffected by the widened lookback and the new window check; ask the user if any existing test's expectations look like they were implicitly relying on the old flat-24h behavior.

### Phase 2: Backend — Check-In/Check-Out Flow Integration

- [x] 4. Wire Stale_Session detection into `persistCheckIn`
    - [x] 4.1 Call `findStaleOpenSession()` first; if found, `invalidateStaleSession()` it before the existing find-or-create-today's-session logic
        - _Requirements: 3.1, 3.3_
    - [x] 4.2 Change return type to `array{session: AdminAttendanceSession, invalidatedSession: ?AdminAttendanceSession}`; update the one existing caller (`CheckInAction`)
        - _Requirements: 3.4_

- [x] 5. Wire the stale fallback into `resolveCheckOutSession`
    - [x] 5.1 When no nearby workplace has a valid open session and `findRecentSession` (today-only) also finds nothing, call `findStaleOpenSession()` at the nearest candidate workplace
        - If found, return `['workplace' => $nearest, 'staleSession' => $staleSession]` instead of `AdminAttendanceNotCheckedIn`
        - _Requirements: 3.2_

- [x] 6. Extend the response envelope for sibling fields (`warning`, `stale_session`)
    - [x] 6.1 Add optional `?array $meta = null` to `ActionResult`'s constructor; `toResponse()` forwards it to `ResponseHelper::success()`
        - Used by both the check-in/out `warning` (Task 7) and `current-session`'s `stale_session` (Task 10.2)
        - _Requirements: 3.4, 1.4_
    - [x] 6.2 Add `array $extra = []` param to `ResponseHelper::success()`, merged into the top-level JSON object alongside `data`
        - Confirm every existing call site (omits `$extra`) still returns byte-identical responses
        - _Requirements: 3.4, 1.4_

- [x] 7. Update `CheckInAction` and `CheckOutAction`
    - [x] 7.1 `CheckInAction`: read `invalidatedSession` off `persistCheckIn`'s result; attach `staleSessionWarningPayload()` to `ActionResult.meta` when present
        - _Requirements: 3.3, 3.4_
    - [x] 7.2 `CheckOutAction`: when `resolveCheckOutSession()` returns `staleSession`, call `persistCheckIn()` for that workplace (using the request's coordinate/`now`) instead of attempting a checkout; return its session + warning
        - _Requirements: 3.2, 3.4_

- [x] 8. Reclassify invalidated sessions in the daily summary aggregate
    - [x] 8.1 `getDailySummary()`: change `SUM(CASE WHEN has_missing_checkout THEN 1 ELSE 0 END)` to also require `invalidated_reason IS NULL`
        - _Requirements: 4.2_
    - [x] 8.2 `getDailySummary()`: add `SUM(CASE WHEN invalidated_reason IS NOT NULL THEN 1 ELSE 0 END)` and expose it as `has_invalidated_session` (bool) in the `->through()` row transform
        - **Must land with 8.1**, not after: 8.1 alone removes the badge from a day whose only anomaly was an unclosed night session, leaving it looking clean despite lost hours
        - _Requirements: 4.3, 4.4_

- [x] 9. Checkpoint - Run backend test suite
    - Ensure all existing and any ad-hoc manual checks for check-in/check-out pass; ask the user before proceeding if `resolveCheckOutSession`'s new branch changes behavior for any currently-passing test around repeat/duplicate checkouts.

### Phase 3: Backend — Current-Session Endpoint

- [x] 10. New read-only endpoint
    - [x] 10.1 Add `CurrentSessionRequest` FormRequest (`workplace_id`: `required|integer|exists:workplaces,id`)
        - _Requirements: 1.1_
    - [x] 10.2 Add `GetCurrentSessionAction`, calling `AdminAttendanceService::findOpenSession($admin->id, $workplaceId, now())` for `data`
        - When that returns null, call `findStaleOpenSession(..., lock: false)` and report it as `stale_session` (id, workplace_id, work_date, first_check_in_at, checkout_deadline_at)
        - Strictly read-only: never invalidate the reported Stale_Session
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5_
    - [x] 10.3 Add `CheckInController::currentSession()` and route `GET /admin/attendance/current-session` in the existing mobile-self `attendance` group (`routes/api.php`), same `ManagerAttendanceCheckIn` permission as sibling routes
        - _Requirements: 1.1_

### Phase 4: Backend — Testing

- [x] 11. Unit/Feature Tests
    - [x] 11.1 `AdminAttendanceCheckInTest` additions (`backend/tests/Feature/...`)
        - Check-in 21:00 → checkout 07:00 next day: valid, credited to original Work_Date, no warning
        - Check-in 21:00 → checkout 08:30 next day: checkout creates a new check-in for today; original session `invalidated_reason = 'late_checkout'`; response includes `warning`
        - Check-in 15:00 (before 20:00) → checkout 00:01 next day: invalidated immediately (same-day-only rule)
        - Check-in 21:00 → admin's client calls check-in again at 09:00 next day: existing session invalidated via the check-in path (not just checkout), `warning` present
        - `current-session`: returns session (with correct `checkout_deadline_at`) when within window; `data: null` + populated `stale_session` when only a Stale_Session exists; both null when nothing open; both null when the stale session was already invalidated; `422` on missing `workplace_id`
        - `current-session` is side-effect free: after calling it while a Stale_Session exists, assert that session's `invalidated_reason` is still `null`
        - `checkout_deadline_at` boundaries: 21:00 check-in → 08:00 next day; 15:00 → 00:00 next day; exactly 20:00 → overnight branch
        - _Requirements: 1.1-1.6, 2.1-2.6, 3.1-3.5_
    - [x] 11.2 `AdminAttendanceHistoryTest` additions
        - Daily summary excludes an invalidated session from `missing_checkout` count while still counting a genuine unresolved one
        - Daily summary sets `has_invalidated_session` true for a day containing one — including the regression case where it is the day's only anomaly (`has_missing_checkout` false, row must still be flagged)
        - Day detail response includes `invalidated_reason` for an invalidated session
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

- [x] 12. Checkpoint - Full backend suite green
    - `docker compose exec php php artisan test --parallel`; ask the user about any failure before proceeding to the frontend slice.

### Phase 5: Web Frontend — Reporting Distinction

- [x] 13. Surface invalidated sessions in both history levels
    - [x] 13.1 Add `invalidated_reason: string | null` to the `AdminAttendanceSession` type and `has_invalidated_session: boolean` to the daily-summary row type
        - _Requirements: 4.1, 4.3_
    - [x] 13.2 Level 2 day detail: render the invalidated badge on an invalidated session's card instead of the generic missing-checkout warning, plus the explanatory sentence
        - Wrap both strings in `t(...)`; copy is specified in design.md's "Frontend Copy (Web)" table
        - _Requirements: 4.1_
    - [x] 13.3 Level 1 daily list: render an invalidated badge for a row with `has_invalidated_session`, independent of the existing missing-checkout badge
        - Unlike Level 2 these two badges stack — one row aggregates a whole day and can contain one of each
        - _Requirements: 4.4, 4.5_
    - [x] 13.4 Add the two new strings to the locale files via the standard flow: `pnpm trans:prepare` → translate → `pnpm trans:apply`
        - Use the vi/ja text from design.md rather than machine defaults; it is deliberately worded to match the existing `"Missing check-out"` entry's terminology
        - _Requirements: 4.1, 4.4_
    - [x] 13.5 Frontend tests: Level 2 shows the invalidated badge for a session with `invalidated_reason`; Level 1 shows it for a flagged row, shows both badges when a day has one of each, and still shows a badge when `has_invalidated_session` is the row's only flag
        - _Requirements: 4.1, 4.4, 4.5_

### Phase 6: Final Validation

- [x] 14. Code Quality
    - [x] 14.1 Backend: `npm run prettier` and `npm run pint` (per `dgf-connect-web/CLAUDE.md`)
    - [x] 14.2 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` (in `./frontend`)

## Notes

- **Ordering rationale**: the response contract (Phases 1-4) is built and tested before the presentation-only web change (Phase 5), which depends on `invalidated_reason` existing and being populated correctly.
- **Risk — `resolveCheckOutSession` behavior change**: Phase 2, Task 5 changes what happens on repeat/duplicate checkout attempts once no valid or today-recent session exists; Task 9's checkpoint exists specifically to catch any existing test asserting the old `AdminAttendanceNotCheckedIn` behavior in a case that's now a Stale_Session.
- **Risk — `persistCheckIn` return type change**: Task 4.2 is a breaking signature change to an existing method; Task 4.2 explicitly calls out updating its one existing caller so this isn't missed.
- **No scheduled job**: per the requirements' explicit constraint, nothing in this plan adds a cron/queue job — all Stale_Session handling is request-time, matching Phase 1-4's design.
- **Mobile is out of scope**: `current-session`, and the `warning`/`invalidated_reason` fields, are the contract a future `dgf-connect-app-admin` spec will build against — nothing in this plan touches the mobile app. Ship and stabilize Phases 1-4 before that follow-up spec is written.
