# Implementation Plan: Work History Edit Approval

## Overview

Build bottom-up: schema, models, enum, and the one new permission first; then the backend "propose" slice (create-request, replacing the current direct-write endpoint) in isolation with its own tests; then the backend "review" slice (approve/reject/list/bulk-resolve, reusing the refactored recalculation logic); then the two admin web surfaces (inline on `Work_History_List`, and the new dedicated `Work_History_Edit_Request_List` page) wired against the now-stable API; then translation registration; then full-suite validation. Each backend slice gets its own unit/feature tests immediately after its implementation tasks, per the project's test-coverage convention — there is no deferred end-of-plan testing phase.

### Implementation Strategy
- Land the `WorkHistoryService::updateCheckIn` → `applyValues()` refactor (Phase 2) before either the create-request or the approve Action depends on it, so both consume the same, already-verified recalculation path instead of duplicating it.
- Treat "propose" (Phase 2) and "review" (Phase 3) as separate vertical slices — each independently testable via its own HTTP endpoints before any admin web page exists.
- Build admin web last (Phase 5–6), after the API contract is fixed, to avoid building UI against a moving backend.
- Translation keys are added as part of the frontend tasks that introduce the `t(...)` calls, then run through the project's `trans:prepare`/`trans:apply` flow as a dedicated phase before final validation, per `CLAUDE.md`'s Frontend Translation Flow.

## Implementation Plan

### Phase 1: Foundation — Schema, Models, Permission

- [x] 1. Migrations
    - [x] 1.1 Create `work_history_edit_requests` table (`check_in_id` FK cascadeOnDelete, `workplace_id` FK cascadeOnDelete, `requested_by_id` FK nullOnDelete, `status` string default `pending`, `processed_by_id` nullable FK nullOnDelete, `processed_at` nullable timestamp, `reject_reason` nullable text, timestamps, index `(check_in_id, status)`, index `(workplace_id, status)`)
        - _Requirements: 3.1, 6.1, 7.2_
    - [x] 1.2 Create `work_history_edit_request_items` table (`work_history_edit_request_id` FK cascadeOnDelete, `field` string, `old_value` nullable string, `new_value` nullable string, `reason` text, timestamps, index `(work_history_edit_request_id)`)
        - _Requirements: 2.5_

- [x] 2. Models & Enums
    - [x] 2.1 `App\Models\WorkHistoryEditRequest` (`belongsTo CheckIn`, `belongsTo Workplace`, `belongsTo User as requestedBy`, `belongsTo User as processedBy`, `hasMany WorkHistoryEditRequestItem as items`, `use FiltersByWorkplace;`)
        - _Requirements: 3.1, 5.1_
    - [x] 2.2 `App\Models\WorkHistoryEditRequestItem` (`belongsTo WorkHistoryEditRequest`)
        - _Requirements: 2.5_
    - [x] 2.3 `App\Enums\WorkHistoryEditRequestStatus` (`Pending`, `Approved`, `Rejected`)
        - _Requirements: 3.1, 6.1, 7.2_
    - [x] 2.4 Add `editRequests(): HasMany` and `latestEditRequest(): HasOne` (`->latestOfMany()`) to `App\Models\CheckIn`
        - _Requirements: 8.3_
    - [x] 2.5 Add `WorkHistoryEditRequestAlreadyPending`, `WorkHistoryEditRequestNoChanges`, `WorkHistoryEditRequestNotPending` to `App\Enums\ErrorCode`
        - _Requirements: 3.3, 6.2, 7.3_
    - [x] 2.6 `App\Exceptions\WorkHistoryEditRequestBulkResolveAbortedException` (mirrors `FixedScheduleBulkResolveAbortedException`)
        - _Requirements: 5.5_

- [x] 3. Permission
    - [x] 3.1 Add `WorkHistoryEditRequestProcess = 'work_history_edit_request.process'` to `App\Enums\PermissionName`
        - _Requirements: 1.1_
    - [x] 3.2 Seed the new permission (Laratrust seeder, matching how `fixed_schedule_change_request.process` is seeded)
        - _Requirements: 1.1_

