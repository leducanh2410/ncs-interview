# Implementation Plan: Attendance Reconciliation

## Overview

Build foundation-first, then vertical backend slices with tests immediately after each slice, then Admin_Web, then translations, then repo checks. Do not edit `WorkHistoryDetailExport.php` or `WorkHistoryService::buildDetailExportData` (DGFC_132 / MR 304 conflict surface). Export Check_In_ID through the new `ReconciliationWorkHistoryExport` wrapper only.

### Implementation Strategy
- Land permission + schema before any action so HTTP tests can 403/persist against real tables.
- Parser and matcher are independently unit-tested before they are composed in `CreateReconciliationBatchAction`.
- Export wrapper is unit-tested against both “inner headings lack Check_In_ID” (current `dev`) and “inner already has Check_In_ID” (post-DGFC_132 test double) **before** the HTTP export endpoint exists.
- Admin_Web starts only after create / patch / confirm / export APIs are stable.
- Translations follow `CLAUDE.md`: `t(...)` in code first, then `pnpm trans:prepare` / fill vi+ja / `pnpm trans:apply`.

## Implementation Plan

### Phase 1: Foundation — Permission, Schema, Enums

- [x] 1. Permission `reconciliation.work`
    - [x] 1.1 Add `PermissionName::ReconciliationWork = 'reconciliation.work'` in `backend/app/Enums/PermissionName.php` (own `reconciliation` section, not under work_history)
        - _Requirements: 1.1_
    - [x] 1.2 Add `RECONCILIATION_WORK: { value: 'reconciliation.work', text: 'Reconciliation Work' }` in `frontend/src/enums/permission_name.enum.ts`
        - _Requirements: 1.1_
    - [x] 1.3 Register scanner keys `t('role|reconciliation')` and `t('role|reconciliation.work')` in `frontend/src/locales/for_scanner.ts`
        - _Requirements: 1.1_
    - [x] 1.4 Migration: `firstOrCreate` permission `reconciliation.work`, `syncWithoutDetaching` on `RoleId::GlobalAdmin` and `RoleId::Admin`, then `flushCache()` (copy `2026_08_24_000001_grant_work_history_edit_request_process_permission.php`)
        - _Requirements: 1.2_
    - [x] 1.5 Do **not** add `reconciliation` to `GetMasterDataAction` workplace_access prefixes
        - _Requirements: 1.5_

- [x] 2. Error codes
    - [x] 2.1 Add `RECONCILIATION_*` cases to `backend/app/Enums/ErrorCode.php` and matching `frontend/src/enums/error_code.enum.ts`: `UNKNOWN_SOURCE`, `INVALID_FORMAT`, `NO_PUNCH_ROWS`, `BATCH_NOT_DRAFT`, `DECISIONS_INCOMPLETE`, `STALE`, `NOTHING_TO_EXPORT`
        - _Requirements: 3.1, 3.3, 3.4, 8.6, 9.2, 9.3, 10.5, 11.2_

- [x] 3. Schema and models
    - [x] 3.1 Migration `reconciliation_batches`: `source`, `original_filename`, `original_file_path`, `export_file_path` nullable, `company_label` nullable, `status` default `draft`, integer counts (`punch_count`, `skipped_non_punch_count`, `matched_count`, `differs_count`, `unmatched_count`, `manual_map_count`), `uploaded_by` FK users, `confirmed_by/at` nullable, `exported_by/at` nullable, timestamps
        - _Requirements: 2.2, 9.1, 11.3_
    - [x] 3.2 Migration `reconciliation_rows`: `batch_id` FK cascade, `source_row_index`, `external_name`, `work_date`, `external_check_in/out` datetime, `external_break_minutes` unsigned int, `raw` JSON, `match_status`, `match_method` nullable, `skipped` bool default false, `user_id` nullable, `candidate_user_ids` JSON nullable, `check_in_id` nullable, `workplace_id` nullable, `compare_status`, `has_pending_edit_request` bool, snapshot columns (`snapshot_dgf_check_in/out`, `snapshot_effective_break`, `snapshot_break_column`), `decision_check_in/out/break` nullable, timestamps; indexes `(batch_id, match_status)`, `(batch_id, skipped)`, `user_id`, `check_in_id`
        - _Requirements: 4.3, 5.6, 6.2, 7.5, 8.5, 9.1_
    - [x] 3.3 Models `ReconciliationBatch` / `ReconciliationRow` with relations in design.md; enums `ReconciliationBatchStatus`, `ReconciliationSource` (`AmazonKadou = 'amazon_kadou'`), `ReconciliationMatchStatus`, `ReconciliationCheckInStatus`, `ReconciliationCompareStatus`, `ReconciliationDecision`
        - _Requirements: 2.2, 3.2_
    - [x] 3.4 TypeScript types `frontend/src/types/models/reconciliation_batch.d.ts` and `reconciliation_row.d.ts` matching design.md
        - _Requirements: 2.2_

