# Implementation Plan: Fixed Schedule Change Request (Backend & Admin Web)

## Overview

Build bottom-up: schema and models first (including the retrofitted workplace-scoping on the existing `FixedSchedule`), then the two new backend domains (Change Config, Change Request) as isolated vertical slices with their own tests, then `is_fixed` exposure on existing endpoints, then the three admin web pages wired to that API, and finally full-suite validation. The employee-facing (`/user/...`) endpoints are built in this repo even though they're consumed by the mobile app in a separate repo — the mobile implementation plan lives in `dgf_connect_app/.agents/specs/20260715-fixed-schedule-change-request/tasks.md` and depends on the API work in Phase 4 here being deployed first.

### Implementation Strategy
- Land the workplace-scoping fix (Requirement 2) early and in isolation — it touches existing, already-shipped behavior (`FixedScheduleService`), so it should be verifiable on its own before new features are layered on top.
- Treat Change Config and Change Request as separate vertical slices (migration → model → Action → controller/route → tests) so each is independently testable before the admin web pages exist.
- Build admin web pages last, after the API they call is fully implemented and tested — avoids building UI against a moving API contract.
- Every phase that adds business logic ends with its own unit/feature tests before moving on, per the project's "add good test coverage for business logic and new endpoints — permissions, validation, happy path" rule; there is no separate deferred "testing phase" at the end (see Notes).

## Implementation Plan

### Phase 1: Foundation — Schema, Models, Permissions

- [x] 1. Migrations
    - [x] 1.1 Create `fixed_schedule_change_configs` table (`workplace_id` unique FK, `is_enabled` bool default false, `deadline` nullable date, timestamps)
        - _Requirements: 4.5_
    - [x] 1.2 Create `fixed_schedule_change_requests` header table (`fixed_schedule_id` FK, `workplace_id` FK denormalized, `status` default `pending`, `processed_by_id` nullable FK, `processed_at` nullable, timestamps, index `(fixed_schedule_id, status)`, index `(workplace_id, status)`)
        - _Requirements: 6.2, 6.5_
    - [x] 1.3 Create `fixed_schedule_change_request_days` detail table (`fixed_schedule_change_request_id` FK, `day` tinyint, `current_workplace_shift_id` nullable FK, `requested_workplace_shift_id` nullable FK, timestamps, unique `(fixed_schedule_change_request_id, day)`)
        - _Requirements: 6.2_

- [x] 2. Models & Enums
    - [x] 2.1 `App\Models\FixedScheduleChangeConfig` (`belongsTo Workplace`, `use FiltersByWorkplace;`)
        - _Requirements: 4, 2.5_
    - [x] 2.2 `App\Models\FixedScheduleChangeRequest` (header: `belongsTo FixedSchedule`, `belongsTo Workplace`, `belongsTo User as processedBy`, `hasMany FixedScheduleChangeRequestDay`, `use FiltersByWorkplace;`) and `App\Models\FixedScheduleChangeRequestDay` (`belongsTo FixedScheduleChangeRequest`, `belongsTo WorkplaceShift as currentWorkplaceShift`, `belongsTo WorkplaceShift as requestedWorkplaceShift`)
        - _Requirements: 6.2, 7.3, 8.1_
    - [x] 2.3 `App\Enums\FixedScheduleChangeRequestStatus` (`Pending`, `Approved`, `Rejected`)
        - _Requirements: 7.5_
    - [x] 2.4 Add `use FiltersByWorkplace;` to `App\Models\FixedSchedule` (it already has `workplace_id`)
        - _Requirements: 2.1, 2.2_

- [x] 3. Permissions
    - [x] 3.1 Add `PermissionName` entries: `FixedScheduleChangeConfigList`, `FixedScheduleChangeConfigEdit`, `FixedScheduleChangeRequestList`, `FixedScheduleChangeRequestProcess` (existing `FixedScheduleList/Create/Edit/Delete` stay, just newly enforced)
        - _Requirements: 1.3, 2.6, 4.6_
    - [x] 3.2 Seed the new permissions (Laratrust seeder, matching how the existing `fixed_schedule.*` set is seeded)
        - _Requirements: 1.3_

