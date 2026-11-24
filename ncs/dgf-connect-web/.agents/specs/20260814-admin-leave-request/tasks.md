# Implementation Plan: Admin Leave Request

## Overview

The build is **foundation-first with a risk-first opening**: the holiday calendar and `LeaveDayCalculator` come before the leave request itself, because the day-count rule is the only genuinely novel logic in this feature and everything downstream depends on it. Once the calendar is proven, the leave request follows the well-worn path already used by absent notices and fixed-schedule change requests — enums, migration, model, filter, actions, form requests, controller, routes — after which the two web admin screens are built against a working API. Tests are written alongside each backend phase rather than batched at the end, since the day calculator and the decision transitions are exactly the places where a late test would force rework. Translations and the final quality gate close the plan.

### Implementation Strategy

- Land the Composer dependency and the calendar first; it is the only external integration and the only piece with no in-repo precedent.
- Build backend phases so each one ends with a runnable test suite, not a half-wired layer.
- Copy the structure of `Manage/AbsentNotice` and `Admin/FixedSchedule` deliberately — matching the existing idiom is a requirement, not a preference.
- Build the manager screen before the holiday screen on the frontend, because the manager screen is the one that validates the API shape end to end.
- Defer all locale JSON edits to a single translation task after the UI is final, per the project's translation flow.

## Implementation Plan

### Phase 1: Holiday Calendar Foundation

- [x] 1. Holiday calendar data layer
    - [x] 1.1 Add the Yasumi dependency and provider seam
        - Run `composer require azuyalabs/yasumi` in the PHP container
        - Create `app/Contracts/HolidayProviderContract.php` with `holidaysForYear(int $year): array`
        - Create `app/Services/AdminLeave/YasumiHolidayProvider.php` returning a `Y-m-d => name` map from Yasumi's Japan provider using the `ja_JP` locale
        - Bind the contract to the concrete provider in `AppServiceProvider`
        - _Requirements: 6.3_

    - [x] 1.2 Create the holiday tables and models
        - Migration `create_company_holidays_table`: `id`, `date` (unique), `name`, `source`, timestamps
        - Migration `create_company_holiday_settings_table`: `id`, `year` (unique), `exclude_weekends` boolean default false, timestamps
        - Models `CompanyHoliday` and `CompanyHolidaySetting` extending `AbstractModel`, with docblock property annotations as in `ShiftAbsentNotice`
        - Enum `app/Enums/CompanyHolidaySource.php` with `Library` and `Manual`
        - _Requirements: 6.1, 6.5, 6.8_

    - [x] 1.3 Implement `EnsureHolidayYearAction`
        - Read existing dates for the year, diff against the provider's map, `insertOrIgnore` the remainder with `source = library`
        - Never update or delete existing rows, so manual renames survive regeneration
        - Return an `ActionResult` carrying the inserted count
        - _Requirements: 6.3, 6.4_

- [x] 2. Leave day calculation
    - [x] 2.1 Implement `LeaveDayCalculator`
        - `countDays(CarbonInterface $start, CarbonInterface $end): int`, inclusive of both endpoints
        - Load holidays for every spanned year in one query, keyed by `Y-m-d`
        - Call `EnsureHolidayYearAction` for any spanned year with zero rows before loading
        - Load weekend settings for spanned years in one query; resolve per date's own year with the "nearest earlier year, else false" inheritance rule
        - Evaluate all dates in `Asia/Tokyo`
        - The result is written once by the caller and never recomputed; the calculator exposes no update path
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 5.9, 6.9, 6.10_

    - [x] 2.2 Unit-test the calculator
        - `LeaveDayCalculatorTest`: inclusive ordinary range; holiday excluded; weekends excluded only when the year's setting is on; a range crossing 31 December applying each year's own rules; every date excluded returning `0`
        - Assert generation fires for an empty year and does not re-fire for a populated one
        - Assert a stored count is unaffected by a later holiday or weekend-setting change
        - Bind `HolidayProviderContract` to a `FakeHolidayProvider` so the data is deterministic
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.7, 5.9_

    - [x] 2.3 Unit-test `EnsureHolidayYearAction`
        - Populates an empty year; inserts only missing dates on a partially populated year; leaves a manually renamed row untouched; is idempotent across two calls
        - _Requirements: 6.3, 6.4_