- [x] 4. Checkpoint — permission + schema
    - Run migrations; confirm Role Management would show group `reconciliation` once a role is loaded; `workplace_access` JSON has no `reconciliation` key.

### Phase 2: Source_Parser — Amazon first

- [x] 5. Parser contract
    - [x] 5.1 `App\Services\Reconciliation\ReconciliationSourceParser` interface and `CanonicalRow` DTO (`external_name`, `work_date`, `check_in`, `check_out`, `break_minutes`, `raw`)
        - _Requirements: 3.2_
    - [x] 5.2 `ReconciliationParserRegistry` mapping source string → parser; unknown source throws / returns `RECONCILIATION_UNKNOWN_SOURCE`
        - _Requirements: 3.1_
    - [x] 5.3 `ReconciliationFormatException` carrying expected vs found for 422 payloads
        - _Requirements: 3.3_

- [x] 6. `AmazonKadouParser`
    - [x] 6.1 Detect header row by contains/prefix: 氏名, 作業日, 実打刻 (start `～` end), 休憩; do not require sheet name `DGF人材開発`
        - _Requirements: 4.1_
    - [x] 6.2 Skip `計` and rows missing both 実打刻 start and end; count them as `skipped_non_punch_count`
        - _Requirements: 4.2_
    - [x] 6.3 Map 氏名 (collapse Unicode spaces), 作業日 (Excel serial/DateTime → `Y-m-d`), 実打刻 start/end (keep overnight calendar datetime), 休憩 day-fraction × 1440 rounded int; put 予定/作業 into `raw` only; `company_label` from 会社名
        - _Requirements: 4.3, 4.4, 4.5_
    - [x] 6.4 Emit two Canonical_Rows when 氏名+Work_Date duplicate; do not merge
        - _Requirements: 4.7_

- [x] 7. Unit tests — `AmazonKadouParserTest` + `ReconciliationParserRegistryTest`
    - [x] 7.1 Fixture under `backend/tests/Fixtures/reconciliation/` (generated workbook equivalent to `20260825_DGF人材開発.xlsx`: 48 punch / 208 skip / date 2026-08-25). Do not commit the original PII-heavy client file if a generator can reproduce layout
        - `testParsesSampleCountsAndWorkDate`
        - `testParsesOvernightPunchDatetimes`
        - `testConvertsBreakDayFractionTo60Minutes`
        - `testCollapsesIdeographicSpaceInName`
        - `testSkipsRowsWithoutPunchLog`
        - `testDoesNotPutScheduledOrWorkTimeIntoCompareFields`
        - `testRejectsMissingHeaders`
        - `testStoresCompanyLabelWithoutUsingItAsWorkplace`
        - `testKeepsDuplicateNameAndDateAsTwoRows`
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 3.3_
    - [x] 7.2 Registry: unknown source errors; fake second parser (`fake_test`) can be bound in tests
        - _Requirements: 3.1, 3.6_

- [x] 8. Checkpoint — parser
    - `php artisan test --filter AmazonKadouParserTest` and `--filter ReconciliationParserRegistryTest`