- [x] 4. Checkpoint - migration + model foundation
    - Run `migrate:fresh` (or `migrate`) against a local/test DB; verify no FK/identifier-length issues (watch for MySQL's 64-char constraint-name limit, as hit previously on `fixed_schedule_change_request_days`); confirm relationships resolve via `php artisan tinker` or a quick smoke test.

### Phase 2: Backend — Submit Edit Request ("Propose")

- [x] 5. Extract `WorkHistoryService::applyValues()`
    - [x] 5.1 Rename/refactor `WorkHistoryService::updateCheckIn(CheckIn $checkIn, array $data)` to `applyValues(CheckIn $checkIn, array $data, int $attributedUserId): void`, replacing the two `Auth::id()` calls (for `checked_in_by`/`checked_out_by`) with `$attributedUserId` — no other behavior change
        - _Requirements: 6.1_
    - [x] 5.2 Confirm no other call site still depends on the old `updateCheckIn` signature/behavior (the controller call site is replaced in task 6)
        - _Requirements: 6.1 (regression safety)_

- [x] 6. `App\Actions\Manage\WorkHistoryEditRequest\CreateWorkHistoryEditRequestAction`
    - [x] 6.1 Lock the target `CheckIn` (`lockForUpdate`) inside a transaction; reject (`WorkHistoryEditRequestAlreadyPending`) if a `pending` request already exists for it
        - _Requirements: 3.3_
    - [x] 6.2 Diff submitted `check_in_time` (from `check_in_date`+`check_in_time`), `check_out_time` (from `check_out_date`+`check_out_time`), `break_minutes` against the `CheckIn`'s current values, using the same Carbon-combination logic as the old `updateCheckIn`
        - _Requirements: 2.5_
    - [x] 6.3 Build one `WorkHistoryEditRequestItem` per changed field (old value, new value, matching reason); reject (`WorkHistoryEditRequestNoChanges`) if zero fields changed
        - _Requirements: 2.4, 2.5_
    - [x] 6.4 Resolve and store `workplace_id` from `$checkIn->shift->workplace_id`; set `requested_by_id = $user->id`, `status = pending`
        - _Requirements: 3.1_

- [x] 7. `App\Http\Requests\Manage\WorkHistory\CreateEditRequestRequest`
    - [x] 7.1 Reuse the existing `check_in_date`/`check_in_time`/`check_out_date`/`check_out_time`/`break_minutes` rules from `UpdateRequest`
        - _Requirements: 2.1_
    - [x] 7.2 Add `check_in_reason`, `check_out_reason`, `break_minutes_reason` — each required (non-empty string) exactly when its paired value differs from `$this->route('checkIn')`'s current value
        - _Requirements: 2.2, 2.3_

- [x] 8. Wire `WorkHistoryController::updateCheckIn` to `CreateWorkHistoryEditRequestAction` (same route/verb, `PATCH /manage/work-histories/{checkIn}`, same `work_history.edit` permission middleware) instead of calling `WorkHistoryService` directly; return the created `WorkHistoryEditRequest` (with `items`) in the response instead of the current empty `ResponseHelper::success()`, so the frontend can show the pending state without an extra fetch
    - _Requirements: 3.1, 8.1_

- [x] 9. Unit Tests — `CreateWorkHistoryEditRequestActionTest` (`backend/tests/Unit/Actions/Manage/WorkHistoryEditRequest/`)
    - `testCreatesPendingRequestWithOneItemPerChangedField`
    - `testOmitsUnchangedFieldsFromTheCreatedRequest`
    - `testRejectsWhenNoFieldChanged`
    - `testRejectsWhenAPendingRequestAlreadyExistsForTheCheckIn`
    - `testAllowsNewRequestAfterPriorOneWasRejected` (reached via the real reject endpoint, not a hand-set status fixture)
    - `testDoesNotModifyCheckInOrDispatchPayrollJobs`
    - `testStoresWorkplaceIdResolvedFromTheShift`
    - _Requirements: 2.4, 2.5, 3.1, 3.2, 3.3, 3.4_

- [x] 10. Feature Tests — `backend/tests/Feature/Manage/WorkHistoryControllerTest.php`
    - [x] 10.1 **Rewrite** the two existing tests that assert the old immediate-apply behavior — `testUpdateCheckInSuccess` (line ~207) and `testUpdateCheckInOnlyCheckInTime` (line ~225) currently `assertDatabaseHas('check_ins', ['check_in_time' => ...])` right after the PATCH call, which will now fail (the value only lands in `check_ins` after approval, and the payload now needs reason fields). Update both to assert a `pending` `work_history_edit_request` is created instead, and add a matching `assertDatabaseMissing`/unchanged-value check on `check_ins`.
        - _Requirements: 3.1, 3.2_
    - [x] 10.2 New tests: `testRequiresWorkHistoryEditPermission` (existing `testUpdateCheckInForbiddenWithoutPermission` already covers this — verify it still passes unmodified), `testCreatesPendingRequestOnValidSubmission`, `testReturns422WhenChangedFieldMissingReason`, `testReturns409WhenPendingRequestAlreadyExists`, `testCheckInRemainsUnchangedAfterSubmission`
        - _Requirements: 2.2, 2.3, 3.1, 3.2, 3.3_

- [x] 11. Checkpoint - propose slice complete
    - `./vendor/bin/phpunit --filter WorkHistoryEditRequest` (and `--filter UpdateCheckIn`), all green.

### Phase 3: Backend — Review (Approve / Reject / List / Bulk)

- [x] 12. `App\Actions\Manage\WorkHistoryEditRequest\ApproveWorkHistoryEditRequestAction`
    - [x] 12.1 Permission check: `$user->isAbleTo(PermissionName::WorkHistoryEditRequestProcess->value, $request->workplace_id)`, else 403
        - _Requirements: 6.3_
    - [x] 12.2 Transaction: `lockForUpdate` the request; guard `status === Pending`, else `WorkHistoryEditRequestNotPending` (409)
        - _Requirements: 6.2_
    - [x] 12.3 Lock the `CheckIn`; build the `applyValues()` data array from the request's items; call `WorkHistoryService::applyValues($checkIn, $data, attributedUserId: $request->requested_by_id)`
        - _Requirements: 6.1_
    - [x] 12.4 Set `status = approved`, `processed_by_id = $user->id`, `processed_at = now()`
        - _Requirements: 6.1_
    - [x] 12.5 Explicitly allow (do not block) approving a request the same user submitted
        - _Requirements: 6.4_

- [x] 13. `App\Actions\Manage\WorkHistoryEditRequest\RejectWorkHistoryEditRequestAction`
    - Same permission check and locked pending-guard as Approve; require non-empty `reject_reason`; set `status = rejected`, `processed_by_id`, `processed_at`, `reject_reason`; no `CheckIn` mutation
    - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x] 14. `App\Actions\Manage\WorkHistoryEditRequest\GetWorkHistoryEditRequestsAction`
    - Filters: `workplace_id` (scoped via `applyWorkplaceFilter($user, WorkHistoryEditRequestProcess)`), `employee_name` (search via `checkIn.user`, same `like` pattern as the Fixed Schedule equivalent), `status` (default `pending`, `all` clears it), `dates[0]`/`dates[1]` (on `created_at`); paginated; eager-loads `checkIn.user`, `checkIn.shift.workplace`, `items`, `requestedBy`, `processedBy`
    - _Requirements: 5.1, 5.2, 5.3, 8.2_