- [x] 3. Checkpoint — calculator is trustworthy
    - Run `docker compose exec php php artisan test --parallel --filter "LeaveDayCalculator|EnsureHolidayYear"`
    - Confirm the year-boundary and empty-calendar cases pass before anything depends on the calculator. Ask the user if the holiday-name language or the inheritance rule looks wrong in practice.

### Phase 2: Permission and Holiday API

- [x] 4. Permission and holiday endpoints
    - [x] 4.1 Register the `admin_leave.manage` permission
        - Add `case AdminLeaveManage = 'admin_leave.manage';` to `PermissionName` under a new section comment
        - Confirm `RolePermissionSeeder` picks it up automatically via `PermissionName::values()`
        - Add `ADMIN_LEAVE_MANAGE` to `frontend/src/enums/permission_name.enum.ts` (union member and value entry)
        - _Requirements: 1.1, 1.5_

    - [x] 4.2 Build the holiday controller, requests, and actions
        - `GetHolidayCalendarAction` returning the year's holidays plus the resolved `exclude_weekends`
        - `CreateCompanyHolidayAction`, `UpdateCompanyHolidayAction`, `DeleteCompanyHolidayAction`, `UpdateHolidaySettingAction`
        - Form Requests validating `year` (2000–2100), `date` (unique), `name` (max 255), `exclude_weekends` (boolean); `source` is never accepted from input
        - `CompanyHolidayController` with thin methods delegating to actions
        - _Requirements: 6.1, 6.5, 6.6, 6.7, 6.8_

    - [x] 4.3 Register holiday routes
        - Under the `admin` prefix: `GET /company-holidays`, `POST /company-holidays/generate`, `POST /company-holidays`, `PUT /company-holidays/{companyHoliday}`, `DELETE /company-holidays/{companyHoliday}`, `PUT /company-holidays/settings`
        - Every route gets `permission:admin_leave.manage`
        - _Requirements: 1.2, 6.1_

    - [x] 4.4 Feature-test the holiday API
        - `CompanyHolidayTest`: generation populates a year; a second generation inserts nothing and preserves a manual edit; manual CRUD works; duplicate date returns `422`; the weekend setting stores per year and a later year inherits it
        - Assert `403` on every endpoint without `admin_leave.manage`
        - _Requirements: 1.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9_

### Phase 3: Leave Request Backend

- [x] 5. Leave request data layer
    - [x] 5.1 Create enums and migrations
        - `app/Enums/AdminLeaveType.php` with the four cases and `requiresDateRange()` returning false only for `ExpenseClaim`
        - `app/Enums/AdminLeaveRequestStatus.php` with `Pending`, `Approved`, `Rejected`
        - Migration `create_admin_leave_requests_table` per the design's column table, with indexes `(requester_id, status)`, `(status, created_at)`, `(start_date, end_date)`
        - Migration `create_admin_leave_request_attachments_table` mirroring `chat_attachments`
        - Add `ADMIN_LEAVE_REQUEST_NOT_PENDING` to `ErrorCode` under a new section comment
        - _Requirements: 2.1, 2.10, 8.4_

    - [x] 5.2 Create models and the query filter
        - `AdminLeaveRequest` with casts for `type`, `status`, dates, `decided_at`; relations `requester`, `decidedBy`, `attachments`
        - `AdminLeaveRequestAttachment` with a `belongsTo` back-reference and an `is_image` accessor
        - `AdminLeaveRequestFilter` extending `AbstractFilter` with `dateFrom`/`dateTo` as one dual-mode `where` group, plus `requesterId`, `status`, `type`
        - _Requirements: 7.4, 7.5, 7.6, 7.7, 7.8_