### Phase 3: Match and compare

- [x] 9. Name normalization + matcher
    - [x] 9.1 Helper: NFKC, hiragana→katakana, collapse all Unicode spaces, trim, uppercase Latin; no romaji
        - _Requirements: 5.1_
    - [x] 9.2 Exact unique match: 1 token → first_name_kana / last_name_kana / Short_Name; 2+ tokens → Name_Kana, reversed given+family, Short_Name. Unique → `exact`; 2+ → `ambiguous`
        - _Requirements: 5.2, 5.3_
    - [x] 9.3 Similar: every Amazon token substring of Name_Kana or Short_Name (or reverse); rank users with Check_In on Work_Date first; cap stored candidate ids at 10; never auto-map
        - _Requirements: 5.4, 5.5_
    - [x] 9.4 Nepal: Short_Name accessor as-is (null column → full `name`)
        - _Requirements: 5.8_

- [x] 10. Check_In attach + field compare
    - [x] 10.1 Lookup mapped user's Check_Ins: `Shift.date = work_date`, else `Shift.adjusted_date`; honor `ShouldShowTestWorkplacesAction`; **no** `applyWorkplaceFilter` / `getWorkplaceIdsByPermission`
        - _Requirements: 1.4, 1.5, 6.1, 6.7_
    - [x] 10.2 1 Check_In → attach + workplace from shift; 0 → `no_attendance`; 2+ → `multiple` (admin pick)
        - _Requirements: 6.2, 6.3, 6.4_
    - [x] 10.3 Compare minutes of raw `check_in_time` / `check_out_time` (ignore seconds, ignore rounded columns); null DGF out vs Amazon out → differs; Effective_Break_Minutes = `check_ins.break_minutes ?? shift.break_minutes ?? workplace_shift.break_duration ?? 0`
        - _Requirements: 6.5, 6.6, 7.1, 7.2, 7.3, 7.4, 7.5_
    - [x] 10.4 Matching fields default `decision_* = dgf`; differing fields `null`; flag `has_pending_edit_request`
        - _Requirements: 7.5, 8.2, 8.7_

- [x] 11. Unit tests — matcher + compare
    - [x] 11.1 `ReconciliationNameMatcherTest`: exact kana 2-token; 1-token given; 1-token shared family → ambiguous; reversed order; Short_Name Latin; Nepal fallback; similar no auto-map; NFKC/hiragana/spaces; no kana↔Latin false exact
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.8_
    - [x] 11.2 `ReconciliationFieldCompareTest`: minute equality ignores seconds; rounded ignored; template-60 vs Amazon-60 match; null checkout vs Amazon out differs
        - _Requirements: 7.1, 7.2, 7.3, 6.6_

- [x] 12. Checkpoint — match
    - Run matcher + compare test classes.

### Phase 4: Create batch API

- [x] 13. `CreateReconciliationBatchAction`
    - [x] 13.1 Validate `source` + file `mimes:xlsx` `max:10240`; parse; zero punches → 422 `NO_PUNCH_ROWS` and no batch/file
        - _Requirements: 2.4, 3.3, 3.4_
    - [x] 13.2 Persist Draft + original file on private disk `reconciliation/{id}/original.xlsx`; rollback batch and file together on failure
        - _Requirements: 11.4, NFR reliability_
    - [x] 13.3 Run matcher; denormalize list counts; duplicate 氏名+date rows get `duplicate_external`
        - _Requirements: 2.2, 4.7, 5, 6_

- [x] 14. HTTP create + list + show + original download
    - [x] 14.1 Routes under `/manage/work-history-reconciliations`, middleware `permission:reconciliation.work` (no team id)
        - _Requirements: 1.3_
    - [x] 14.2 `POST /` multipart; `GET /` paginated list (filters source, uploaded_by, dates); `GET /{id}` with rows; `GET /{id}/original`
        - _Requirements: 2.2, 2.3, 2.5, 11.3_
    - [x] 14.3 Form requests for upload + index filters
        - _Requirements: 2.4_