- [x] 4. Checkpoint - migration + model foundation
    - Ran `migrate:fresh` against a fresh local DB. Found and fixed a real bug: `2026_07_16_000003_create_fixed_schedule_change_request_days_table.php`'s auto-generated FK/unique constraint names (e.g. `fixed_schedule_change_request_days_fixed_schedule_change_request_id_foreign`) exceeded MySQL's 64-char identifier limit; gave them explicit short names (`fscrd_*`). All migrations now run clean; models/relationships verified via the full test suite.

### Phase 2: Fix Workplace Scoping on the Existing Fixed Schedule List

- [x] 5. Retrofit `FixedScheduleService` with workplace scoping
    - [x] 5.1 Pass `User $user` into `listFixedSchedules()`, `store()`, `update()`, `destroy()` (currently absent) and update `FixedScheduleController` call sites accordingly
        - _Requirements: 2.1, 2.2_
    - [x] 5.2 `listFixedSchedules()`: apply `->applyWorkplaceFilter($user, PermissionName::FixedScheduleList->value)` to the query
        - _Requirements: 2.1_
    - [x] 5.3 `store()`: call `$user->isAbleTo(PermissionName::FixedScheduleCreate->value, $data['workplace_id'])`, throw/return 403 on failure
        - _Requirements: 2.3_
    - [x] 5.4 `update()`/`destroy()`: call the `Edit`/`Delete` permission check against `$schedule->workplace_id` before mutating
        - _Requirements: 2.3_
    - [x] 5.5 Confirm GlobalAdmin path is unaffected (unrestricted query, `isAbleTo` passes) by tracing `getWorkplaceIdsByPermission`'s `null` return path
        - _Requirements: 2.4_

- [x] 6. Unit Tests — `FixedScheduleService` scoping (`backend/tests/Unit/Services/Admin/FixedScheduleServiceTest.php`)
    - `testListFixedSchedulesOnlyReturnsManagedWorkplaces`
    - `testListFixedSchedulesReturnsAllForGlobalAdmin`
    - `testStoreRejectsWorkplaceOutsideManagedScope`
    - `testStoreSucceedsForManagedWorkplace`
    - `testUpdateRejectsScheduleOutsideManagedScope`
    - `testDestroyRejectsScheduleOutsideManagedScope`
    - _Requirements: 2.1, 2.2, 2.3, 2.4_

- [x] 7. Feature Tests — HTTP layer (`backend/tests/Feature/Admin/FixedSchedule/IndexScopingTest.php`, extending existing `FixedScheduleController` feature tests)
    - `testIndexOnlyReturnsScopedWorkplacesForNonGlobalAdmin`
    - `testIndexReturnsAllWorkplacesForGlobalAdmin`
    - `testStoreReturns403ForUnmanagedWorkplace`
    - `testUpdateReturns403ForUnmanagedSchedule`
    - `testDestroyReturns403ForUnmanagedSchedule`
    - `testStoreSucceedsForManagedWorkplaceHappyPath`
    - _Requirements: 2.1–2.5_

- [x] 8. Checkpoint - scoping fix verified
    - `./vendor/bin/phpunit --filter FixedSchedule` initially had 1 failure: `testStoreReturns403ForUnmanagedWorkplace` used an employee who already had a `FixedSchedule` at the target workplace (from `setUp`), so the "already has a schedule" validation rule fired first (422) before the 403 authorization check ever ran — a test bug, not a scoping bug. Fixed by using a fresh employee with no pre-existing schedule (same pattern the neighboring happy-path test already used). All 111 tests green afterward.

### Phase 3: Change Config (Backend)

- [x] 9. Actions (`backend/app/Actions/Admin/FixedSchedule/`)
    - [x] 9.1 `GetFixedScheduleChangeConfigsAction` — one entry per Managed_Workplace, config row optional (left-join semantics), computed `is_window_open`
        - _Requirements: 4.1, 2.5_
    - [x] 9.2 `UpsertFixedScheduleChangeConfigAction` — single workplace, `isAbleTo` check, `updateOrCreate`
        - _Requirements: 4.2, 4.3, 4.5, 4.6_
    - [x] 9.3 `BulkUpsertFixedScheduleChangeConfigAction` — all-or-nothing scope check across `workplace_ids` before any write
        - _Requirements: 4.4, 4.6_

