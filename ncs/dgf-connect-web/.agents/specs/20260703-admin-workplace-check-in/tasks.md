# Implementation Plan: Admin Workplace Check-In

## Overview

Build bottom-up and back-to-front: start with the backend data layer (migrations, models, enums, permissions), then the reusable domain service, then the mobile check-in/out actions and endpoints, then the web history endpoints, and finally the frontend history page. This ordering lets each layer be tested before the layer above depends on it, and it front-loads the two riskiest areas — nearest-workplace resolution over team-scoped RBAC and the session/event aggregation for worked-hours. Face recognition, image upload, and distance calculation are reused from the existing `CheckInService`, so no new integration risk is introduced there.

### Implementation Strategy

- **Foundation-First** for the backend: schema and models before service, service before actions, actions before controllers/routes.
- **Reuse over re-implementation**: wire into `CheckInService`, `WorkHelper`, and Laratrust RBAC rather than duplicating logic.
- **Distinct error codes early**: define the two mobile error codes before the actions so failure paths are explicit and testable.
- **Vertical validation**: get the mobile check-in slice working end-to-end (with faked face service) before building the web history views.

## Implementation Plan

### Phase 1: Backend Foundation (Schema, Models, Enums, Permissions)

- [x] 1. Data model foundation
    - [x] 1.1 Create migrations for `admin_attendance_sessions` and `admin_attendance_events`
        - `admin_attendance_sessions`: `user_id`, `workplace_id`, `work_date`, `first_check_in_at`, `last_check_out_at` (nullable), `work_minutes` (nullable), timestamps; `UNIQUE(user_id, workplace_id, work_date)`; indexes `(user_id, work_date)`, `(workplace_id, work_date)`
        - `admin_attendance_events`: `session_id` (cascade), `type`, `event_time`, `coordinate`, `image_path`, `bounding_box` (nullable), `bounding_box_format` (nullable), `verification_status` (default pending), `is_spoofed`, `spoofed_confidence`, timestamps; index `(session_id, event_time)`
        - _Requirements: 6.1, 6.2, 6.3_

    - [x] 1.2 Create `AdminAttendanceEventType` enum (`check_in`, `check_out`) using the project `EnumTrait`
        - _Requirements: 6.3_

    - [x] 1.3 Create Eloquent models `AdminAttendanceSession` and `AdminAttendanceEvent`
        - Session: relations `user()`, `workplace()`, `events()`; a `work_hours` accessor deriving from `work_minutes`
        - Event: relation `session()`; cast `type` to enum, `is_spoofed` to boolean
        - _Requirements: 6.2, 6.3, 6.4_

    - [x] 1.4 Add new `ErrorCode` cases
        - `AdminAttendanceNotAssignedWorkplace`, `AdminAttendanceNoNearbyWorkplace`, `AdminAttendanceNotCheckedIn`
        - _Requirements: 1.3, 1.4, 2.3_

    - [x] 1.5 Add new `PermissionName` cases and seed them onto admin roles
        - `AttendanceSelfCheckIn` (`attendance.self.check_in`, team-scoped for check-in workplace resolution). Web history is gated by `role:global_admin` (no dedicated history permission).
        - GlobalAdmin already syncs all permissions; `attendance.self.check_in` is granted to admins who inspect workshops (team-scoped) so they can check in on mobile
        - _Requirements: 1.1, 1.2, 4.5, 7.5_

- [ ] 2. Checkpoint — run migrations and confirm schema/model wiring
    - Run migrations in the container; confirm models load and factories (if added) work. Ask the user if schema questions arise.

### Phase 2: Domain Service