- [x] 15. `App\Actions\Manage\WorkHistoryEditRequest\BulkResolveWorkHistoryEditRequestsAction`
    - Loop `ids` through the single-request Approve/Reject Action inside one outer transaction; abort/roll back the whole batch on the first error via `WorkHistoryEditRequestBulkResolveAbortedException`; `reject_reason` required and applied to every item when `action === reject`
    - _Requirements: 5.5_

- [x] 16. `App\Http\Controllers\Manage\WorkHistoryEditRequestController` + routes + `FormRequest`s (`index`, `approve`, `reject`, `bulkResolve`) under `manage/work-history-edit-requests`, all middleware'd to `permission:work_history_edit_request.process`. No separate `show`/detail endpoint — the list response already eager-loads `items` per row (Task 14), so the frontend detail modal opens directly from the already-fetched row object, same as `FixedScheduleChangeRequest`'s `RequestDetailModal` (no extra API call).
    - _Requirements: 5.4, 8.2_

- [x] 17. Add `latest_edit_request` eager-load to `WorkHistoryService::getWorkHistory()` (`with('latestEditRequest.items')`) so `Work_History_List` gets status/reason without N+1
    - _Requirements: 8.3_

- [x] 18. Unit Tests (`backend/tests/Unit/Actions/Manage/WorkHistoryEditRequest/`)
    - `ApproveWorkHistoryEditRequestActionTest`: `testAppliesItemsToCheckIn`, `testRecalculatesWorkMinutesIdenticallyToDirectEditFlow`, `testSetsCheckedInByAndCheckedOutByToRequesterNotApprover`, `testAllowsSelfApproval`, `testForbidsApproverOutsideManagedWorkplace`, `testReturns409ForAlreadyResolvedRequest` (reached by resolving it first via the real action, not a hand-set fixture), `testRollsBackEverythingWhenRecalculationFails`
    - `RejectWorkHistoryEditRequestActionTest`: `testRejectionDoesNotChangeCheckIn`, `testRequiresRejectReason`, `testSetsProcessedByAndProcessedAtAndRejectReason`, `testReturns409ForAlreadyResolvedRequest`, `testForbidsApproverOutsideManagedWorkplace`
    - `GetWorkHistoryEditRequestsActionTest`: `testFiltersByWorkplaceScopedToManagedWorkplaces`, `testFiltersByEmployeeNameSearch`, `testFiltersByStatusDefaultingToPending`, `testStatusAllClearsTheDefault`, `testFiltersByCreatedAtDateRange`, `testGlobalAdminSeesRequestsAcrossAllWorkplaces`
    - `BulkResolveWorkHistoryEditRequestsActionTest`: `testApprovesAllSelectedRequests`, `testRejectsAllSelectedRequestsWithSharedReason`, `testRollsBackWholeBatchOnPartialFailure`
    - _Requirements: 5, 6, 7_

