# Requirements Document

## Introduction

This feature adds workplace attendance tracking for admins who physically inspect factories ("xưởng"). Unlike employee attendance, which is bound to a pre-assigned shift, an admin freely visits multiple workplaces during a single day to inspect them. On the mobile app the admin taps to check in and out at each workplace (GPS location resolves the nearest assigned workplace; no face recognition); on the web admin panel, GlobalAdmin reviews the resulting attendance history — a daily list drilling into a per-day detail with per-workplace worked hours and the full check-in/check-out event log. This introduces a new, independent attendance data domain (session-level records plus an event log) and does not modify the existing shift-based employee check-in flow.

### Feature Summary

A shift-independent attendance system that lets managing admins tap to check in/out at multiple workplaces per day on mobile (GPS-resolved nearest workplace) and lets GlobalAdmin review the resulting worked-hours history (daily list + per-day detail).

### Business Value

- Provides visibility into how much time each managing admin spends inspecting each workplace, and which workplaces they visited.
- Enforces on-site presence via GPS proximity to the nearest assigned workplace.
- Reuses the company's existing location/distance infrastructure and RBAC, keeping behavior consistent with employee attendance.

### Scope

**Included:**

- Mobile API for admin workplace check-in and check-out with a single tap (GPS-based nearest-workplace resolution; NO face recognition / photo capture).
- A new session record per (admin, workplace, day) and an event log recording every check-in/check-out.
- Aggregation of worked hours per workplace as the raw duration from the earliest check-in to the latest check-out.
- Web admin history (GlobalAdmin-only), two levels: Level 1 a paginated daily list (one row per admin + day, with workplace count, total hours, warning); Level 2 a full-screen day detail listing each workplace with its inline check-in/out log.
- Web admin filtering of the daily list by date range and by admin.
- Restriction of check-in eligibility to admins assigned to manage the target workplace.

**Excluded:**

- Any change to the existing shift-based employee check-in/out flow (`ShiftUser` / `CheckIn`).
- Payroll/salary calculation based on admin attendance (raw hours only; no rounding, break deduction, overtime, or night-shift splitting).
- Editing/correcting admin attendance sessions or events from the web; the check-in/check-out history is view-only in this phase.
- Mobile UI implementation (this repository is web + API; the mobile client is a separate codebase consuming the API).

## Glossary

| Term | Definition |
|------|------------|
| Managing_Admin | A user authenticated through the admin/manager gate (`manager.auth` permission, same as web admin login) who is assigned to manage one or more workplaces. The mobile check-in endpoints sit behind this admin auth, so callers are already admins. |
| Assigned_Workplaces | The set of workplaces a Managing_Admin manages, resolved via the existing team-scoped RBAC helper `User::getWorkplaceIdsByPermission()`. GlobalAdmin resolves to all workplaces; an Admin resolves to the workplaces their role is scoped to (may be empty). |
| Attendance_Session | A single record per (Managing_Admin, Workplace, work_date) aggregating that admin's presence at that workplace on that day. |
| Attendance_Event | A single check-in or check-out action, logged with timestamp and GPS coordinate; belongs to one Attendance_Session. No image or face data is stored. |
| Work_Date | The calendar date (company timezone) to which an Attendance_Session belongs, derived from the first check-in time. |
| Nearest_Workplace | The workplace, among the admin's Assigned_Workplaces, whose distance (computed from GPS coordinates) to the admin's current GPS position is smallest and within the Proximity_Threshold. |
| Proximity_Threshold | The maximum allowed distance (meters) between the admin's GPS position and a workplace for a check-in/out to be accepted. Reuses the existing employee configuration `work.check_in.max_distance` (currently 200m). |
| Worked_Hours | **Superseded 2026-07-05 (Requirement 8)**: the sum of an Attendance_Session's valid, closed Attendance_Intervals for that Work_Date. No longer the raw earliest-check-in-to-latest-check-out span — see Requirement 8. |
| Attendance_Interval | A committed, credited span `[start, end]` at one workplace, closed either by an explicit check-out or by a Checkpoint_Commit. Only valid Attendance_Intervals count toward Worked_Hours. Introduced by Requirement 8. |
| Interrupted | A workplace's currently-open Attendance_Interval is marked Interrupted when a DIFFERENT workplace closes a valid Attendance_Interval (via an explicit check-out) while the first interval is still open. An Interrupted interval's eventual check-out is audit-only and credits zero minutes. Introduced by Requirement 8. |
| Checkpoint_Commit | A check-in at a workplace that is already open (the admin returns without an intervening check-out) closes the currently accumulating interval (crediting it, unless already Interrupted — in which case it is discarded) and immediately opens a fresh interval at the same timestamp. Introduced by Requirement 8. |
| Current_Focus | The workplace whose check-in is the temporally last Attendance_Event of the Work_Date, provided its interval is still open at the end of processing. The only open, unresolved interval exempt from the missing-checkout warning. Introduced by Requirement 8. |
| Attendance_Day_Detail | The full-screen Level 2 web page showing all of one admin's Attendance_Sessions (with inline event logs) for one Work_Date. |
| Attendance_Web_History | The GlobalAdmin-only web screens for admin attendance: a Level 1 daily list (one row per admin + day) and the Level 2 Attendance_Day_Detail screen. |

