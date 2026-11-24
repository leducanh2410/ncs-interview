# Requirements Document

## Introduction

Managing_Admins who check in for a night shift and do not check out before local midnight currently lose the ability to check out at all: the mobile app decides which button to show by querying attendance history for a single calendar-day bucket (`Work_Date`), and an Attendance_Session opened the previous evening is invisible once the bucket rolls over. Separately, the backend's existing session-resolution window (a flat rolling 24-hour lookback in `AdminAttendanceService::findOpenSession`) has no awareness of what counts as a legitimate overnight shift, so if the button-state bug is fixed without also tightening this window, a very late or multi-day-delayed checkout could still be silently credited in full to the original check-in's `Work_Date`. This feature builds the backend foundation for the fix: a calendar-day-independent way to ask "does this admin currently have an open, still-valid session here", and a precise Valid_Checkout_Window so only genuine overnight continuations are creditable, with any stale session cleanly resolved (invalidated, logged, and surfaced in the API response) rather than silently mishandled either way (lost, or over-credited). Mobile consumption of this API (button state, warning display) is tracked as a separate follow-up spec in the `dgf-connect-app-admin` repository.

### Feature Summary

Give the backend a calendar-day-independent way to report a Managing_Admin's currently open Attendance_Session, cap how late a checkout may still be credited as worked time, and cleanly resolve (and report) any stale, uncredited check-in — laying the groundwork for the mobile night-shift checkout fix.

### Business Value