- [x] 19. Feature Tests (`backend/tests/Feature/Manage/WorkHistoryEditRequest/`)
    - `IndexTest`: `testRequiresProcessPermission`, `testDefaultsToPendingStatus`, `testFiltersScopedToManagedWorkplaces`, `testFiltersByEmployeeName`, `testFiltersByDateRange`
    - `ApproveTest`: `testRequiresProcessPermission`, `testAppliesValuesOnApproval`, `testAllowsSelfApprovalWhenUserHasBothPermissions`, `testReturns409ForAlreadyResolvedRequest`, `testReturns403ForUnmanagedWorkplace`
    - `RejectTest`: `testRequiresProcessPermission`, `testRequiresRejectReason`, `testDoesNotChangeCheckInOnRejection`, `testReturns409ForAlreadyResolvedRequest`
    - `BulkResolveTest`: `testApprovesMultipleRequests`, `testRejectsMultipleRequestsWithSharedReason`, `testReturns403WhenAnyRequestOutsideScope`
    - `LifecycleTest`: `testSubmitThenApproveAppliesValuesAndPayrollJobsFire` (via `PATCH .../{checkIn}` then `POST .../approve}`), `testSubmitBlockedWhileAPendingRequestExists`, `testSubmitAllowedAfterPriorRequestRejected`, `testEmployeeWithHistoryOfOneRejectedAndOneNewPendingRequestShowsBothWhenStatusAllFiltered`
    - _Requirements: 2, 3, 5, 6, 7_

- [x] 20. Checkpoint - review slice complete + full backend suite
    - `./vendor/bin/phpunit --filter WorkHistoryEditRequest`, then the full `./vendor/bin/phpunit` (or `--parallel`) to catch regressions in the existing Work History suite from the `updateCheckIn` → `applyValues` refactor.

### Phase 4: Admin Web — Foundation

- [x] 21. `frontend/src/enums/permission_name.enum.ts`: add `WORK_HISTORY_EDIT_REQUEST_PROCESS` → `{ value: 'work_history_edit_request.process', text: 'Work History Edit Request Process' }`
    - _Requirements: 1.1_
- [x] 22. `frontend/src/configs/api.config.ts`: add `MANAGE_WORK_HISTORY_EDIT_REQUESTS` (list/bulk-resolve base), `MANAGE_WORK_HISTORY_EDIT_REQUESTS_APPROVE`, `MANAGE_WORK_HISTORY_EDIT_REQUESTS_REJECT`, `MANAGE_WORK_HISTORY_EDIT_REQUESTS_BULK_RESOLVE` (no `_DETAIL` entry — Task 16 does not add a detail endpoint)
    - _Requirements: 8.2_