- [x] 10. `FixedScheduleChangeConfigController` + routes + `FormRequest`s (`index`, `update`, `bulkUpdate`) under `admin/fixed-schedule-change-configs`
    - _Requirements: 4, 14.1_

- [x] 11. `is_window_open` computation (`is_enabled && deadline !== null && today <= deadline`) as a model accessor or Action-level computed field
    - _Requirements: 5.1, 5.2_

- [x] 12. Unit Tests (`backend/tests/Unit/Actions/Admin/FixedSchedule/`)
    - `GetFixedScheduleChangeConfigsActionTest`: `testListsAllManagedWorkplacesEvenWithoutConfigRow`, `testComputesIsWindowOpenCorrectlyWhenEnabledAndBeforeDeadline`, `testComputesIsWindowOpenFalseWhenDeadlinePassed`, `testComputesIsWindowOpenFalseWhenDisabled`
    - `UpsertFixedScheduleChangeConfigActionTest`: `testCreatesConfigWhenNoneExists`, `testUpdatesExistingConfig`, `testAllowsPastDeadline`, `testRejectsUnmanagedWorkplace`
    - `BulkUpsertFixedScheduleChangeConfigActionTest`: `testAppliesSameValuesToAllSelectedWorkplaces`, `testCreatesConfigForWorkplacesWithNoneExisting`, `testRejectsEntireBatchWhenAnyWorkplaceUnmanaged`, `testAppliesNothingWhenBatchRejected`
    - _Requirements: 4, 5_

- [x] 13. Feature Tests (`backend/tests/Feature/Admin/FixedScheduleChangeConfig/`)
    - `IndexTest`: `testRequiresFixedScheduleChangeConfigListPermission`, `testReturnsOnlyManagedWorkplaces`, `testGlobalAdminSeesAll`
    - `UpdateTest`: `testRequiresFixedScheduleChangeConfigEditPermission`, `testUpdatesEnabledAndDeadline`, `testReturns403ForUnmanagedWorkplace`, `testValidatesDeadlineIsAValidDate`
    - `BulkUpdateTest`: `testUpdatesAllSelectedWorkplaces`, `testReturns403WhenAnySelectedWorkplaceUnmanaged`, `testNothingAppliedWhenRejected`
    - _Requirements: 4, 2.6_

- [x] 14. Checkpoint - Change Config slice complete
    - `./vendor/bin/phpunit --filter FixedScheduleChangeConfig`, all green.

### Phase 4: Change Request — Employee Submission (Backend)

- [x] 15. Extract shared day-mutation logic
    - [x] 15.1 Extract the per-day diff/`updateOrCreate`/delete logic already inside `FixedScheduleService::update()` into a reusable method (e.g. `FixedSchedule::applyDetailForDay(int $day, ?int $workplaceShiftId)`), used by both the existing admin edit path and the new approval Action
        - _Requirements: 7.3_
    - [x] 15.2 Update `FixedScheduleService::update()` to call the extracted method, confirm existing behavior/tests unaffected
        - _Requirements: 7.3 (regression safety)_

- [x] 16. `App\Actions\User\FixedSchedule\GetUserFixedScheduleAction`
    - Returns the employee's `FixedSchedule` (by `workplace_id` or the sole one), each day's `Workplace_Shift`, `change_window { is_open, deadline }`, `pending_request` if one exists, and per-day `pending_or_recent_change`
    - _Requirements: 9.2 (mobile-consumed), 14.3_

- [x] 17. `App\Actions\User\FixedSchedule\CreateFixedScheduleChangeRequestAction`
    - [x] 17.1 Validate: `fixed_schedule.user_id === Auth::id()`
        - _Requirements: 6 (ownership)_
    - [x] 17.2 Validate: Change_Window open for the schedule's workplace
        - _Requirements: 6.4_
    - [x] 17.3 Validate: every submitted day's `requested_workplace_shift_id` (if not null) belongs to the schedule's workplace
        - _Requirements: 6.6_
    - [x] 17.4 Diff submitted days against current `fixed_schedule_details`; drop any day whose submitted value matches the current one; reject if zero days remain
        - _Requirements: 6.2, 6.3_
    - [x] 17.5 `lockForUpdate` check: no existing `pending` header for this `fixed_schedule_id`; reject if one exists
        - _Requirements: 6.5_
    - [x] 17.6 Create header + `FixedScheduleChangeRequestDay` rows (post-diff) in one transaction
        - _Requirements: 6.2_