- [x] 6. Request creation
    - [x] 6.1 Build `CreateAdminLeaveRequestRequest`
        - `type` required and in the enum; dates required unless `expense_claim`; `end_date` after or equal `start_date`
        - `start_date` after or equal today for leave types; no earlier than 366 days ago for `expense_claim`
        - Span at most 366 days; `reason` required, 1–1000 chars
        - `attachments` max 3 files, `max:5120` each, extension and MIME both limited to jpg/jpeg/png/webp/pdf
        - All date rules evaluated in `Asia/Tokyo`
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 2.9, 2.14, 4.1, 4.2, 4.3, 4.4_

    - [x] 6.2 Implement `CreateAdminLeaveRequestAction`
        - Write files under `admin-leave/{requester_id}/{uuid}.{ext}` on the private disk, collecting `$storedPaths`
        - Inside `DB::transaction`: compute `leave_days` via `LeaveDayCalculator` when dates are present, insert the request and attachment rows
        - On `Throwable`, `Storage::delete($storedPaths)` before rethrowing, following `UploadDocumentStepAction`
        - _Requirements: 2.10, 2.12, 4.5, 4.6_

    - [x] 6.3 Unit-test creation
        - Persists `pending` status with the computed day count; persists a null day count for a dateless `expense_claim`
        - Forces a mid-transaction failure and asserts every written file is deleted and no attachment rows remain
        - Creates a request overlapping an existing pending one and asserts it is accepted rather than rejected
        - _Requirements: 2.10, 2.12, 2.13, 4.6, 5.8_

- [x] 7. Listing and detail
    - [x] 7.1 Implement `GetAdminLeaveRequestsAction`
        - Apply the filter, eager-load `requester` and `decidedBy`, `withCount('attachments')`, paginate, order by `created_at` descending
        - Resolve `has_overlap` in one query per page against the page's requesters and date spans
        - Shape rows into the flat payload from the design
        - _Requirements: 7.1, 7.2, 7.3, 7.11, 7.16, 7.17_

    - [x] 7.2 Implement `GetMyAdminLeaveRequestsAction` and `GetAdminLeaveRequestDetailAction`
        - The "mine" action scopes to the authenticated requester, newest first
        - The detail action allows the requester or any holder of `admin_leave.manage`, returns `403` otherwise, and generates a time-limited URL per attachment via `UrlHelper::getStorageUrl()`
        - Detail includes the conflicting request's dates and status when an overlap exists
        - _Requirements: 3.1, 3.2, 3.3, 3.4, 4.7, 4.8, 9.7_

    - [x] 7.3 Implement the day-count preview endpoint
        - `PreviewLeaveDaysAction` delegating straight to `LeaveDayCalculator`, creating nothing
        - `PreviewLeaveDaysRequest` validating the range is ordered and at most 366 days
        - `POST /admin/admin-leave-requests/preview-days` gated by `permission:manager.auth`
        - Feature test asserting the previewed number equals the number stored when the same range is submitted, and that no request row is created
        - Consumed by the mobile submission form; see the companion mobile spec in `dgf-connect-app-admin`
        - _Requirements: 5.10, 5.11, 5.12, 5.13_

    - [x] 7.4 Feature-test listing and filters
        - `GetAdminLeaveRequestsTest`: a merely-overlapping request is returned, not only fully-contained ones
        - A dateless `expense_claim` is returned by a date filter covering its submission date — the regression guard for the default-filter trap
        - Requester, type, and status filters narrow correctly and combine
        - `has_overlap` true for intersecting pending/approved requests from one requester, false across different requesters
        - Seed already-decided requests from a prior lifecycle and assert the filters select and ignore them correctly
        - _Requirements: 7.1, 7.4, 7.5, 7.6, 7.7, 7.8, 7.16_