Night-shift leaders currently cannot close out their attendance after midnight, which both blocks a core mobile workflow and creates unresolved "missing checkout" records that never self-correct (per the existing system's documented behavior, a dangling open session is never automatically re-evaluated). Left unaddressed, this also creates a latent payroll-integrity gap: once the button-state bug is fixed by naive means, nothing would stop an arbitrarily delayed checkout from crediting excessive hours to a single day. This feature closes both gaps together at the API level, protecting attendance data integrity and giving the mobile team a correct contract to build the restored night-shift workflow against.

### Scope

**In scope:**
- A new mobile-facing API endpoint that reports the admin's current open, still-valid Attendance_Session at a given workplace (plus its Checkout_Deadline), and separately reports an un-invalidated Stale_Session when one exists — independent of calendar-day bucketing.
- A precise Valid_Checkout_Window rule (evening check-in cutoff, next-morning grace deadline, same-day-only for daytime check-ins) replacing the current unconditional 24-hour lookback used to resolve which session a checkout closes.
- Lazy (request-time) detection and resolution of Stale_Sessions: invalidation, audit log preservation, and automatic creation of a fresh check-in when a checkout request arrives too late.
- A structured warning included in the API response whenever a Stale_Session is auto-resolved.
- Web (GlobalAdmin) reporting screens correctly distinguishing an invalidated session from an ordinary missing-checkout.

**Out of scope:**
- Mobile app changes (button state, warning display) — tracked separately as a follow-up spec in `dgf-connect-app-admin`, to be built once this API contract lands.
- Any scheduled/background job that proactively invalidates sessions or notifies admins without a triggering check-in/check-out request.
- Changes to the existing interval/interruption Worked_Hours state machine (`recomputeDay`, Checkpoint_Commit, Current_Focus) beyond excluding invalidated sessions from it.
- Changes to GPS/proximity resolution (Assigned_Workplaces, Nearest_Workplace, Proximity_Threshold).
- Changes to the existing daily-summary and day-detail history endpoints/screens beyond correctly reflecting invalidated sessions.

## Glossary

| Term | Definition |
|------|------------|
| Managing_Admin | A user authenticated through the admin/manager gate who checks in/out of one or more assigned workplaces. Same definition as the existing Admin Workplace Check-In feature. |
| Attendance_Session | A single record per (Managing_Admin, Workplace, Work_Date) aggregating that admin's presence at that workplace on that day. Existing entity. |
| Attendance_Event | A single check-in or check-out action with timestamp and GPS coordinate, belonging to one Attendance_Session. Existing entity, append-only; never deleted or edited by this feature. |
| Work_Date | The calendar date (Asia/Tokyo) an Attendance_Session belongs to, fixed at the session's first check-in. Existing concept. |
| Worked_Hours | The sum of an Attendance_Session's valid, closed Attendance_Intervals. Existing concept (Requirement 8 of Admin Workplace Check-In). |
| Nearest_Workplace | The Assigned_Workplace closest to the admin's current GPS position, within the Proximity_Threshold. Existing concept. |
| Valid_Checkout_Window | The time range during which an open Attendance_Session may still be legitimately closed and credited. Begins at the check-in that is currently keeping the session open — its *latest* check-in event, not necessarily `first_check_in_at` (a same-day re-check-in after an earlier checkout, per Requirement 8's Checkpoint_Commit, opens its own window). If that check-in occurred at or after 20:00, the window ends at 08:00 on the following calendar day. Otherwise, the window ends at the close of that check-in's own calendar day (00:00 the next calendar day). Evaluated independently per (Managing_Admin, Workplace) pair. |
| Current_Session | The admin's open Attendance_Session at a specific workplace whose Valid_Checkout_Window has not yet elapsed. At most one Current_Session can exist per (Managing_Admin, Workplace) pair at any moment. |
| Checkout_Deadline | The absolute timestamp at which an Attendance_Session's Valid_Checkout_Window closes, derived from its check-in time. Computed and returned by the backend so clients never re-implement the 20:00/08:00 rule. |
| Next_Action | `'check_in'` or `'check_out'`, returned by Current_Session_Endpoint alongside `data`/`stale_session`, stating directly which action a client's button should present. Equivalent to "is `data` non-null", but stated explicitly so no client re-derives the decision the backend already made. |
| Is_Overnight | A boolean returned on every serialized Attendance_Session, true exactly when its Valid_Checkout_Window took the 08:00-next-day branch. Lets a client show an overnight reminder without knowing the 20:00 cutoff or comparing dates itself. |
| Stale_Session | An open Attendance_Session (last event is a check-in with no later check-out) whose Valid_Checkout_Window has elapsed, and which has not yet been invalidated. No longer eligible to be closed or credited. |
| Stale_Session_Warning | A payload returned by the check-in or check-out API when a Stale_Session is detected and auto-resolved, describing the invalidated session and instructing the admin to contact an administrator if it looks wrong. |
| Current_Session_Endpoint | The new read-only API endpoint (`GET /admin/attendance/current-session`) that reports whether a Current_Session exists at a given workplace, independent of calendar-day bucketing. The future mobile Check In/Check Out button will consume it (out of scope here). |

## Requirements

### Requirement 1: Calendar-Day-Independent Session Lookup

**User Story:** As the mobile client, I want to query whether a Managing_Admin currently has an open, valid Attendance_Session at a workplace — and whether a previous one just expired — independent of calendar-day bucketing, so that a future UI can reliably decide between Check In and Check Out without losing state across midnight, and can explain an expired session rather than silently resetting.

#### Acceptance Criteria

1. WHEN Current_Session_Endpoint is called for a given workplace THEN the system SHALL determine the result from whether a Current_Session exists at that workplace, not from a single calendar-day history query.
2. IF a Current_Session exists at the requested workplace THEN Current_Session_Endpoint SHALL return that Attendance_Session together with its Checkout_Deadline.
3. IF no Current_Session exists at the requested workplace THEN Current_Session_Endpoint SHALL return no current session.
4. IF an un-invalidated Stale_Session exists at the requested workplace THEN Current_Session_Endpoint SHALL additionally report that Stale_Session's identity and check-in time, so a client can distinguish "never checked in" from "the previous session expired".
5. WHEN Current_Session_Endpoint is called THEN it SHALL NOT mutate any session state — in particular it SHALL NOT invalidate a Stale_Session it reports.
6. WHERE an admin is assigned to multiple workplaces THEN the lookup SHALL be scoped to the requested workplace only, independently of any other workplace's session state.
7. WHEN Current_Session_Endpoint responds THEN it SHALL include Next_Action, so a client presents Check In/Check Out by reading a field rather than by inferring it from whether a Current_Session was returned.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 2 (Valid_Checkout_Window defines what counts as a Current_Session vs. Stale_Session, and supplies the Checkout_Deadline).

### Requirement 2: Valid_Checkout_Window Definition

**User Story:** As a PM responsible for attendance data integrity, I want a precise cutoff for how late a checkout can still be credited, so that Worked_Hours cannot be inflated by an arbitrarily delayed checkout.

#### Acceptance Criteria

1. IF the check-in currently keeping an Attendance_Session open occurred at or after 20:00 THEN the Valid_Checkout_Window SHALL remain open until 08:00 on the following calendar day. This check-in is the session's *latest* check-in event — for a session reopened by a same-day re-check-in after an earlier checkout (Requirement 8's Checkpoint_Commit), it is that re-check-in, not the session's `first_check_in_at`.
2. IF the check-in currently keeping an Attendance_Session open occurred before 20:00 THEN the Valid_Checkout_Window SHALL close at 00:00 the following calendar day (i.e., checkout must occur the same calendar day as that check-in).
3. WHEN a checkout request arrives while the Valid_Checkout_Window is still open THEN the system SHALL credit Worked_Hours to the Attendance_Session's original Work_Date exactly as it does today (no change to `recomputeDay`) — the original Work_Date, not the calendar date the checkout happened to arrive on.
4. WHEN a checkout request arrives after the Valid_Checkout_Window has elapsed THEN the system SHALL NOT credit any Worked_Hours for that session's open interval.
5. WHERE an admin holds open sessions at multiple workplaces simultaneously THEN each workplace's Valid_Checkout_Window SHALL be evaluated independently, per (Managing_Admin, Workplace) pair.
6. WHEN an Attendance_Session is serialized in any API response THEN the system SHALL include its Checkout_Deadline as an absolute timestamp, so that no client re-implements the 20:00/08:00 rule and a future change to the rule requires no client release.
7. WHEN an Attendance_Session is serialized in any API response THEN the system SHALL include Is_Overnight, so that no client infers overnight status by comparing Checkout_Deadline against a check-in date, or needs to know 20:00 is the cutoff at all.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None (foundational rule consumed by Requirements 1, 3).

### Requirement 3: Stale_Session Resolution and Warning

**User Story:** As a Managing_Admin, I want the system to clearly tell me when my previous check-in was auto-invalidated, so I can contact an administrator if it doesn't look right.

#### Acceptance Criteria

1. WHEN a check-in or check-out request is received for an (admin, workplace) pair that has an existing Stale_Session THEN the system SHALL mark that session invalidated, preserve its Attendance_Event log unchanged, and exclude it from Worked_Hours computation.
2. IF the triggering request was a checkout THEN the system SHALL, instead of returning a "not checked in" error, create a new Attendance_Session check-in for the current Work_Date at the same workplace, using the original request's timestamp and coordinate.
3. IF the triggering request was a check-in THEN the system SHALL proceed with that check-in normally for the current Work_Date after invalidating the Stale_Session.
4. WHEN a Stale_Session is invalidated as part of handling a request THEN the API response SHALL include a Stale_Session_Warning identifying the invalidated session's check-in time, structured so a client can render it (message text/localization is a client concern, out of scope here).
5. IF no Stale_Session exists for the (admin, workplace) pair at request time THEN the request SHALL proceed exactly as it does today, with no warning returned.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2 (defines when a session is Stale).

### Requirement 4: Reporting Consistency

**User Story:** As a GlobalAdmin reviewing attendance history, I want invalidated sessions to be visibly distinguishable from ordinary missing-checkout warnings — and never invisible — so reports remain trustworthy.

#### Acceptance Criteria

1. WHERE Attendance_Day_Detail displays an invalidated Attendance_Session THEN it SHALL indicate its invalidated status rather than presenting it as an ordinary unresolved missing-checkout.
2. WHEN computing the missing-checkout count for the daily summary THEN the system SHALL NOT double-count an invalidated Attendance_Session as both invalidated and missing-checkout.
3. WHEN a daily summary row covers a day containing at least one invalidated Attendance_Session THEN the row SHALL expose that fact as its own flag, so excluding it from the missing-checkout count (AC2) does not make the day appear anomaly-free.
4. WHERE a daily summary row is displayed THEN a day whose only anomaly is an invalidated session SHALL remain visibly flagged, distinctly from a day with an unresolved missing checkout.
5. IF an Attendance_Session was validly closed within its Valid_Checkout_Window THEN existing daily-summary and day-detail behavior SHALL be unchanged.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 2, Requirement 3.
- **Note**: AC3/AC4 exist because AC2 alone would be a visibility regression — before this feature an unclosed night session surfaced as a missing checkout; after it, invalidation would remove that badge and leave the day looking clean despite lost hours.

## Non-Functional Requirements

### Performance Requirements
- WHEN Current_Session_Endpoint is called THEN it SHALL respond within the same latency envelope as the existing check-in/check-out endpoints, reusing existing indexed lookups rather than introducing new full-table scans.

### Security Requirements
- WHEN Current_Session_Endpoint is called THEN it SHALL require the same authentication and permission gate (`manager.auth`, `ManagerAttendanceCheckIn`) as the existing check-in/check-out endpoints, and SHALL be scoped to the authenticated admin's own sessions.

### Usability Requirements
- WHEN a Stale_Session_Warning is included in a response THEN it SHALL carry the invalidated session's check-in date/time in a machine-parseable, human-readable-when-formatted form (e.g. ISO 8601), sufficient for a client to build a message directing the admin to contact an administrator if it appears incorrect.

### Reliability Requirements
- WHILE concurrent check-in/check-out requests race for the same (admin, workplace) THEN the system SHALL serialize Stale_Session invalidation and new-session creation using the existing row-locking mechanism, preventing double-invalidation or lost updates.

## Constraints and Assumptions

### Technical Constraints
- All date/time boundaries (20:00 cutoff, 08:00 deadline, Work_Date) are evaluated in Asia/Tokyo time, consistent with the existing Work_Date derivation.
- No new scheduled or background job is introduced; Stale_Session detection is evaluated lazily, only when a check-in or check-out request arrives for that (admin, workplace) pair.
- GPS/proximity resolution (Assigned_Workplaces, Nearest_Workplace, Proximity_Threshold) is unchanged.

### Assumptions
- A Managing_Admin may have at most one Current_Session per Workplace at any moment; a Stale_Session and a newly created session for today's Work_Date at the same workplace are expected to coexist as separate historical rows.
- Admins are not expected to interact with the API between checking in and the Valid_Checkout_Window elapsing; the fix must work correctly even if no request arrives for that (admin, workplace) pair until well after the window has passed.
- Mobile consumption of Current_Session_Endpoint and the Stale_Session_Warning (button state, banner UI) is a separate, follow-up piece of work in `dgf-connect-app-admin`, not delivered by this spec.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] Current_Session_Endpoint correctly reports an open session for a night-shift check-in (at or after 20:00) at any point up to 08:00 the next morning, regardless of when it is queried.
- [ ] A checkout attempted after the Valid_Checkout_Window never credits Worked_Hours for the stale interval, and the API response includes a Stale_Session_Warning.
- [ ] Existing on-time check-in/check-out flows and attendance reporting show no behavior change.
- [ ] Testing criteria are passed