- [x] 23. `frontend/src/configs/route.config.ts` + sidebar config: add a route/menu entry for the new edit-requests page (peer to the existing "Xem theo tháng" / `MANAGE_WORK_HISTORY_MONTHLY` entry), gated by `WORK_HISTORY_EDIT_REQUEST_PROCESS`
    - _Requirements: 5.5_
- [x] 24. `frontend/src/types/models/work_history_edit_request.d.ts` — `WorkHistoryEditRequest`, `WorkHistoryEditRequestItem` interfaces; extend `frontend/src/types/models/check_in.d.ts`'s `CheckIn` type with `latest_edit_request?: WorkHistoryEditRequest | null`
    - _Requirements: 8.3_
- [x] 25. `frontend/src/enums/work_history_edit_request_status.enum.ts` — mirrors `fixed_schedule_change_request_status.enum.ts` (pending/approved/rejected → `EnumHelper` color/text). Reuses the exact same label strings ("Pending approval"/"Approved"/"Rejected"), so no new translation keys are needed for this enum.
    - _Requirements: 4.1_

### Phase 5: Admin Web — Work History List Changes

- [x] 26. `check_in_update_modal.tsx`: add three reason inputs — "Lý do sửa giờ vào" (paired with `check_in_date`+`check_in_time`), "Lý do sửa giờ ra" (paired with `check_out_date`+`check_out_time`), "Lý do sửa giờ nghỉ" (paired with `break_minutes`) — each becomes required only when its paired value(s) differ from the modal's initial values; wire submit to the (now request-creating) `useUpdateCheckInMutation`; update the success toast from "Successfully updated check in data" to a submitted-for-approval message (e.g. "Đã gửi yêu cầu sửa công, chờ duyệt"), since the values are no longer applied immediately
    - _Requirements: 2.1, 2.2, 2.3, 3.1_
- [x] 27. `work-history/index.page.tsx`
    - [x] 27.1 Add "Trạng thái" column reading `data.latest_edit_request?.status` (badge via the new status enum; blank/"—" when `null`)
        - _Requirements: 4.1_
    - [x] 27.2 Add "Lý do sửa đổi" column/affordance showing each item's reason from `data.latest_edit_request?.items`, plus the rejection reason when `status === 'rejected'`
        - _Requirements: 4.1_
    - [x] 27.3 Disable the edit (pencil) action when `data.latest_edit_request?.status === 'pending'`
        - _Requirements: 3.3_
    - [x] 27.4 Add an inline "Duyệt" action (visible only when `perms.WORK_HISTORY_EDIT_REQUEST_PROCESS` and status is `pending`) opening a confirmation showing old→new values and reasons before calling the approve mutation
        - _Requirements: 4.2, 4.5_
- [x] 28. `frontend/src/pages/manage/work-history/api.ts`: adjust `useUpdateCheckInMutation` payload (adds the three reason fields) and invalidate the `work-history-edit-requests` query key too; add a shared `useApproveWorkHistoryEditRequestMutation` (reused by both the inline action here and the dedicated page in Phase 6) that invalidates **both** `work-history` and `work-history-edit-requests` query keys on success, so the list's status/value columns refresh immediately after an inline approve
    - _Requirements: 2.5, 4.5_
- [x] 29. Frontend Tests — `work-history/index.page.test.tsx` updates + `check_in_update_modal.test.tsx` (+ dedicated `approve_edit_request_modal.test.tsx` for the inline confirmation modal)
    - reason required only for changed fields (and not required when nothing changed, relying on the backend's 422 as documented in the design's Error Handling table)
    - status badge + reason tooltip render from `latest_edit_request`, including the rejection reason when the latest request was rejected
    - edit (pencil) action disabled while pending
    - inline "Duyệt" only visible with `WORK_HISTORY_EDIT_REQUEST_PROCESS` and a pending request
    - inline approve modal shows changed fields/reasons and calls the shared approve mutation before applying
    - _Requirements: 2.2, 2.3, 4.1, 4.2, 4.3, 4.5_

### Phase 6: Admin Web — Yêu cầu sửa công Page