## Requirements

### Requirement 1: Admin Workplace Check-In (Mobile)

**User Story:** As a Managing_Admin inspecting factories, I want to check in at the workplace I am currently at with a single tap, so that the system records my arrival with the verified location.

> No face capture: admin check-in/out does NOT use face recognition or photo capture. The admin just taps to check in/out; the app sends the GPS coordinates to resolve the nearest assigned workplace.

#### Acceptance Criteria

1. WHERE the admin check-in endpoint is invoked THEN it SHALL sit behind the existing admin auth (`manager.auth`) middleware, so the caller is already an authenticated admin and no additional role check is performed in the action.
2. WHEN a Managing_Admin submits a check-in with GPS latitude/longitude THEN the system SHALL resolve the admin's Assigned_Workplaces via team-scoped RBAC and then compute the Nearest_Workplace among them.
3. IF the admin's Assigned_Workplaces is empty THEN the system SHALL reject the check-in with a distinct "not assigned to any workplace" error code and message (so the mobile app can inform the admin) and SHALL NOT create any record.
4. IF no Assigned_Workplace is within the Proximity_Threshold of the admin's GPS position THEN the system SHALL reject the check-in with a distinct "no nearby workplace" error code and message and SHALL NOT create any record.
5. IF GPS latitude/longitude is missing or invalid THEN the system SHALL reject the check-in with a validation error.
6. IF no Attendance_Session exists for the (admin, resolved workplace, current Work_Date) THEN the system SHALL create a new Attendance_Session and record a check-in Attendance_Event.
7. IF an Attendance_Session already exists for the (admin, resolved workplace, current Work_Date) THEN the system SHALL NOT create a second session and SHALL record the action as an additional Attendance_Event on the existing session (the earliest check-in time is preserved).
8. WHEN a check-in Attendance_Event is created THEN the system SHALL persist the event timestamp and GPS coordinate (no image, no face-verification data).

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: `WorkHelper::calculateDistance`, admin auth (`manager.auth`), team-scoped workplace resolution (`User::getWorkplaceIdsByPermission()`).

### Requirement 2: Admin Workplace Check-Out (Mobile)

**User Story:** As a Managing_Admin leaving a workplace, I want to check out, so that the system records the time I finished at that workplace.

#### Acceptance Criteria

1. WHEN a Managing_Admin submits a check-out with GPS latitude/longitude THEN the system SHALL resolve the Nearest_Workplace among the admin's Assigned_Workplaces and locate the current-day Attendance_Session for that workplace.
2. IF the admin's Assigned_Workplaces is empty OR no Assigned_Workplace is within the Proximity_Threshold THEN the system SHALL reject the check-out with the same distinct error codes as check-in (Requirement 1.3 / 1.4).
3. IF no Attendance_Session exists for the (admin, resolved workplace, current Work_Date) THEN the system SHALL reject the check-out with a "not checked in" error.
4. WHEN a valid check-out is submitted THEN the system SHALL record a check-out Attendance_Event with timestamp and GPS coordinate (no image, no face-verification data).
5. WHEN a check-out Attendance_Event is recorded THEN the system SHALL recompute Worked_Hours per the interval/interruption model of Requirement 8, not as a raw earliest-check-in-to-latest-check-out span.
6. WHERE a session already has one or more check-outs THEN the system SHALL allow additional check-outs; a check-out with no corresponding open Attendance_Interval SHALL be recorded as an audit-only event and SHALL NOT alter Worked_Hours (Requirement 8.10) — it no longer "always uses the latest check-out."
7. WHEN the check-out timestamp is not after the session's earliest check-in THEN the system SHALL reject the check-out with a validation error.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1.

