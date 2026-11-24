# Implementation Plan: Recent Absence Warning

## Overview

Backend-first, then frontend. Build the shared `AbsenceService` and its config first, write its unit tests immediately (boundary-heavy), then plug it into the three call sites (mobile registration, add-user search, shift detail). After backend is verified, build the small `AbsenceWarning` component and wire it into the assignment table (covers all four shift-detail instances) and the add-user modal. Translation flow runs near the end so we only translate once. Final phase: feature tests, lint, prettier, typecheck, pint.

### Implementation Strategy

- **Foundation-first** for the Service and config so all consumers have a stable interface
- **Single shared frontend component** (`AbsenceWarning`) used in two places — write once, use twice
- **Translation flow runs after all `t(...)` strings are introduced** (per `CLAUDE.md`)

## Implementation Plan

### Phase 1: Backend foundation

- [x] 1. Configurable absence window
    - [x] 1.1 Create `backend/config/absence.php` with `'window_days' => (int) env('ABSENCE_RECENT_WINDOW_DAYS', 180)`
        - _Requirements: TR2.1, TR2.2_
    - [x] 1.2 Add `ABSENCE_RECENT_WINDOW_DAYS=180` to `backend/.env.example`
        - _Requirements: TR2.1_

- [x] 2. Implement `AbsenceService`
    - [x] 2.1 Create `backend/app/Services/Common/AbsenceService.php` with `getUserAbsenceCount(int|array $userIds): int|array`
        - Single Eloquent query: `shift_users` JOIN `shifts` LEFT JOIN `check_ins` LEFT JOIN `shift_absent_notices`, filtered by status = approved, `shifts.date <= today.subDays(2)`, `shifts.date >= today.subDays(window_days)`, `check_ins.id IS NULL`, `shift_absent_notices.id IS NULL`, GROUP BY `user_id`
        - Resolve window from `config('absence.window_days')`; defensive fallback to 180 on non-positive value
        - Zero-fill the result so every requested id is present in the array form
        - Return `int` for single-id input, `array<int,int>` for list input
        - _Requirements: TR1.1, TR1.2, TR1.3, TR1.4, TR2.1, TR2.2_

- [x] 3. Checkpoint — Service contract verified by unit tests (Phase 4 writes them; for now, smoke-test the query in `tinker` or a temporary feature test)
    - Confirm SQL filter values match the requirement (boundary days, status filter, joins)

### Phase 2: Backend integration

- [x] 4. Mobile shift registration response
    - [x] 4.1 Inject `AbsenceService` into `RegisterShiftAction` (`backend/app/Actions/User/Schedule/RegisterShiftAction.php`)
        - On the success path only, call `getUserAbsenceCount` with the registering user's id and merge `absence_count` into the response data alongside the existing `ShiftUser` payload
        - Do **not** include the field on any error path (keep current `ActionResult(error: ..., status: 4xx)` shape)
        - _Requirements: 1.1, 1.2, 1.3, 1.4, TR1.1_

- [x] 5. Admin add-user search
    - [x] 5.1 Extend `ShiftService::searchUserForShift` (`backend/app/Services/Manage/ShiftService.php`) to call `AbsenceService::getUserAbsenceCount` once with the result page's user ids and attach `absence_count` to each user record before returning
        - _Requirements: 3.1, TR1.1, TR1.2_

- [x] 6. Shift detail user lists (every group)
    - [x] 6.1 In `ShiftService::getShiftDetail` (or the child loader that assembles the per-group user lists), collect the **union of distinct user ids** across pending, rejected, and per-position groups
        - _Requirements: 2.1, 2.5_
    - [x] 6.2 Call `AbsenceService::getUserAbsenceCount` once with that union and attach `absence_count` to every user record across every group
        - Same user appearing in multiple groups receives the same count
        - _Requirements: 2.1, 2.5, TR1.1, TR1.2_

- [x] 7. Checkpoint — verify no N+1 on shift detail and add-user search via `DB::enableQueryLog()` in a quick local test before moving to frontend

### Phase 3: Frontend (admin web)