- [x] 18. `UserFixedScheduleController` + routes + `FormRequest`s: `GET /user/fixed-schedule`, `POST /user/fixed-schedule/change-requests`
    - _Requirements: 14.3, 14.4_

- [x] 19. Unit Tests (`backend/tests/Unit/Actions/User/FixedSchedule/CreateFixedScheduleChangeRequestActionTest.php`)
    - `testCreatesRequestWithOneDayPerChangedDay`
    - `testCreatesRequestCoveringMultipleChangedDaysInOneSubmission`
    - `testOmitsUnchangedDaysFromTheCreatedRequest`
    - `testCreatesRequestDayForDayOff`
    - `testRejectsWhenWindowClosed`
    - `testRejectsWhenDeadlinePassedEvenIfEnabledStillTrue`
    - `testRejectsWhenNoDayActuallyDiffersFromCurrentSchedule`
    - `testRejectsWhenAnExistingPendingRequestForThisScheduleAlreadyExists`
    - `testAllowsNewRequestAfterPriorOneResolved`
    - `testRejectsEntireSubmissionWhenAnyDayUsesAShiftFromAnotherWorkplace`
    - `testRejectsWhenScheduleNotOwnedByUser`
    - _Requirements: 6_

- [x] 20. Unit Tests (`backend/tests/Unit/Actions/User/FixedSchedule/GetUserFixedScheduleActionTest.php`)
    - `testReturnsScheduleWithChangeWindowState`
    - `testReturnsPendingRequestAndPerDayChangeWhenOneExists`
    - `testReturnsNullPendingRequestWhenNoneExists`
    - `testRequiresWorkplaceIdWhenUserHasMultipleSchedules`
    - _Requirements: 9.2, 14.3_

- [x] 21. Feature Tests (`backend/tests/Feature/User/FixedSchedule/`)
    - `ShowTest`: `testReturnsOwnFixedScheduleShape`, `testReturns404OrEmptyWhenNoSchedule`, `testRequiresAuthentication`
    - `StoreChangeRequestTest`: `testCreatesRequestHappyPath`, `test422WhenWindowClosed`, `test422WhenPendingRequestExists`, `test422WhenNoDayChanged`, `test422WhenShiftFromOtherWorkplace`, `test403WhenScheduleNotOwnedByUser`
    - _Requirements: 6, 14.3, 14.4_

- [x] 22. Checkpoint - employee submission slice complete
    - `./vendor/bin/phpunit --filter FixedSchedule` (broad filter catching all new test classes), all green.

### Phase 5: Change Request — Admin Review (Backend)

- [x] 23. `App\Actions\Admin\FixedSchedule\ApproveFixedScheduleChangeRequestAction`
    - [x] 23.1 Guard: request `status === pending`, else reject (409-equivalent)
        - _Requirements: 7.5_
    - [x] 23.2 Guard: `isAbleTo` against the request's `workplace_id`
        - _Requirements: 7.6_
    - [x] 23.3 Transaction: loop `FixedScheduleChangeRequestDay` rows, call `FixedSchedule::applyDetailForDay()` for each; set header `status = approved`, `processed_by_id`, `processed_at`; roll back entirely on any failure
        - _Requirements: 7.3_

- [x] 24. `App\Actions\Admin\FixedSchedule\RejectFixedScheduleChangeRequestAction`
    - Same guards as Approve, sets `status = rejected`, no schedule mutation
    - _Requirements: 7.4, 7.5, 7.6_

- [x] 25. `App\Actions\Admin\FixedSchedule\BulkResolveFixedScheduleChangeRequestsAction`
    - Loops each ID through the single-request Approve/Reject Action inside one outer transaction
    - _Requirements: 8.6_