- [x] 30. `frontend/src/pages/manage/work-history/edit-requests/api.ts` — react-query hooks: list, detail, approve, reject (with `reject_reason`), bulk-resolve (with `reject_reason` for bulk-reject)
    - _Requirements: 8.2_
- [x] 31. `frontend/src/pages/manage/work-history/edit-requests/index.page.tsx`
    - [x] 31.1 Filters via `CommonSearchBox`: Xưởng (scoped `Select`), Nhân viên (text search), Trạng thái (`Select`, default "Chờ duyệt"), Thời gian (date-range on submission date)
        - _Requirements: 5.2, 5.3_
    - [x] 31.2 `DDataTable` columns: Nhân viên, Xưởng, Ngày công, Trường thay đổi (summary), Gửi lúc, Trạng thái, Thao tác; row selection (`isRecordSelectable`: `status === 'pending'`)
        - _Requirements: 5.1_
    - [x] 31.3 Bulk approve / bulk reject toolbar buttons (visible with selection + permission)
        - _Requirements: 5.5_
    - [x] 31.4 `noPermission` gate on `WORK_HISTORY_EDIT_REQUEST_PROCESS`
        - _Requirements: 5.5_
- [x] 32. `partials/request_detail_modal.tsx`
    - [x] 32.1 Show every `WorkHistoryEditRequestItem` (field label, old→new, reason) before Approve/Reject are actionable
        - _Requirements: 5.4_
    - [x] 32.2 Approve button (confirmation, applies immediately)
        - _Requirements: 6.1_
    - [x] 32.3 Reject flow with a required reason `Textarea` (cannot reuse the reason-less `DConfirmModal`) — small custom dialog
        - _Requirements: 7.1_