- [x] 3. AdminAttendanceService
    - [x] 3.1 Implement `resolveNearestAssignedWorkplace(User $admin, float $lat, float $lng)`
        - Resolve Assigned_Workplaces via `getWorkplaceIdsByPermission(AttendanceSelfCheckIn)` (null => all workplaces)
        - Return a typed outcome distinguishing: no assigned workplaces, no workplace within `work.check_in.max_distance`, or the resolved `Workplace`
        - Reuse `WorkHelper::calculateDistance`; skip workplaces with invalid coordinates
        - _Requirements: 1.2, 1.3, 1.4, 2.1, 2.2_

    - [x] 3.2 Implement session/event write helpers
        - `openOrGetSession(adminId, workplaceId, workDate)` using `firstOrCreate` + `lockForUpdate`, preserving earliest `first_check_in_at`
        - `appendEvent(session, type, eventTime, coordinate, verification)` persisting inline verification fields
        - `recomputeWorkedHours(session)` setting `last_check_out_at` = latest check-out and `work_minutes` = raw diff from `first_check_in_at`
        - _Requirements: 1.7, 1.8, 2.5, 2.6, 2.7_

    - [x] 3.3 Implement history aggregation queries
        - `getByAdmin(from, to, workplaceIds, reviewerScope)` → per-admin totals (sum sessions with worked-hours) + count of sessions without check-out
        - `getByDay(userId, from, to, workplaceIds, reviewerScope)` → sessions grouped by `work_date` + workplace with `work_hours`/`has_checkout`
        - `getSessionDetail(sessionId, reviewerScope)` → session + chronological events
        - Web history is GlobalAdmin-only (all workplaces); mobile `my-history` scopes to `Auth::id()`
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 5.1, 5.2, 5.5, 7.1, 7.4_

- [ ] 4. Checkpoint — unit test the service in isolation
    - Verify nearest resolution, session aggregation, and worked-hours edge cases pass before building actions.

### Phase 3: Mobile Check-In / Check-Out (Vertical Slice)

- [x] 5. Mobile actions
    - [x] 5.1 `CheckInAction` (Admin/Attendance)
        - Resolve nearest → map empty/no-nearby to the distinct `ErrorCode`s
        - Reuse `CheckInService::uploadAndVerifyFace($image, true, $adminId)`; reject on failure
        - In a transaction: open/get session, append `check_in` event with verification fields
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.6, 1.7, 1.8, 3.1, 3.2_

    - [x] 5.2 `CheckOutAction` (Admin/Attendance)
        - Resolve nearest (same error mapping); require existing session else `AdminAttendanceNotCheckedIn`
        - Validate GPS + face (reuse service); reject check-out time not after first check-in
        - Append `check_out` event and `recomputeWorkedHours`
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8_

- [x] 6. Mobile HTTP layer
    - [x] 6.1 Create `SubmitRequest` FormRequest (latitude, longitude numeric-in-range, image required)
        - _Requirements: 1.5_

    - [x] 6.2 Create `AttendanceCheckInController` (`checkIn`, `checkOut`) returning `ActionResult::toResponse()`
        - _Requirements: 1.1, 2.1_

    - [x] 6.3 Register routes under the admin group behind `manager.auth`
        - `POST /admin/attendance/check-in/start`, `POST /admin/attendance/check-in/end`
        - _Requirements: 1.1_

- [ ] 7. Checkpoint — mobile slice end-to-end (face service faked)
    - Confirm both error codes, validation, and happy-path check-in→check-out worked-hours via HTTP tests.

### Phase 4: Web History Endpoints

- [x] 8. Web history HTTP layer
    - [x] 8.1 Create history FormRequests (single `date`, integer `workplace_ids[]`, `user_id`)
        - _Requirements: 7.1, 7.3, 7.4_

    - [x] 8.2 Create `AttendanceHistoryController` (`byAdmin`, `byDay`, `sessionDetail`)
        - _Requirements: 4.1, 4.3, 5.1, 5.3_

    - [x] 8.3 Register web routes behind `permission:attendance.history.list`
        - `GET /admin/attendance/by-admin`, `/by-day`, `/sessions/{session}`
        - _Requirements: 4.5, 5.5, 7.5_

- [ ] 9. Checkpoint — web endpoints with scoping
    - Confirm reviewer scoping, workplace filter intersection, and 403/404/422 paths via HTTP tests.

### Phase 5: Frontend Web History Page