- [x] 26. `App\Actions\Admin\FixedSchedule\GetFixedScheduleChangeRequestsAction`
    - Filters: `workplace_id` (scoped via `FiltersByWorkplace`), `employee_name` (search), `status` (default `pending`), `dates[0]`/`dates[1]` (optional, filters `created_at`; no server-side default — the default-range computation is frontend-only, see Requirement 8.7–8.9), paginated
    - _Requirements: 8.1–8.4, 14.2_

- [x] 27. Export
    - [x] 27.1 `App\Exports\FixedScheduleChangeRequestExport` (mirrors `SalaryRequestExport`: `FromCollection`, `WithHeadings`, `ShouldAutoSize`)
        - _Requirements: 8 (export button support)_
    - [x] 27.2 `App\Actions\Admin\FixedSchedule\ExportFixedScheduleChangeRequestsAction` — builds the collection honoring the same filters as the list
        - _Requirements: 8_

- [x] 28. `FixedScheduleChangeRequestController` + routes + `FormRequest`s: `index`, `approve`, `reject`, `bulkResolve`, `export`
    - _Requirements: 14.2_

- [x] 29. Unit Tests (`backend/tests/Unit/Actions/Admin/FixedSchedule/`)
    - `ApproveFixedScheduleChangeRequestActionTest`: `testApprovalUpdatesFixedScheduleDetailForEveryDayInTheRequest`, `testApprovalRemovesFixedScheduleDetailForDayOffRequestDays`, `testApprovalSetsProcessedByAndProcessedAt`, `testRejectsActionOnAlreadyResolvedRequest`, `testForbidsAdminOutsideManagedWorkplace` (note: `testApprovalAppliesAllDaysAtomicallyRollsBackOnPartialFailure` left uncovered — forcing a genuine partial mid-loop DB failure black-box isn't practical without mocking model internals, which this codebase's tests don't do elsewhere; the transaction wrapper is visible on review)
    - `RejectFixedScheduleChangeRequestActionTest`: `testRejectionDoesNotChangeAnyFixedScheduleDetail`, `testRejectionSetsProcessedByAndProcessedAt`, `testRejectsActionOnAlreadyResolvedRequest`, `testForbidsAdminOutsideManagedWorkplace`
    - `BulkResolveFixedScheduleChangeRequestsActionTest`: `testApprovesAllSelectedRequests`, `testRejectsAllSelectedRequests`, `testRollsBackWholeBatchOnPartialFailure`
    - `GetFixedScheduleChangeRequestsActionTest`: `testFiltersByWorkplaceScopedToManagedWorkplaces`, `testFiltersByEmployeeNameSearch`, `testFiltersByStatus`, `testDefaultsToPendingStatus`, `testFiltersByCreatedAtDateRangeWhenProvided`, `testReturnsAllDatesWhenRangeNotProvided`
    - _Requirements: 7, 8_

- [x] 30. Feature Tests (`backend/tests/Feature/Admin/FixedScheduleChangeRequest/`)
    - `IndexTest`: `testRequiresListPermission`, `testDefaultsToPendingStatus`, `testFiltersByWorkplaceScoped`, `testFiltersByEmployeeName`, `testFiltersByStatus`, `testFiltersByDateRange`
    - `ApproveTest`: `testRequiresProcessPermission`, `testAppliesScheduleChangeOnApproval`, `testReturns409ForAlreadyResolvedRequest`, `testReturns403ForUnmanagedWorkplace`
    - `RejectTest`: `testRequiresProcessPermission`, `testDoesNotChangeScheduleOnRejection`, `testReturns409ForAlreadyResolvedRequest`, `testReturns403ForUnmanagedWorkplace`
    - `BulkResolveTest`: `testApprovesMultipleRequests`, `testRejectsMultipleRequests`, `testReturns403WhenAnyRequestOutsideScope`
    - `ExportTest`: `testExportsFilteredResultsAsXlsx`, `testRequiresListPermission`
    - _Requirements: 7, 8, 2.6_

- [x] 31. Checkpoint - admin review slice complete
    - `./vendor/bin/phpunit --filter FixedScheduleChangeRequest`, all green.

### Phase 6: Expose `is_fixed` on Existing Endpoints

- [x] 32. Add `is_fixed` to `GET /user/schedules` response (source: existing `shift_users.is_fixed` column)
    - _Requirements: 14.5 (backs mobile Requirement 4)_
    - Verified no code change needed: `ScheduleService::getListSchedules()` returns raw `ShiftUser` models with no `select()`/`$hidden` restricting `is_fixed`, so it is already serialized in the response. Locked in with a regression test.
- [x] 33. Add `is_fixed` to `GET /user/check-in/shift` response
    - _Requirements: 14.5 (backs mobile Requirement 5)_
    - Verified no code change needed: `CheckInService::getCheckInShift()` also returns a raw `ShiftUser` model for the same reason. Locked in with a regression test.
- [x] 34. Feature Tests
    - `backend/tests/Feature/User/Schedule/IsFixedTest.php`: `testResponseIncludesIsFixedForFixedShift`, `testResponseIncludesIsFixedFalseForTempShift`
    - `backend/tests/Feature/User/CheckIn/IsFixedTest.php`: `testCheckInShiftResponseIncludesIsFixed`
    - _Requirements: 14.5_
- [x] 35. Checkpoint - all backend work complete
    - Full suite `./vendor/bin/phpunit`: 2314 tests, 7783 assertions, all green. No regressions in unrelated areas.

### Phase 7: Admin Web — Menu Restructure & List Page Fixes

- [x] 36. Sidebar/route/permission wiring
    - [x] 36.1 `frontend/src/configs/sidebar.config.ts`: move "Fixed schedule" out of the Workplace group into a new top-level "Lịch làm cố định" entry with 3 children
        - _Requirements: 1.1, 1.2_
    - [x] 36.2 `frontend/src/configs/route.config.ts`: keep `ADMIN_FIXED_SCHEDULE_LIST`, add `ADMIN_FIXED_SCHEDULE_CHANGE_CONFIG`, `ADMIN_FIXED_SCHEDULE_CHANGE_REQUEST`
        - _Requirements: 1.2_
    - [x] 36.3 `frontend/src/enums/permission_name.enum.ts`: add `FIXED_SCHEDULE_CHANGE_CONFIG_LIST/EDIT`, `FIXED_SCHEDULE_CHANGE_REQUEST_LIST/PROCESS`
        - _Requirements: 1.3_
    - [x] 36.4 Hide sub-items the admin lacks permission for; confirm the existing "no permission" page renders for direct URL access
        - _Requirements: 1.3, 1.4_
        - Uses the existing generic `permissions` array on `RouteInfo` + `Layout.tsx`/route-guard mechanism already used by every other sidebar item — no new per-route code needed once the two new routes carry `permissions`.

- [x] 37. List page updates (`frontend/src/pages/admin/fixed-schedule/index.page.tsx`)
    - [x] 37.1 Remove the "Bắt đầu từ" column
        - _Requirements: 3.1_
    - [x] 37.2 Scope the "Xưởng" filter `Select` and the create/edit modal's "Xưởng" `Select` to `masterData.workplace_access.fixed_schedule` (falling back to all workplaces when `null`/GlobalAdmin)
        - _Requirements: 2.1, 2.2, 2.4_
    - [x] 37.3 Add `'fixed_schedule'` to the permission-group list computed in `GetMasterDataAction` so `workplace_access.fixed_schedule` exists
        - _Requirements: 2.1, 2.2_

- [x] 38. Frontend Tests (`frontend/src/pages/admin/fixed-schedule/index.page.test.tsx`)
    - `testDoesNotRenderStartedAtColumn`
    - `testWorkplaceFilterOnlyShowsAccessibleWorkplaces`
    - `testWorkplaceFilterShowsAllWorkplacesForGlobalAdmin`
    - _Requirements: 2.1, 2.2, 3.1_

### Phase 8: Admin Web — Cấu hình đổi lịch Page

- [x] 39. `frontend/src/pages/admin/fixed-schedule/change-config/api.ts` — react-query hooks for list/update/bulk-update
    - _Requirements: 4_
- [x] 40. `frontend/src/pages/admin/fixed-schedule/change-config/index.page.tsx`
    - [x] 40.1 `DDataTable` with `selectedRecords`/`onSelectedRecordsChange` for multi-select (mirrors `admin/user/index.page.tsx`)
        - _Requirements: 4.4_
    - [x] 40.2 "Trạng thái" column: inline `Switch` bound to `is_enabled` (mirrors `admin/chat/banned-keywords/index.page.tsx`)
        - _Requirements: 4.2_
    - [x] 40.3 "Hạn cuối" column: inline editable date
        - _Requirements: 4.3_
    - [x] 40.4 Bulk toolbar + confirmation modal (enable/disable + shared deadline for selected workplaces)
        - _Requirements: 4.4_
    - [x] 40.5 "Đã hết hạn" visual state, distinct from "Tắt"
        - _Requirements: 5.5_
- [x] 41. Frontend Tests (`frontend/src/pages/admin/fixed-schedule/change-config/index.page.test.tsx`)
    - `testInlineSwitchTogglesCallUpdateMutation`
    - `testBulkToolbarOnlyAppearsWithSelection`
    - `testBulkModalSubmitsCombinedPayloadForAllSelected`
    - `testExpiredDeadlineShowsDistinctStateFromDisabled`
    - `testOnlyManagedWorkplacesListed`
    - _Requirements: 4, 5.5, 2.5_

### Phase 9: Admin Web — Yêu cầu đổi lịch Page

- [x] 42. `frontend/src/pages/admin/fixed-schedule/change-requests/api.ts` — react-query hooks for list/approve/reject/bulk-resolve/export
    - _Requirements: 7, 8_
- [x] 43. `frontend/src/pages/admin/fixed-schedule/change-requests/index.page.tsx`
    - [x] 43.1 Filters: Xưởng (`Select`, scoped), Nhân viên (text search), Trạng thái (`Select`, default "Chờ duyệt"), Khoảng ngày gửi (`DatePickerInput` range)
        - _Requirements: 8.1–8.4_
    - [x] 43.1a Default date-range logic: when exactly one workplace is selected, pre-fill the range from that workplace's `FixedScheduleChangeConfig.updated_at` (date) to `deadline`; no default when no single workplace is selected or the workplace has no config yet; admin's manual edits always override the computed default
        - _Requirements: 8.7, 8.8, 8.9, 8.10_
    - [x] 43.2 List table: Nhân viên, Xưởng, Số ngày thay đổi, Gửi lúc, Trạng thái, Thao tác
        - _Requirements: 8.5_
    - [x] 43.3 Detail view (modal/drawer): weekly grid showing current → requested per changed day
        - _Requirements: 7.1_
        - Simplification: shows only the changed days carried by the request payload (current → requested / "Day off"), matching what Requirement 7.1 literally asks for ("every Fixed_Schedule_Change_Request_Day it contains"). Does not additionally fetch and interleave the full unchanged week — that would need a second API call against the separate Fixed Schedule list endpoint and isn't required by the acceptance criteria; flagging as a possible follow-up if the admin UX review wants it.
    - [x] 43.4 Approve action (row + bulk): opens detail/confirm with "áp dụng ngay cho N ngày" warning before calling the API
        - _Requirements: 7.2, 8.6_
    - [x] 43.5 Reject action (row + bulk)
        - _Requirements: 7.4, 8.6_
    - [x] 43.6 Export Excel button, honoring current filters
        - _Requirements: 8 (export)_
- [x] 44. Frontend Tests (`frontend/src/pages/admin/fixed-schedule/change-requests/index.page.test.tsx` + `partials/request_detail_modal.test.tsx`)
    - `testDefaultsToPendingStatusFilter`
    - `testWorkplaceFilterScopedToManaged`
    - `testEmployeeSearchFiltersResults` (element present; full filtering is server-side, covered on the backend)
    - `testStatusFilterAppliesCorrectly` (element present; filtering is server-side, covered on the backend)
    - `testDateRangeDefaultsToConfigUpdatedAtAndDeadlineWhenOneWorkplaceSelected`
    - `testDateRangeHasNoDefaultWhenNoWorkplaceSelected`
    - `testDateRangeHasNoDefaultWhenWorkplaceHasNoConfig`
    - `testManualDateRangeEditOverridesComputedDefault` (named `does not override an already-present date range`)
    - `testApproveShowsImmediateEffectWarningBeforeConfirming` (in `request_detail_modal.test.tsx`)
    - `testBulkApproveAPIsCalledForAllSelectedIds`
    - `testDetailViewShowsAllChangedDaysBeforeApproveIsEnabled` (in `request_detail_modal.test.tsx`)
    - `testExportButtonSubmitsCurrentFilters`
    - _Requirements: 7, 8_

- [x] 45. Checkpoint - admin web complete
    - Docker Desktop started successfully this session; ran `pnpm test` for the full frontend suite instead of a manual click-through. Found and fixed a real test-mock bug in `change-config/index.page.test.tsx`: the mocked `DatePickerInput` assumed `value` was always a `Date` (called `.toISOString()`), but the real page passes `row.deadline` as a `string | null` per the API contract — crashed all 7 tests in that file. Fixed the mock to pass the string through directly. All 100 frontend test files pass (1 unrelated, pre-existing flaky file — see task 46.4 note).

### Phase 10: Final Validation

- [x] 46. Code Quality
    - [x] 46.1 Backend: `npm run prettier` (reformatted several new files, no logic change) and `npm run pint` (clean)
    - [x] 46.2 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` — all clean
    - [x] 46.3 Full backend suite: `./vendor/bin/phpunit` — 2314 tests, all green
    - [x] 46.4 Full frontend suite: `pnpm test` — 100 test files; 99 pass outright, 1 (`poll_form.test.tsx`, an unrelated pre-existing poll feature not part of this spec) shows timeout flakiness under full-suite CPU load on this machine but passes 14/14 every time it's run in isolation — not a regression from this feature, left as-is.
    - Environment notes for whoever runs this next on the same machine: (a) local dev DB's migration-tracking state was inconsistent before this session (claimed tables existed that didn't) — resolved via `migrate:fresh`, unrelated to this feature; (b) this machine's default Node (v22.9.0) predates Node's stable `require(esm)` support and is below the platform-specific optional dependency's engine floor, so `pnpm test`/`pnpm install` should be run under Node ^20.19.0 or >=22.12.0 (used v20.20.2 here) to get a working `@rolldown/binding-darwin-x64`.

## Notes

- **Test-as-you-go, not a deferred phase.** Per this repo's test-coverage convention, each Phase 2–9 slice includes its own unit/feature/frontend tests immediately after the implementation tasks it covers, rather than one big testing phase at the end — this keeps each slice mergeable/verifiable independently and matches how the codebase's existing features (e.g. `ShiftAbsentNotice`) are structured (`tests/Unit/Actions/...` + `tests/Feature/...` per feature).
- **PHPUnit method names must be camelCase** (`testCreatesPendingRequestForShiftChange`, not `test_creates_pending_request_...`), including `setUp` and private helpers, per `CLAUDE.md`.
- **Ordering risk**: Phase 2 (scoping fix) changes behavior of an already-shipped admin page. Land and verify it before Phase 3+ so a regression there isn't conflated with new-feature bugs.
- **Ordering risk**: Phase 4's `CreateFixedScheduleChangeRequestAction` and Phase 5's `ApproveFixedScheduleChangeRequestAction` both depend on the shared `FixedSchedule::applyDetailForDay()` extraction in task 15.1 — do not start Phase 5 before 15.1 lands, or Approve will duplicate logic that then drifts from the admin's own edit path.
- **Cross-repo dependency**: the mobile app's implementation (separate repo/spec) cannot be finished end-to-end until Phase 4 (`GET /user/fixed-schedule`, `POST /user/fixed-schedule/change-requests`) and Phase 6 (`is_fixed` exposure) are deployed — flag Phase 4/6 completion to whoever picks up the mobile tasks.
- Action pattern is used throughout for new business logic per `CLAUDE.md`; `FixedScheduleService` (pre-existing, Service pattern) is only minimally modified (scoping calls), not migrated to Actions, to keep this change focused.