- [x] 15. Feature tests — `CreateReconciliationBatchTest` + `ReconciliationPermissionTest`
    - Happy path Draft + file + rows
    - Format error: no batch, no orphan file
    - Zero punches: no batch
    - Duplicate bytes → second independent batch
    - 403 without permission
    - Role granted only on workplace A still matches Check_In at workplace B
    - Migration grants Global Admin + Admin
    - `workplace_access` has no `reconciliation` key
    - Upload → no `check_ins` / edit-request writes
        - _Requirements: 1.2, 1.3, 1.4, 1.5, 3.3, 3.4, 11.4, 12.1, 12.2_

- [x] 16. Checkpoint — create API
    - `php artisan test --filter ReconciliationPermissionTest --filter CreateReconciliationBatchTest`

### Phase 5: Draft decisions and Confirm

- [x] 17. `UpdateReconciliationRowAction` + bulk-decide
    - [x] 17.1 PATCH row: skip, `user_id` (manual map → re-run Check_In lookup + compare), `check_in_id` pick (must belong to user + Work_Date), field decisions `amazon`|`dgf`; 409 if not Draft
        - _Requirements: 5.6, 5.7, 6.3, 8.3, 8.5, 8.6, 9.3_
    - [x] 17.2 `POST /{id}/bulk-decide` `{ side: amazon|dgf }` on unresolved differing fields only
        - _Requirements: 8.4_
    - [x] 17.3 Optional `DELETE /{id}` Draft only (delete DB + original file); 409 if Confirmed
        - _Requirements: 11.5_

- [x] 18. User search
    - [x] 18.1 `GET /manage/work-history-reconciliations/users?q=&work_date=` — Reconciliation_Work, no workplace filter; search staff_no / name / kana / short_name; include Check_Ins on `work_date`
        - _Requirements: 5.5_

- [x] 19. `ConfirmReconciliationBatchAction`
    - [x] 19.1 Incomplete → 422 `DECISIONS_INCOMPLETE` + `unfinished_row_ids`. Unmatched/similar/ambiguous/duplicate_external must be mapped or skipped; multiple Check_Ins must be picked; every differing field decided. `no_attendance` may confirm without skip
        - _Requirements: 8.3, 9.2_
    - [x] 19.2 Success: `confirmed`, snapshots of DGF times + Effective_Break + raw `break_minutes` column; re-query pending edit requests; idempotent re-confirm
        - _Requirements: 9.1, 9.4, 8.7_

- [x] 20. Feature tests — `UpdateAndConfirmReconciliationBatchTest`
    - Reach Confirm through real upload + PATCH (no hand-set `status = confirmed` except after this test proves the path)
    - Manual map + Check_In pick + decisions persist
    - Confirm incomplete 422
    - Confirm freezes; later PATCH 409
    - Confirm retry idempotent
    - Skip unmatched allows confirm
    - Multiple Check_Ins require pick
    - Overnight `adjusted_date` match
    - Absent Check_In still comparable
    - Pending request flagged
        - _Requirements: 5.6, 5.7, 6.1, 6.3, 6.5, 8.6, 8.7, 9.1, 9.2, 9.3_

- [x] 21. Checkpoint — confirm
    - Run update/confirm feature tests.

### Phase 6: Export (merge-safe Check_In_ID)

- [x] 22. `ReconciliationWorkHistoryExport` wrapper
    - [x] 22.1 **Do not modify** `backend/app/Exports/WorkHistoryDetailExport.php` or `WorkHistoryService::buildDetailExportData`
        - _Requirements: 10.11_
    - [x] 22.2 New `App\Exports\ReconciliationWorkHistoryExport`: construct inner `WorkHistoryDetailExport($groupByWorkplace)`; if headings already contain `Check_In_ID`, delegate headings/collection/widths/styles; else append heading, append `check_in_id` on data rows (lockstep with source items), hide + number-format last column, bold that header
        - _Requirements: 10.1, 10.9, 10.10_
    - [x] 22.3 Overlay mapper (new action, **not** a change to `buildDetailExportData`): copy current export field list, always set `check_in_id`, overlay merged in/out via `toDayFraction` and merged break minutes; do not filter `whereNotNull('check_out_time')` or `WorkHistoryExport` permission
        - _Requirements: 10.2, 10.3, 10.8_