### Requirement 3: Multi-Workplace Concurrent Presence

**User Story:** As a Managing_Admin, I want to check in at workplace B even though I have not checked out of workplace A, so that I can move between factories without being blocked.

#### Acceptance Criteria

1. WHEN a Managing_Admin checks in at a workplace THEN the system SHALL NOT require any other open Attendance_Session (at a different workplace) to be checked out first.
2. WHILE a Managing_Admin has an Attendance_Session without a check-out at workplace A the system SHALL allow creating and operating an Attendance_Session at workplace B on the same Work_Date.
3. WHEN multiple Attendance_Sessions exist for the same admin on the same Work_Date THEN the system SHALL track each workplace's Worked_Hours independently.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Requirement 2.

### Requirement 4: Web Admin — Daily Attendance List (Level 1)

**User Story:** As a GlobalAdmin, I want a daily attendance list with one row per admin per day, so that I can scan who worked and how long even when admins visit several workplaces a day.

> Access: the web history screens (Requirements 4, 5, 7) are GlobalAdmin-only. On mobile, a non-global admin sees only their own history; a GlobalAdmin on mobile sees everyone.

#### Acceptance Criteria

1. WHEN a GlobalAdmin opens the Attendance_Web_History THEN it SHALL display a paginated list with one row per (Managing_Admin, Work_Date).
2. WHERE a Managing_Admin has attendance on multiple days THEN that admin SHALL appear on multiple rows (one per day); a single day is a single row regardless of how many workplaces were visited.
3. WHEN a daily row is displayed THEN it SHALL show: Work_Date, the Admin column (avatar, name, staff number, linking to the admin's profile), the number of workplaces visited that day, the total Worked_Hours for the day (sum of that day's valid, closed Attendance_Intervals across all workplaces, per Requirement 8), and a warning indicator when any workplace that day has an open, unresolved interval that is not the Current_Focus.
4. WHEN a daily row's detail action is triggered THEN the system SHALL navigate to the Attendance_Day_Detail screen (Requirement 5) for that (admin, Work_Date).

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Requirement 2.

### Requirement 5: Web Admin — Day Detail Screen (Level 2)

**User Story:** As a GlobalAdmin, I want a detail screen for an admin's day showing each workplace with its check-in/out log inline, so that I can verify the hours per workplace without extra clicks.

#### Acceptance Criteria