- [x] 8. Decisions
    - [x] 8.1 Implement approve and reject actions
        - Refuse with `403` when the decider is the requester, checked before the lock
        - Inside `DB::transaction`, re-fetch with `lockForUpdate()`, return `409 ADMIN_LEAVE_REQUEST_NOT_PENDING` when no longer pending
        - Write `status`, `decided_by_id`, `decided_at`, and for rejection `reject_reason`; never overwrite an existing decision
        - `RejectAdminLeaveRequestRequest` requires a reason of 1–1000 characters
        - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.8, 8.10_

    - [x] 8.2 Wire the controller and routes
        - `AdminLeaveRequestController` with `index`, `mine`, `show`, `store`, `approve`, `reject`
        - Routes under the `admin` prefix: `store`/`mine`/`show` gated by `permission:manager.auth`; `index`/`approve`/`reject` gated by `permission:admin_leave.manage`
        - _Requirements: 1.2, 2.11, 3.4_

    - [x] 8.3 Unit-test the decision actions
        - Approve sets status, decider, timestamp; reject additionally stores the reason
        - Both refuse when the decider is the requester
        - Both return `409` on an already-decided request with the original decision intact
        - A decided request whose decider is later disabled or soft-deleted still returns that decider's name
        - _Requirements: 8.1, 8.2, 8.4, 8.5, 8.9, 8.10_

    - [x] 8.4 Feature-test permissions and the full lifecycle
        - `AdminLeavePermissionTest`: every manager endpoint `403` without `admin_leave.manage`; creation `403` without `manager.auth`; requester reads own detail, unrelated admin cannot
        - `AdminLeaveLifecycleTest`, reaching each state through the real endpoints: create → list shows pending → approve → list shows approved with decider and timestamp; create → reject with reason → second decision returns `409`; create in an ungenerated year → assert the year was generated and the count reflects its holidays
        - `AdminLeaveAttachmentTest`: three files accepted, a fourth rejected, oversized and wrong-type rejected; detail returns time-limited URLs; unrelated user gets `403`
        - _Requirements: 1.2, 2.11, 3.3, 4.1, 4.2, 4.3, 4.4, 4.7, 4.8, 5.5, 8.1, 8.2, 8.4_

- [ ] 9. Checkpoint — backend complete
    - Run `docker compose exec php php artisan test --parallel --filter "AdminLeave|CompanyHoliday"`
    - Run `npm run pint` and `npm run prettier` in `backend/`
    - Verify the API by hand against the payload shapes in the design before the frontend is built on them.

### Phase 4: Web Admin

- [x] 10. Routing, types, and API client
    - [x] 10.1 Register routes, sidebar entry, and API paths
        - `RouteConfig`: `ADMIN_LEAVE_REQUEST` (`/admin/admin-leave`) and `ADMIN_LEAVE_HOLIDAY` (`/admin/admin-leave/holidays`), both requiring `ADMIN_LEAVE_MANAGE`
        - `sidebar.config.ts`: a new group for the Admin Management menu holding both entries
        - `api.config.ts`: entries for the nine backend endpoints
        - _Requirements: 1.3, 1.4_

    - [x] 10.2 Add types and query hooks
        - `types/models/admin_leave_request.ts` and `types/models/company_holiday.ts` per the design's interfaces
        - `enums/admin_leave_type.enum.ts` and `enums/admin_leave_request_status.enum.ts` following `absent_notice_status.enum.ts`, with status colors
        - `api.ts` with list, detail, approve, and reject hooks, invalidating a shared query key on success
        - _Requirements: 7.2, 8.7_

- [x] 11. Request list screen
    - [x] 11.1 Build the list page
        - `CommonSearchBox` with four elements: date range, requester (`select-remote` against `ADMIN_MASTER_USER_SEARCH` with `permission=manager.auth`), leave type select, status select
        - Default the date range to the current month and the other three to all
        - `DDataTable` columns: requester, type, dates, day count, submitted at, status, decider, decided at; `-` for null decision fields; an Overlap_Warning badge where flagged
        - `noPermission` state driven by `usePermissions(['ADMIN_LEAVE_MANAGE'])`, with the query disabled when absent
        - _Requirements: 1.4, 7.2, 7.3, 7.9, 7.10, 7.12, 7.13, 7.14, 7.15, 7.16_

    - [x] 11.2 Build the detail modal
        - `partials/request_detail_modal.tsx`: full reason, image thumbnails opening a full-size preview, PDF links opening a new tab, an empty-attachment state
        - Approve and reject actions only while pending; the recorded decision read-only otherwise
        - Reject opens a reason input and blocks submission until it is filled
        - Actions disabled with an explanation when the viewer is the requester
        - Refetch the detail before opening so attachment URLs are never stale
        - _Requirements: 8.6, 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7, 9.8_

    - [x] 11.3 Test the list screen and modal
        - List: renders all columns; `-` on pending decision fields; no-permission state issues no query; approve calls the mutation and invalidates; reject opens the modal and sends nothing until a reason is entered
        - Modal: image thumbnail and PDF link render; empty state with no attachments; decided request hides the actions; requester-as-viewer disables them
        - _Requirements: 1.4, 7.3, 8.6, 9.2, 9.3, 9.4, 9.5, 9.8_