- [x] 23. Unit tests — wrapper both heading states
    - Inner without Check_In_ID (live `WorkHistoryDetailExport` on this branch) → exactly one hidden last Check_In_ID, data-row ids match, A–P headers unchanged
    - Inner already with Check_In_ID (test double wrapping headings/collection) → still exactly one Check_In_ID column
        - _Requirements: 10.9, 10.10, 10.11_

- [x] 24. `ExportReconciliationBatchAction` + HTTP
    - [x] 24.1 Confirmed only; stale check vs snapshot raw times + `break_minutes` **column** → 409 `STALE`; pending request omitted at export time; include only amazon-accepted diffs with Check_In_ID
        - _Requirements: 10.2, 8.7, 11.1, 11.2_
    - [x] 24.2 One workplace → xlsx; several → zip of per-workplace xlsx; store `reconciliation/{id}/export.xlsx|zip`; GET download; 422 `NOTHING_TO_EXPORT`
        - _Requirements: 10.4, 10.5, 10.7_
    - [x] 24.3 POST `/{id}/export` then GET `/{id}/export`
        - _Requirements: 10.7, 11.1_

- [x] 25. Feature tests — `ExportReconciliationBatchTest`
    - Lifecycle: upload → decide Amazon on one field → confirm → export (real actions)
    - One workplace xlsx: A–P + hidden Check_In_ID; G/H day-fractions
    - Two workplaces → zip of two single-workplace files
    - Keep-all-DGF / unmatched / no Check_In / pending omitted
    - Nothing-to-export 422
    - If `PreviewBulkImportWorkHistoryEditRequestsAction` exists on the branch: import preview old DGF / new Amazon. If not, parse workbook only
    - Upload→confirm→export does not insert edit requests or change `check_ins`
    - Re-export OK when live equals snapshot; after a **real** approve-edit on that Check_In, re-export 409 stale
    - Export succeeds **without** `work_history.export` or `work_history.process_edit_request`
        - _Requirements: 10.2, 10.3, 10.4, 10.5, 10.6, 10.7, 10.8, 11.1, 11.2, 12.1, 12.2_

- [x] 26. Checkpoint — export
    - Wrapper unit tests + export feature tests green. `git diff` must not include `WorkHistoryDetailExport.php` or `buildDetailExportData`.

### Phase 7: Admin_Web

- [x] 27. Routing and nav
    - [x] 27.1 `RouteConfig.MANAGE_WORK_HISTORY_RECONCILIATION` path `/manage/work-history/reconciliation`, permission `reconciliation.work`; detail `/manage/work-history/reconciliation/[id]`
        - _Requirements: 2.1, 2.5, 2.6_
    - [x] 27.2 Sidebar Check in group after edit-requests: customName `Attendance reconciliation` (user-facing via `t('route|...')`)
        - _Requirements: 2.1_
    - [x] 27.3 `ApiConfig` entries for list/create/show/patch/bulk-decide/confirm/export/original/users
        - _Requirements: 2, 8, 9, 10_

- [x] 28. List + upload
    - [x] 28.1 `frontend/src/pages/manage/work-history/reconciliation/index.page.tsx`: breadcrumbs, title, `CommonSearchBox` (source, uploader, dates), `DDataTable` columns per Requirement 2.2, “Đối soát mới”
        - _Requirements: 2.2, 2.3, 2.4_
    - [x] 28.2 Upload modal: source select (Amazon 稼働実績), `.xlsx` picker, client type/size check
        - _Requirements: 2.4_
    - [x] 28.3 Hide page/nav without permission (same pattern as other manage pages)
        - _Requirements: 1.3_