- [x] 33. Bulk-reject dialog with a required shared reason input (extends/replaces the generic `DConfirmModal` used for bulk approve, since bulk reject needs a reason field the generic modal doesn't have)
    - _Requirements: 7.1, 5.5_
- [x] 34. Frontend Tests (`edit-requests/index.page.test.tsx` + `partials/request_detail_modal.test.tsx` + dedicated `reject_request_modal.test.tsx`/`bulk_reject_modal.test.tsx`)
    - defaults to pending status filter; workplace filter scoped to `work_history_edit_request` access group; all four filters present and wired
    - detail modal shows every item before approve/reject are actionable; hides actions without process permission or once resolved
    - reject requires a non-empty reason (single and bulk); bulk approve/reject call the bulk-resolve mutation with all selected ids
    - `noPermission` state when lacking `WORK_HISTORY_EDIT_REQUEST_PROCESS`
    - _Requirements: 5, 6, 7_

### Phase 7: Translation

- [x] 35. Register new dynamic Role Management keys in `frontend/src/locales/for_scanner.ts`: `t('role|work_history_edit_request')` (group) and `t('role|work_history_edit_request.process')` (permission). Also registered the dynamic sidebar key `t('route|Work history edit requests')` (Task 23's `customName`), which needed the same scanner treatment.
    - _Requirements: 1.1_
- [x] 36. Ran `pnpm trans:prepare`; filled `vi`/`ja` for all 19 new keys (the Role Management/route keys above, plus every new `t(...)` string introduced in Phases 4–6) in the generated `.translation-workflow/translation-batch.json`, preserving placeholders exactly. The new status enum (Task 25) reused existing `enum|Pending approval`/`Approved`/`Rejected` keys, so no new keys were needed there.
    - _Requirements: (cross-cutting — CLAUDE.md Frontend Translation Rules)_
- [x] 37. Ran `pnpm trans:apply` (auto-removed `.translation-workflow/` on completion; `trans:cleanup` not needed)
    - _Requirements: (cross-cutting)_

### Phase 8: Final Validation

- [x] 38. Code Quality
    - [x] 38.1 Backend: `npm run prettier` and `npm run pint` — clean
    - [x] 38.2 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` — clean
    - [x] 38.3 Full backend suite: `php artisan test --parallel` — 3204 tests, all green, no regressions
    - [x] 38.4 Full frontend suite: `pnpm test` — 130 files / 1463 tests, all green
    - [x] 38.5 Manual smoke test against the real dev environment (docker backend + `pnpm dev` frontend via Playwright/chromium, logged in as GlobalAdmin who holds both `work_history.edit` and `work_history_edit_request.process`): submitted an edit with a reason → client blocked submission with a localized error until the reason was filled → pending badge shown, edit action disabled, current committed values unchanged → inline "Duyệt" opened a confirmation showing old→new+reason → confirming applied the change, recalculated work hours, flipped status to "Đã duyệt", and re-enabled the edit action → dedicated "Yêu cầu sửa công" page listed the request with working filters and a detail modal → separately submitted and **rejected** a second request: required-reason dialog, rejected status, `check_ins` left unchanged, edit action re-enabled for resubmission.
      Found and fixed 3 real bugs this surfaced (not caught by unit/integration tests since they were UI-composition/localization issues):
      - Missing translations for the reason-field validator labels (`.label('check-in reason')` etc.) leaked raw English text into an otherwise-Vietnamese error message. Fixed by dropping the custom `.label()` calls (falling back to the already-snake_case field names, consistent with the existing `field|check_in_time` convention) and registering/translating `field|check_in_reason`, `field|check_out_reason`, `field|break_minutes_reason`.
      - `GetWorkHistoryEditRequestsAction` didn't eager-load `workplace` on the request itself (only `checkIn.shift.workplace`), so the edit-requests list's "Xưởng" column always showed "-" despite the TS type and column expecting `row.workplace`. Fixed by adding `workplace` to the eager-load list; added `testIncludesWorkplaceNameInTheResponse` regression test.
      - The reject dialog and the detail modal were both mounted as separate Mantine `<Modal>`s with equal stacking, so clicking "Từ chối" opened the reject dialog *behind* the still-open detail modal — invisible and unclickable to a real user (only reachable via a forced click in the test). Fixed by not rendering `RequestDetailModal` while the reject dialog is open (`detailRequest` state is preserved so `handleReject` still works; the detail modal reappears once the reject dialog closes).
      Also incidentally discovered `GetMasterDataAction`'s hardcoded permission-group prefix list (used to build `workplace_access` for scoping the "Xưởng" filter) didn't include the new `work_history_edit_request` group — added it there and to the frontend's `WorkplaceAccess` type, matching the existing `fixed_schedule_change_request` precedent.

## Notes

- **Test-as-you-go, not a deferred phase.** Each Phase 2/3 backend slice includes its own unit/feature tests immediately after its implementation tasks, per this repo's test-coverage convention.
- **PHPUnit method names must be camelCase** (`testAppliesItemsToCheckIn`, not `test_applies_items_to_check_in`), including `setUp` and private helpers, per `CLAUDE.md`.
- **Ordering risk**: Task 5.1 (`applyValues` refactor) must land before Task 12 (`ApproveWorkHistoryEditRequestAction`) — Approve depends on the new signature (`attributedUserId`). Do not start Phase 3 before Phase 2's task 5 is merged.
- **Ordering risk**: the permission (Task 3.1) and its Role Management translation keys (Task 35) are easy to land separately and forget the other half — land them together or verify Role Management renders the new group correctly before Phase 8.
- **Breaking-change risk (not an ordering concern)**: Task 8 changes the behavior of an already-shipped, actively-used endpoint (`PATCH /manage/work-histories/{checkIn}`, single frontend call site: `check_in_update_modal.tsx`) from "apply immediately" to "create a pending request." This is the one deliberate breaking change in this feature (see requirements.md Introduction), and it has a concrete, already-identified blast radius: `testUpdateCheckInSuccess` and `testUpdateCheckInOnlyCheckInTime` in `backend/tests/Feature/Manage/WorkHistoryControllerTest.php` currently assert immediate application and will fail unless rewritten (Task 10.1) — flag this explicitly in review, it is a behavior/compatibility risk, not a task-sequencing one.
- Action pattern is used throughout for all new business logic per `CLAUDE.md`; `WorkHistoryService` (pre-existing, Service pattern) is only minimally modified (the `applyValues` extraction), not migrated to Actions, to keep the change focused.
- Per `CLAUDE.md`'s Test Design Rules: the lifecycle test in Task 19 (`LifecycleTest`) reaches "pending → approved" and "pending → rejected → new pending" states through the real endpoints (submit, then approve/reject), not via hand-set fixture rows — this is the required at-least-once-per-flow lifecycle test.