- [x] 8. Type extension
    - [x] 8.1 In `frontend/src/types/models/user.d.ts`, add a narrow extension interface `UserWithAbsenceCount extends User { absence_count: number }` (mirrors the existing `UserWithInfo` pattern). Do **not** add `absence_count` to the base `User` interface — it is a derived field attached only by specific endpoints, not a property of the user model.
        - For the shift detail / assignment table flow, type the `ShiftUser.user` reference as `UserWithAbsenceCount` (combine with `UserWithInfo` if `info` is also loaded at that call site).
        - For the add-user search flow, the modal already uses a flat local result type — declare `absence_count: number` directly on that local type rather than reusing `UserWithAbsenceCount`.
        - _Requirements: 2.1, 3.1_

- [x] 9. Reusable warning component
    - [x] 9.1 Create `frontend/src/pages/manage/shift/partials/absence_warning.tsx`
        - Props: `{ absenceCount: number }`; renders `null` when `absenceCount < 1`
        - Renders `LuTriangleAlert` wrapped by Mantine `Tooltip` with `t('{{count}} recent absences', { count })` (uses i18next plural keys `_one`/`_other`)
        - Use the same yellow/orange color token as the existing paper-warning style; do not add new tokens
        - _Requirements: 2.2, 2.3, 2.4, 3.2, 3.3, 3.4, TR2.3_

- [x] 10. Assignment table integration (covers all four shift-detail instances)
    - [x] 10.1 In `frontend/src/pages/manage/shift/partials/assignment_table.tsx`, render `<AbsenceWarning absenceCount={user.absence_count} />` adjacent to the user name in the row cell (no `?? 0` fallback — the user reference is typed as `UserWithAbsenceCount`, so the field is guaranteed present)
        - Type cascade applied: `ShiftUserWithRelations.user`, `GetShiftDetailResponse.fixed_schedule_users`, `useShiftDetail.users` state, `categorizeAllUsers`, `Props.users`, and `UserRow.user` all carry `UserWithAbsenceCount`.
        - Backend `ImportCsvShiftAction` updated to inject `AbsenceService` and attach `absence_count` to `users_in_workplace` so the CSV-import path keeps the type contract.
        - _Requirements: 2.2, 2.3, 2.4_

- [x] 11. Add-user modal integration
    - [x] 11.1 In `frontend/src/pages/manage/shift/partials/update/add_user_modal.tsx`, render `<AbsenceWarning absenceCount={user.absence_count} />` next to the user name in each search-result row (the local search-result type declares `absence_count: number` directly, so no fallback is needed)
        - Search results, selected users, and the `onAddUser` prop are typed as `UserWithAbsenceCount[]`; `handleAddUsers` in `useShiftDetail` updated accordingly.
        - _Requirements: 3.2, 3.3, 3.4_

### Phase 4: Tests

- [x] 12. Unit tests — `AbsenceService` (`backend/tests/Unit/Services/Common/AbsenceServiceTest.php`)
    - [x] 12.1 `testReturnsZeroForUserWithNoShifts`
        - _Requirements: TR1.4_
    - [x] 12.2 `testCountsApprovedPastShiftWithNoCheckInAndNoNotice`
        - _Requirements: TR1.1_
    - [x] 12.3 `testExcludesShiftWithCheckIn`
        - _Requirements: 1.3, 2.1, 3.1_
    - [x] 12.4 `testExcludesShiftWithAbsentNotice`
        - _Requirements: 1.3, 2.1, 3.1_
    - [x] 12.5 `testExcludesPendingAndRejectedShifts`
        - _Requirements: TR1.1_
    - [x] 12.6 `testExcludesShiftInsideTwoDayGracePeriod` (date = yesterday)
        - _Requirements: 1.3_
    - [x] 12.7 `testExcludesShiftOlderThanWindow`
        - _Requirements: TR2.1_
    - [x] 12.8 `testIncludesShiftAtBoundaryDates` (date = today − 2 days; date = today − window_days)
        - _Requirements: 1.3, TR2.1_
    - [x] 12.9 `testHonorsCustomWindowConfigOverride` (set `config(['absence.window_days' => 30])`)
        - _Requirements: TR2.1, TR2.4_
    - [x] 12.10 `testFallsBackToDefaultWhenConfigIsInvalid` (e.g., `null`, `0`, `-1`)
        - _Requirements: TR2.2_
    - [x] 12.11 `testBatchedCallReturnsZeroFilledArrayForEveryRequestedId`
        - _Requirements: TR1.2, TR1.3_
    - [x] 12.12 `testBatchedCallExecutesSingleQuery` (assert `DB::getQueryLog()` length)
        - _Requirements: TR1.2_