- [x] 12. Holiday calendar screen
    - [x] 12.1 Build the holiday page
        - Year selector bound to the URL query, defaulting to the current year
        - Table of holidays with a source badge distinguishing library from manual
        - Empty state offering generation; generation button hidden once rows exist
        - Add/edit modal, delete with confirmation, and a weekend switch that saves immediately with a confirmation notification
        - _Requirements: 6.1, 6.2, 6.3, 6.5, 6.7, 6.8, 6.11_

    - [x] 12.2 Test the holiday page
        - Generation offered on an empty year and hidden once populated; the weekend switch calls the settings mutation
        - _Requirements: 6.2, 6.8_

- [ ] 13. Checkpoint — screens working against the real API
    - Run `pnpm test` in `frontend/`
    - Click through both screens against a seeded database: submit a request via the API, filter for it, approve it, reject another, and confirm a dateless expense claim appears under the default filter.

### Phase 5: Translation and Final Validation

- [x] 14. Translations
    - [x] 14.1 Run the project translation flow
        - Confirm every user-facing string already uses `t(...)`; no hardcoded text
        - Run `pnpm trans:prepare`, fill `vi` and `ja` in `.translation-workflow/translation-batch.json` without touching `key`, `en`, or `sources`
        - Leave type labels read as the localized name followed by the Japanese term, e.g. `Nghỉ phép năm (有休)`
        - Run `pnpm trans:apply`, then `pnpm trans:cleanup`
        - _Requirements: 7.9, 9.1_

- [ ] 15. Code Quality
    - [x] 15.1 Frontend: run `pnpm lint:fix`, then `pnpm prettier`, then `pnpm typecheck` in `frontend/`
    - [ ] 15.2 Backend: run `npm run prettier` and `npm run pint` in `backend/`
    - [ ] 15.3 Run the full backend suite: `docker compose exec php php artisan test --parallel`
    - [ ] 15.4 Report any deliberately uncovered code to the user with the reason, per the project's test coverage rule

## Notes

**Ordering rationale.** Phases 1–2 exist before the leave request because `LeaveDayCalculator` is the feature's only novel logic and its output is frozen into every request row. Getting it wrong after requests exist means bad data, not just bad code — the snapshot rule offers no way back. Everything from Phase 3 onward follows an in-repo template and carries far less risk.

**Risks.**

- *Yasumi data quality for future years.* The library ships statutory holidays, but Japanese substitute holidays and one-off imperial dates have shifted historically. Tests bind a fake provider precisely so the suite does not depend on the library's future-year data; verify a real generated year against an external source once by hand.
- *On-demand generation inside a transaction.* Task 2.1 has the calculator triggering writes. This is deliberate — see the design — but it means a request creation can fail because of a calendar problem. Task 6.3 must assert the whole thing rolls back cleanly.
- *The dual-mode date filter.* Task 5.2's `where` group is easy to get subtly wrong in a way that only shows on dateless rows. Task 7.3's regression guard is the specific test that catches it; do not skip it.
- *`has_overlap` performance.* If the one-query-per-page approach turns out awkward against the real schema, prefer a second query over a correlated subquery per row — never fall back to per-row lookups.

**Conventions.** All PHPUnit methods use camelCase per the project rule. Classes are imported with `use` statements, never referenced inline as FQCNs. Business logic lives in invokable Action classes returning `ActionResult`; controllers stay thin. New frontend pages follow the `admin/fixed-schedule/change-requests` layout: `index.page.tsx`, `api.ts`, `partials/`, and colocated `.test.tsx` files.

**Deliberately out of scope**, per the requirements: notifications, 有休 balance tracking, editing or cancelling a submitted request, CSV export, a monetary amount field on expense claims, the mobile submission UI, and any attachment retention policy.