- [x] 29. Review screen
    - [x] 29.1 `frontend/src/pages/manage/work-history/reconciliation/[id].page.tsx` full page: filters all / differs / matched / unmatched / no DGF attendance / pending; Amazon vs DGF per field; overnight `+1`; bulk accept; skip; Confirm disabled until complete
        - _Requirements: 7.6, 7.7, 8.1, 8.2, 8.3, 8.4_
    - [x] 29.2 Map-user modal: candidates + search (`users` endpoint), show staff_no / avatar / kana / Check_Ins that day
        - _Requirements: 5.5, 5.6_
    - [x] 29.3 Check_In picker when `check_in_status = multiple`
        - _Requirements: 6.3_
    - [x] 29.4 Confirmed: read-only, Export/Re-export, copy that Import is a separate step needing `work_history.process_edit_request`; stale 409 surfaced
        - _Requirements: 9.3, 9.4, 10.7, 11.2, 12.3_
    - [x] 29.5 Pending-edit-request badge; all new strings via `t(...)`
        - _Requirements: 8.7, NFR usability_

- [x] 30. Frontend tests
    - [x] 30.1 `index.page.test.tsx`: renders list; hidden without permission; upload requires source + xlsx
        - _Requirements: 1.3, 2.4_
    - [x] 30.2 `[id].page.test.tsx`: radios; Confirm disabled while unresolved; bulk accept; confirmed read-only + export; `+1` overnight; map modal search present
        - _Requirements: 8.1, 8.3, 8.4, 7.6, 5.5, 9.3_

- [x] 31. Checkpoint — UI
    - `pnpm` tests for the new pages.

### Phase 8: Translations

- [x] 32. Translation workflow
    - [x] 32.1 Ensure every new user-facing string uses `t(...)` (including Role group/permission labels and route name)
        - _Requirements: NFR usability_
    - [x] 32.2 In `./frontend`: `pnpm trans:prepare`; fill `.translation-workflow/translation-batch.json` `vi` and `ja` only; `pnpm trans:apply`
        - _Requirements: NFR usability_

- [x] 33. Checkpoint — i18n
    - No hardcoded Admin_Web copy in the new pages; `vi`/`ja` keys present for new strings.

### Phase 9: Final Validation

- [x] 34. Regression and DoD tests
    - [x] 34.1 Fake second parser drives match + export with no Amazon types in that path
        - _Requirements: 3.5, 3.6_
    - [x] 34.2 Confirm `git diff -- backend/app/Exports/WorkHistoryDetailExport.php backend/app/Services/Manage/WorkHistoryService.php` is empty (except if `toDayFraction` is only *called*, not edited)
        - _Requirements: 10.11_
    - [x] 34.3 Run the reconciliation PHPUnit filter set; then affected frontend tests
        - _Requirements: Success Criteria_

- [x] 35. Code Quality
    - [x] 35.1 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` in `./frontend`
    - [x] 35.2 Backend: `npm run prettier` and `npm run pint` in `./backend`
    - [x] 35.3 PHPUnit methods camelCase (`testParsesOvernightPunchDatetimes()`, not snake_case)

## Notes

- **Ordering:** permission/schema → parser (testable without DB users) → matcher → create API → decisions/confirm → export wrapper (the merge-risk item, isolated) → UI → i18n.
- **DGFC_132 conflict avoidance:** never patch `WorkHistoryDetailExport` or `buildDetailExportData`. Task 23 proves both heading states before the HTTP export exists.
- **Import lifecycle:** if MR 304 is not on the branch, skip calling `PreviewBulkImportWorkHistoryEditRequestsAction`; still assert workbook shape. When it lands, add the preview assertion without changing the wrapper.
- **PII:** prefer a generated Amazon-layout fixture over committing the real `20260825_DGF人材開発.xlsx`.
- **Do not** attach `reconciliation.work` to workshop-leader roles in seed data beyond Global Admin / Admin.
- Optional Draft delete (11.5 MAY) is task 17.3; drop it only if product later says no.
- Browser verification of Admin_Web is required before calling the UI done (user rule); if no browser tools, say what was covered by tests instead.