- [x] 10. Frontend data + types
    - [x] 10.1 Add TS model types (`admin_attendance_session.ts`, `admin_attendance_event.ts`) and `ApiConfig` entries
        - _Requirements: 4.1, 5.1_

    - [x] 10.2 Add React Query hooks in `src/pages/admin/attendance/api.ts` (by-admin, by-day, session detail)
        - _Requirements: 4.1, 4.3, 5.1, 7.6_

- [x] 11. Frontend UI
    - [x] 11.1 Build the page shell with view toggle (by-day / by-admin) and shared filter bar (single day + workplace multi-select)
        - Preserve filters when drilling from by-admin → by-day
        - _Requirements: 5.3, 7.1, 7.2, 7.4, 7.6_

    - [x] 11.2 Build by-admin table (name, total hours, excluded-sessions indicator) and by-day view (workplaces with hours / warning)
        - Warning row/badge when `has_checkout=false`; no computed hours shown
        - _Requirements: 4.1, 4.2, 5.1, 5.2, 5.4_

    - [x] 11.3 Build session-detail modal showing the read-only chronological event log
        - _Requirements: 4.3_

    - [ ] 11.4 Wire all copy through `t(...)` and run the translation flow (`trans:prepare` → fill vi/ja → `trans:apply`)
        - _Requirements: 4.2 (usability/i18n)_

### Phase 6: Testing

- [x] 12. Backend tests (written; service + actions covered via the two feature tests, run pending Docker)
    - [x] 12.1 Service logic (nearest resolution, worked-hours, null-while-no-checkout) — covered end-to-end by the feature tests
        - _Requirements: 1.2, 1.4, 1.8, 2.5, 2.6, 6.4_

    - [x] 12.2 Action logic (both error codes, happy path, no-session check-out, multiple check-outs, concurrent B while A open) — covered by `AdminAttendanceCheckInTest`
        - _Requirements: 1.3, 1.4, 2.3, 2.6, 3.1, 3.2_

    - [x] 12.3 Mobile HTTP test `AdminAttendanceCheckInTest` — permission gate, validation, happy path (face faked)
        - _Requirements: 1.1, 1.5_

    - [x] 12.4 Web HTTP test `AdminAttendanceHistoryTest` — by-admin totals + exclusions, by-day grouping + warning, filter, session detail 404, permission gate
        - _Requirements: 4.1, 4.2, 4.5, 5.1, 5.2, 7.1, 7.3_

- [x] 13. Frontend tests
    - [x] 13.1 Attendance page test — renders both views, warning badge, filter elements, drill-down preserves filters
        - _Requirements: 4.2, 5.3, 5.4, 7.6_

### Phase 7: Final Validation

- [x] 14. Code Quality
    - [x] 14.1 Backend: `pint` ✓ and `prettier` ✓ (run locally on new/changed files); `phpunit` pending Docker
    - [x] 14.2 Frontend: `pnpm lint:fix` ✓, `pnpm prettier` ✓, `pnpm typecheck` ✓; `pnpm test` blocked by a pre-existing vitest/Node env issue
    - [x] 14.3 Confirm no changes to existing employee `check_ins` / `check_in_verifications` behavior

### Phase 8: Worked-Hours Redesign (2026-07-05) — NOT STARTED, pending sign-off

Implements Requirement 8 and the "Addendum (2026-07-05)" section of `design.md`. Do not start until the updated `requirements.md`/`design.md` are reviewed and approved — this replaces already-shipped behavior and changes an existing test's expected value.