1. WHEN a GlobalAdmin opens the Attendance_Day_Detail THEN it SHALL be a full screen (not a modal/popup) at its own route, showing every Attendance_Session for that (admin, Work_Date).
2. WHEN a session is displayed THEN it SHALL show the Workplace (linking to the workplace detail), the list of that session's valid, closed Attendance_Intervals (start/end/duration per Requirement 8), the session's total Worked_Hours (or a warning when the session's open interval is not the Current_Focus and remains unresolved), AND the full chronological list of that session's check-in/out Attendance_Events inline (including audit-only events discarded from Worked_Hours).
3. WHERE the day has several workplaces THEN the sessions SHALL render as a vertically scrollable list.
4. WHEN the screen is opened THEN it SHALL provide a way to return to the Level 1 daily list.
5. WHERE the content is displayed THEN it SHALL be read-only (no editing).

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4.

### Requirement 6: Attendance Data Model Separation

**User Story:** As a maintainer, I want admin attendance stored in its own session and event tables separate from the shift-based employee check-in tables, so that the two flows evolve independently and remain easy to reason about.

#### Acceptance Criteria

1. WHERE admin attendance is persisted THEN the system SHALL use an Attendance_Session table and an Attendance_Event table distinct from `check_ins` and `check_in_verifications`.
2. WHEN persisting a session THEN the Attendance_Session SHALL uniquely identify a (user, workplace, Work_Date) combination.
3. WHEN persisting an event THEN each Attendance_Event SHALL reference exactly one Attendance_Session and record its type (check-in or check-out), timestamp, and GPS coordinate.
4. WHEN Worked_Hours is queried THEN it SHALL be derivable from the session's earliest check-in and latest check-out without altering the existing employee attendance schema.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None.

### Requirement 7: Web Admin — History Filtering

**User Story:** As a GlobalAdmin, I want to filter the daily attendance list by a date range and by admin (like the employee Work History filters), so that I can focus on a specific period or person.

#### Acceptance Criteria

1. WHEN a GlobalAdmin sets a start date and end date THEN the Level 1 daily list SHALL restrict rows to Work_Dates within the inclusive range.
2. IF no date range is explicitly chosen THEN the list SHALL default to a sensible recent range (e.g., the last 7 days).
3. WHEN a GlobalAdmin selects an admin in the admin filter THEN the list SHALL restrict rows to that admin.
4. WHEN filters are changed THEN the list SHALL re-query and reflect the updated result set.

#### Additional Details

- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 4.

### Requirement 8: Interval-Based Worked Hours & Interruption Handling

**Added 2026-07-05.** Supersedes the raw "earliest check-in → latest check-out" Worked_Hours definition used in Requirements 2, 4, and 5 above.

**User Story:** As a GlobalAdmin reviewing attendance, I want worked hours computed from genuinely valid, uninterrupted visit intervals — not from the raw span between an admin's first check-in and last check-out of the day — so that switching workplaces and stray/late check-outs cannot inflate a workplace's reported hours.

**Rule of thumb (added 2026-07-05, for quick reference before the acceptance criteria below):** among concurrently open workplaces, whichever one closes via an explicit check-out **first** is credited its full duration, and that closure marks every other still-open workplace Interrupted (Acceptance Criteria 2–4) — a later check-out at an Interrupted workplace is logged for audit only and credits zero minutes. The one exception: closing via a Checkpoint_Commit (a return check-in to the same workplace, Acceptance Criterion 5) also credits fully, but does **not** mark other open workplaces Interrupted (Acceptance Criterion 6) — only an explicit check-out does that.

**Motivating examples:**

1. `A CI 09:00 → A CO 10:00 → A CI 11:00 → A CO 12:00` ⇒ A = 2h (1h + 1h), not 09:00→12:00 = 3h.
2. `A CI 09:00 → B CI 10:00 → B CO 14:00 → A CO 18:00` ⇒ only B = 4h is counted; the A check-out at 18:00 is still recorded as an event (audit trail) but credits zero minutes to A, because B fully completed a round-trip while A was still open.
3. `A CI 09:00 → B CI 10:00 → A CI 11:00` ⇒ A = 2h, no warning. Returning to a workplace that is already open (without an intervening check-out) is an "extend/confirm," not an interruption.

**Derived edge-case examples (added 2026-07-05, for reference/regression alongside Examples 1–3 above; also tabulated in `design.md`'s Test Case Matrix):**

4. `A CI 09:00 → B CI 10:00 → B CO 11:00` (day ends there) ⇒ A = 0 (interrupted by B's valid closure, never resolved), B = 1h. **Both** A and B are flagged missing-checkout: the last event is a check-out, so nothing is exempted as Current_Focus (Acceptance Criterion 9).
5. `A CI 09:00 → A CO 10:00 → A CO 12:00` ⇒ A = 1h, not 3h. The second check-out has no open interval to close, so it is recorded as an audit-only event and does not extend Worked_Hours (Acceptance Criterion 10).
6. `A CI 09:00 → B CI 10:00 → B CO 11:00 → A CI 13:00` ⇒ A = 0 credited so far (its 09:00 interval was interrupted by B's valid closure), then a fresh, non-interrupted interval opens at 13:00; A is now the Current_Focus and is not flagged, even though its new interval is still open (Acceptance Criteria 3, 5, 8).
7. `A CI 09:00 → B CI 10:00 → A CI 11:00 → B CI 12:00` ⇒ A = 2h (Checkpoint_Commit closes 09:00→11:00, then reopens), B = 2h (A's checkpoint does NOT interrupt B — Acceptance Criterion 6 — so B's own return check-in at 12:00 is a normal Checkpoint_Commit crediting its full 10:00→12:00 span rather than discarding it). At the end, A's fresh open interval (from 11:00) is flagged missing-checkout because B's 12:00 check-in is the Current_Focus, not A's.
8. Same sequence as Example 3 (`A CI 09:00 → B CI 10:00 → A CI 11:00`), but for a Work_Date that is now in the past (reviewed on a later day) ⇒ A still credits 2h, but is now ALSO flagged missing-checkout — the Current_Focus exemption no longer applies once the day is over (Acceptance Criterion 11).

#### Acceptance Criteria

1. WHEN an Attendance_Session has multiple sequential check-in/check-out pairs at the same workplace on the same Work_Date THEN Worked_Hours SHALL be the sum of each pair's duration (Example 1), not the earliest-check-in-to-latest-check-out span.
2. WHEN a check-out event closes an Attendance_Interval that was NOT Interrupted THEN the system SHALL credit its full duration and treat the closure as a valid, completed round-trip.
3. WHEN a check-out event closes an Attendance_Interval that WAS Interrupted THEN the system SHALL record the check-out as an audit-only Attendance_Event, credit zero minutes for that interval, and clear the open marker so a future check-in at that workplace starts a fresh interval (Example 2).
4. WHEN any workplace's Attendance_Interval closes validly (Acceptance Criterion 2) THEN every OTHER workplace with a currently-open Attendance_Interval for the same admin and Work_Date SHALL be marked Interrupted — not only the workplace the admin most recently left. Requirement 3's concurrency allowance is unaffected; only hours computation changes.
5. WHEN a check-in event targets a workplace that is already open (the admin returns without an intervening check-out) THEN the system SHALL treat it as a Checkpoint_Commit: if the open interval is not Interrupted, close and credit it up to this event's time and open a fresh interval at the same timestamp (Example 3); if the open interval IS Interrupted, discard it (zero credit) and open a fresh, non-Interrupted interval at this event's time.
6. A Checkpoint_Commit (Acceptance Criterion 5) SHALL NOT itself mark any OTHER workplace's open interval as Interrupted — only an explicit check-out that closes a valid interval does (Acceptance Criterion 4).
7. WHEN computing Worked_Hours for a Work_Date THEN the system SHALL sum only closed, credited Attendance_Intervals; an interval left open at the end of processing (no closing check-out or Checkpoint_Commit yet) SHALL contribute zero to Worked_Hours and SHALL NOT be estimated, projected, or displayed as a running/elapsed number.
8. WHEN determining the missing-checkout warning for a Work_Date THEN the system SHALL flag exactly those workplaces with an open, unresolved Attendance_Interval at the end of processing that are NOT the Current_Focus; the Current_Focus's open interval SHALL NOT be flagged (Example 3: A is Current_Focus and unflagged; B is left open and unresolved and SHALL be flagged).
9. IF the Work_Date's last Attendance_Event is a check-out (nothing is currently "active") THEN there SHALL be no Current_Focus exemption; any workplace left open from earlier in the day SHALL be flagged as missing checkout.
10. WHEN a check-out event has no corresponding open Attendance_Interval at that workplace (e.g., a second check-out after the interval already closed) THEN the system SHALL record it as an audit-only Attendance_Event and SHALL NOT extend, reopen, or otherwise alter any Attendance_Interval or Worked_Hours.
11. The Current_Focus exemption (Acceptance Criterion 8) SHALL apply only WHEN the Work_Date is the current date (as of when the interval computation runs). IF the Work_Date is a past date THEN any workplace with an open, unresolved Attendance_Interval SHALL be flagged missing-checkout regardless of whether it was the last check-in of that day — a day that has already ended gives no reason to assume the admin is still there. **Confirmed 2026-07-05.**

#### Additional Details

- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 2, Requirement 3, Requirement 6.

> **Breaking change vs. the initial implementation**: `AdminAttendanceCheckInTest::testMultipleCheckOutsUseLatestForWorkedHours` (check-in 09:00, check-out 10:00, check-out 12:00) previously expected `work_minutes = 180`. Under Acceptance Criterion 10, the second check-out is an audit-only orphan event — the corrected expectation is `work_minutes = 60` (only the valid 09:00→10:00 interval is credited). This test's assertion must change, not merely be re-verified.

## Non-Functional Requirements

### Performance Requirements

- WHEN resolving the Nearest_Workplace THEN the system SHALL evaluate only workplaces assigned to the acting admin and SHALL return within the same latency envelope as the existing employee check-in endpoint.
- WHEN rendering the by-day or by-admin history for a single day THEN the API SHALL return results using indexed queries on (user_id, work_date, workplace_id) without N+1 event loading.

### Security Requirements

- WHERE the web attendance history is accessed THEN the system SHALL restrict it to GlobalAdmin only (the role that manages admins), enforced by a `role:global_admin` gate on both the API and the web menu/route.
- WHERE the mobile attendance history is accessed by a non-global Managing_Admin THEN the system SHALL return only that admin's own sessions; a GlobalAdmin on mobile MAY use the same (all-admins) history endpoints as the web.
- WHEN any attendance endpoint is called THEN the system SHALL authenticate the caller via the existing admin auth (`manager.auth`) and SHALL scope check-in/out to the admin's team-scoped Assigned_Workplaces.
- IF an authenticated admin has no Assigned_Workplaces THEN the system SHALL return a distinct, actionable error code (not a generic 403) so the mobile app can display a clear message.
- WHERE a web admin requests attendance data THEN the system SHALL scope results to the workplaces/admins the requester is permitted to view.

### Usability Requirements

- WHEN a session lacks a check-out THEN the web UI SHALL clearly warn the reviewer that hours cannot be computed for that session.
- WHEN any user-facing text is displayed on the web THEN it SHALL use the translation function (no hardcoded strings), consistent with project i18n rules.

### Reliability Requirements

- WHEN concurrent check-in requests for the same (admin, workplace, day) occur THEN the system SHALL create at most one Attendance_Session (enforced by a uniqueness constraint and locking).
- IF an error occurs mid-request THEN the system SHALL NOT persist a partial Attendance_Event (atomic transaction).

## Constraints and Assumptions

### Technical Constraints

- Backend is Laravel 11 (Action pattern, classes imported via `use`); frontend is Next.js 15 + React 19 + Mantine.
- Distance calculation reuses the existing `WorkHelper::calculateDistance`; workplace scoping reuses Laratrust team-scoped RBAC. No face-recognition/image services are used for admin attendance.
- The Proximity_Threshold reuses the existing employee configuration `work.check_in.max_distance` (currently 200m); no separate admin threshold is introduced. Nearest_Workplace is computed purely from GPS distance among the admin's assigned workplaces.

### Assumptions

- Admin authentication reuses the existing `manager.auth` gate (Laratrust); the mobile check-in endpoints live under the admin-auth middleware so callers are already admins. Workplace assignment for admins is expressed via team-scoped RBAC and resolved with `User::getWorkplaceIdsByPermission()` (GlobalAdmin → all workplaces). The employee `workplace_users` pivot is NOT used for admin assignment.
- A permission is needed as the argument to `getWorkplaceIdsByPermission()` to represent "manages this workplace" for attendance; the design will select or introduce an appropriate permission (assigned to admin roles) — to be finalized in design.md.
- Company operates in a single timezone for deriving Work_Date.
- Mobile clients send one action (check-in or check-out) per request with GPS coordinates only (no image).
- Returning to a previously visited workplace on the same day is recorded as additional events on the same session; the earliest check-in and latest check-out define Worked_Hours.

## Success Criteria

### Definition of Done

- [ ] All acceptance criteria are met
- [ ] Managing admins can tap to check in/out at multiple workplaces per day (GPS-resolved), with concurrent open sessions allowed
- [ ] Attendance is stored in dedicated session and event tables, one session per (admin, workplace, day)
- [ ] Worked_Hours is the sum of valid, non-interrupted Attendance_Intervals per Requirement 8; an open/unresolved interval that is not the Current_Focus shows a missing-checkout warning and contributes no hours
- [ ] GlobalAdmin can review a flat attendance history list (one row per admin + workplace + day) with admin, workplace, check-in/out times, worked hours, and a read-only event-log detail
- [ ] GlobalAdmin can filter history by date range, admin, and workplace
- [ ] Non-functional requirements (security scoping, atomicity, uniqueness, i18n) are satisfied
- [ ] Backend and frontend test coverage added per project conventions; lint/prettier/typecheck/pint pass