- [x] 13. Feature tests — mobile registration (`backend/tests/Feature/User/Schedule/RegisterShiftTest.php`)
    - [x] 13.1 `testSuccessfulRegistrationResponseIncludesAbsenceCount`
        - _Requirements: 1.1_
    - [x] 13.2 `testRegistrationFailureResponseOmitsAbsenceCount`
        - _Requirements: 1.2_
    - [x] 13.3 `testJustRegisteredShiftDoesNotContributeToAbsenceCount`
        - _Requirements: 1.4_

- [x] 14. Feature tests — shift detail (`backend/tests/Feature/Manage/Shift/GetShiftDetailTest.php`)
    - [x] 14.1 `testEveryUserInEveryGroupHasAbsenceCount` (pending, rejected, position groups)
        - _Requirements: 2.1_
    - [x] 14.2 `testSameUserAcrossGroupsShowsSameCount`
        - _Requirements: 2.1, 2.5_
    - [x] 14.3 `testEndpointMakesSingleAbsenceQueryRegardlessOfGroupCount` (query-log assertion)
        - _Requirements: 2.5_

- [x] 15. Feature tests — add-user search (`backend/tests/Feature/Manage/Shift/SearchUserTest.php`)
    - [x] 15.1 `testSearchResultIncludesAbsenceCountPerUser`
        - _Requirements: 3.1_
    - [x] 15.2 `testSearchUsesSingleAbsenceQueryRegardlessOfResultCount`
        - _Requirements: TR1.2_

### Phase 5: Translation

- [x] 16. Translate frontend strings
    - [x] 16.1 Run `pnpm trans:prepare` in `./frontend` (after all `t(...)` calls in Phase 3 are committed)
    - [x] 16.2 Fill `vi` and `ja` translations for `{{count}} recent absences` (preserving `_one` / `_other` plural keys and the `{{count}}` placeholder)
    - [x] 16.3 Run `pnpm trans:apply`
    - [x] 16.4 Run `pnpm trans:cleanup`

### Phase 6: Final validation

- [x] 17. Manual UI verification (admin web)
    - [x] 17.1 Open a shift detail page where at least one user has a known recent absence; verify the warning icon + tooltip appears in pending, rejected, and a position group
    - [x] 17.2 Open the add-user modal; verify the warning appears in matching search results
    - [x] 17.3 Hover the icon and confirm the tooltip shows count only — no duration text
    - [x] 17.4 Verify a user with `absence_count = 0` shows no icon
    - [x] 17.5 Verify pluralization for 1 vs. ≥ 2 absences in `en`, `vi`, `ja`

- [x] 18. Code Quality
    - [x] 18.1 Frontend: `pnpm lint:fix`
    - [x] 18.2 Frontend: `pnpm prettier`
    - [x] 18.3 Frontend: `pnpm typecheck`
    - [x] 18.4 Backend: `npm run prettier`
    - [x] 18.5 Backend: `npm run pint`
    - [x] 18.6 Backend: `docker compose exec php ./vendor/bin/phpunit` — full suite green

## Notes

- **Backward compatibility**: the registration response merges `absence_count` into the existing `data` payload. Coordinate with the mobile team before merging — the additive field is non-breaking, but visibility helps them schedule the popup work.
- **Filter semantics**: the absence query filters by `shifts.date`, not a derived `end_time`. Boundary tests in 12.6 / 12.8 must use the same `Carbon::today()` convention as the production Service to avoid timezone-induced flakiness.
- **No backfill, no schema changes**: existing `is_absent` and `shift_absent_notices` rows are not touched. If a future task wants to reconcile the new derived count with the existing `is_absent` flag, that is a separate spec.
- **Risk: ambiguous `data` shape on registration**. The current `RegisterShiftAction` returns the bare `ShiftUser` model in `data`. The integration in 4.1 must use a shape that preserves existing fields and adds `absence_count` at the same level (not nesting under another key) to keep the mobile contract additive.