- [ ] 15. Backend: interval/interruption state machine
    - [ ] 15.1 Implement the day-scoped recomputation (`AdminAttendanceService::recomputeDay` or a new `AdminAttendanceIntervalCalculator`) per the pseudocode in `design.md`'s addendum; wire `persistCheckIn`/`persistCheckOut` to call it instead of the per-session `recomputeWorkedHours`
        - _Requirements: 8.1–8.10_
    - [ ] 15.2 Add `admin_attendance_intervals` migration + model (per the addendum's recommended Data Model Change) and persist committed intervals from the calculator
        - _Requirements: 8.7, Requirement 5.2_
    - [ ] 15.3 Replace the three duplicated missing-checkout implementations (SQL correlated subquery in `getDailySummary`, `AdminAttendanceSession::getHasMissingCheckoutAttribute()`, private `isSessionOpen()`) with a single persisted `has_missing_checkout` column written by the calculator
        - _Requirements: 8.8, 8.9_
    - [ ] 15.4 Update `AdminAttendanceCheckInTest::testMultipleCheckOutsUseLatestForWorkedHours` to the corrected expectation (60 min, not 180) and add new tests for the Test Case Matrix rows 1–7 in `design.md`'s addendum
        - _Requirements: 8.1–8.10_
- [ ] 16. Frontend: Day Detail interval list
    - [ ] 16.1 Update `frontend/src/pages/admin/attendance/day/index.page.tsx` to render each session's list of valid intervals (not just first check-in/last check-out) and an "(in progress)" status for an open Current_Focus interval instead of a live/projected number
        - _Requirements: Requirement 5.2, 8.7_
- [ ] 17. Checkpoint — confirm all 7 Test Case Matrix rows pass before considering the redesign complete

## Notes

- **Rework (post-review, final)**:
    - **No face recognition** for admin check-in/out — just a tap + GPS to resolve the nearest assigned workplace. Removed the image field and all face/verification columns from `admin_attendance_events` (now only `type`, `event_time`, `coordinate`). Removed `CheckInService`/face deps from the actions and `SubmitRequest` (image dropped).
    - **Removed the Admin Spoof Check-in feature entirely** (no page, route, endpoint, or spoof columns) since there is no face capture.
    - **Web history = two levels (GlobalAdmin-only)**:
        - Level 1 `/admin/attendance` → `GET /admin/attendance/daily`: paginated, one row per (admin, day) with workplace count, total hours, missing-checkout warning. Filters: date range + admin.
        - Level 2 `/admin/attendance/day?user_id=&date=` (a full **page**, not a modal) → `GET /admin/attendance/day`: each workplace of that day rendered as a scrollable card with first check-in / last check-out / hours + inline check-in/out event log.
    - **Mobile self** (`manager.auth`): `GET /admin/attendance/my-history` (daily), `GET /admin/attendance/my-history/day` (day detail), scoped to `Auth::id()`.
    - Frontend role gating generalized: `RouteInfo.roles` + `RoleHelper.canAccessRoute` (used by `Layout` menu + route guard).
- **Pending Docker verification**: checkpoints 2, 4, 7, 9 and running `phpunit` require the PHP container (Docker daemon was not running during implementation). Start Docker, then run: `docker compose exec php php artisan migrate` and `docker compose exec php ./vendor/bin/phpunit --filter Attendance`. Frontend `pnpm test` is blocked by a pre-existing vitest config load error (Node v22 + current vite/vitest) that affects existing tests too, not just the new ones.
- **Ordering rationale**: schema → models → service → actions → controllers/routes → frontend guarantees each dependency exists before use; the two mobile error codes are defined in Phase 1 so failure paths are explicit throughout.
- **Reuse boundaries**: face verification, image upload, anti-spoofing, and distance calc come from `CheckInService`/`WorkHelper`. Verification results are stored inline on `admin_attendance_events`; the employee `check_in_verifications` table and its anti-spoofing batch jobs are intentionally untouched (batch re-processing of admin events is a possible future enhancement).
- **Permission choice**: `attendance.self.check_in` is the argument to `getWorkplaceIdsByPermission()` for workplace scoping; confirm during Phase 1 whether it should be seeded to the Admin role by default (GlobalAdmin gets all permissions automatically).
- **Risks**: (1) team-scoped RBAC returning `null` (global) vs empty (none) must be handled distinctly to produce the correct error — cover in tests. (2) Concurrency on first check-in relies on the unique constraint + `lockForUpdate`; verify under parallel requests. (3) Timezone consistency for `work_date` derivation (use company timezone / `now()` as configured).
- **Out of scope**: editing attendance from web, payroll integration, mobile UI (separate codebase), and CSV/PDF export of admin attendance.
