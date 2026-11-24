# Implementation Plan: Admin Residence Card Scan Upload & Reconciliation

## Overview

The build goes **shared plumbing → capture pipeline → read surface → web UI**. Phase 1 holds everything that touches existing code: the `OcrImagePreparer`, `CurrentPaperItemSelector`, and comparison-key extractions. They land first because doing them under the existing employee OCR tests means any mistake surfaces immediately rather than inside new, untested code. The capture pipeline (upload → job → submit) is completed end to end before any reconciliation work starts, so the comparison is built against real submitted rows rather than fixtures — which is also what the repository's test rules demand. The reconciliation read layer is finished and tested on the API before the web page exists, so the page is never written against a moving contract. The admin app (`dgf-connect-app-admin`, spec `20260911-admin-residence-card-scan-upload-mobile`) consumes phases 2–3; it cannot start until the capture endpoints are deployed to a shared environment.

### Implementation Strategy

- Reuse, do not re-implement: `OpenRouterOcrService`, `OcrFieldNormalizer`, `ValidateOcrRequiredFieldsAction`, and the `ExtractDocumentStepOcrJob` job shape are the employee flow's proven parts and are used as-is or mirrored exactly.
- Keep the independence rule enforceable: no task in this plan writes to `user_papers`, `user_paper_items`, `user_paper_steps`, `user_paper_ocr_results`, `user_infos`, or `users`. A task that finds itself needing to is a task that has gone wrong.
- The employee side is the profile: `users` names plus `user_infos` residence fields at the slot `ResidenceSlotResolver` calls current. No OCR result of an employee image is read for any compared value; the employee's scan image is displayed and nothing more.
- Every status transition is a conditional update guarded on the expected status, never a read-then-write.
- Only the admin side ever passed through `OcrFieldNormalizer`. Every compared field must be brought to one shape on **both** sides before it is judged, or the surface reports differences that exist only because the profile was typed rather than read.
- The OCR budget is enforced where the money is spent — at upload — not only at submit.
- Write the lifecycle test (Task 5.1) before the fixture-based tests, so the factory states it legitimises already exist when the later tests reach for them.
- Follow the Action pattern for all business logic and import every class with `use`.

## Implementation Plan

### Phase 1: Shared plumbing

- [x] 1. Permission and shared OCR pieces
    - [x] 1.1 Add the `user.paper.upload` permission
        - `PermissionName::UserPaperUpload = 'user.paper.upload'` beside the other `UserPaper*` cases
        - Migration `2026_09_11_000001_add_user_paper_upload_permission.php` copying the `grant_reconciliation_work_permission` shape: hardcoded name, `firstOrCreate`, `syncWithoutDetaching` to Global Admin + Admin, `flushCache()`, reversible `down()`
        - Frontend `src/enums/permission_name.enum.ts`: union member `USER_PAPER_UPLOAD` and entry `{ value: 'user.paper.upload', text: 'User Paper Upload' }`
        - _Requirements: 1.1, 1.2_

    - [x] 1.2 Extract `OcrImagePreparer`
        - New `App\Services\Ocr\OcrImagePreparer` holding the body of `OcrResultCacheService::prepareImage()`, keyed on a storage path instead of a `UserPaperItem`
        - Inject it into `OcrResultCacheService` and delegate; delete the private method
        - Run the existing employee OCR test suite unchanged as the regression check
        - _Requirements: reuse constraint in Technical Constraints_

    - [x] 1.3 Extract the shared comparison key
        - Lift `DocumentFieldComparator::comparisonKey()` into a small shared helper (`App\Support\Ocr\FieldComparisonKey`) and have `DocumentFieldComparator` call it, so employee-vs-employee and admin-vs-employee comparison can never drift
        - Existing `DocumentFieldComparator` tests must pass untouched
        - _Requirements: 7.3_

    - [x] 1.4 Extract `CurrentPaperItemSelector`
        - New `App\Support\Papers\CurrentPaperItemSelector` holding the rule from `ExtractPapersOcrAction::eligibleItemsByType()`, taking an already-loaded item collection plus types so it serves both a single user and a batch
        - `eligibleItemsByType()` keeps its own query and becomes a wrapper around it; behaviour unchanged
        - Here rather than in Phase 3, so the move runs under the existing `UserPaperOcrTest` before anything new depends on it
        - Used by the reconciliation surface only to pick the employee scan image the popup displays; it feeds no compared value
        - _Requirements: 8.7, 10.1_

### Phase 2: Capture pipeline

- [x] 2. Storage and model
    - [x] 2.1 Create the table and model
        - Migration `2026_09_11_000002_create_admin_paper_uploads_table.php` with every column, index, and FK from the design (`restrictOnDelete` on `user_id`, `nullOnDelete` on `uploaded_by_id` / `submitted_by_id`)
        - `AdminPaperUploadStatus` enum using `EnumTrait`
        - `App\Models\AdminPaperUpload` with casts, relations, `MAX_OCR_RUNS`, `isDraft()`, `canRetakeForUnreadableScan()`, `allowsManualConfirmation()`
        - `AdminPaperUploadFactory` with a draft default plus `ocrReady()` and `submitted()` states
        - _Requirements: 2.5, 5.6, 6.1, 6.2_

- [x] 3. Upload and OCR
    - [x] 3.1 `UploadAdminPaperAction`
        - Transactional: lock the admin's existing draft for this employee and paper type **first**, so the budget check below is race-free
        - Refuse the retake when that draft is `ocr_failed` with `ocr_run_count >= MAX_OCR_RUNS`: 422 `AdminPaperUploadOcrUnreadable` before any file is stored. The budget is spent by the upload, not the submit, so guarding only the submit leaves the paid call open — this is the guard `UploadDocumentStepAction` already applies through `UserPaperStep::canReplaceUnreadableUpload()`
        - Otherwise replace the draft, carry `ocr_run_count` forward, delete the replaced file only after commit
        - Store at `admin-paper-uploads/{user_id}` with `width: 2000`
        - On any throw, delete files written during the attempt before rethrowing
        - Dispatch `ExtractAdminPaperUploadOcrJob` with `afterCommit()`
        - Return `recent_submission` — the employee's most recent Submitted_Upload from the last 24 h (submitter + timestamp), `null` when there is none — so the app can warn that a colleague just scanned this person; a notice, never a block
        - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.6, 3.8_

    - [x] 3.2 `UploadAdminPaperRequest` and controller
        - `AdminPaperUploadController::store()`, route `POST admin/users/{user}/paper-uploads` behind `permission:user.paper.upload`
        - Validate `paper_type` `in:foreigner_card_scan`, `file` image mime + 10 MB, rejecting before anything is stored
        - _Requirements: 3.1, 3.5, 3.7, 1.3_

    - [x] 3.3 `ExtractAdminPaperUploadOcrJob`
        - `tries = 2`, `timeout = 130`, `backoff() => [10]`, mirroring `ExtractDocumentStepOcrJob`
        - Guard: return unless the row exists and is still `ocr_processing`
        - `OcrImagePreparer` → `OpenRouterOcrService::extract(GROUP_RESIDENCE_CARD_SCAN, [...], retryWithFallback: true)` → `OcrFieldNormalizer::normalize()` → `ValidateOcrRequiredFieldsAction`
        - Unreadable → store partial fields + `OcrRequiredFieldsMissingException::ERROR_CODE`, status `ocr_failed`, no retry spent
        - Other throwables rethrown; `failed()` writes `ocr_failed` with a truncated message and logs
        - Every write conditional on `status = ocr_processing`
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

    - [x] 3.4 Poll endpoint
        - `GET admin/paper-uploads/{adminPaperUpload}` returning status, fields, warnings, error, `ocr_run_count`, `max_ocr_runs`, `can_retake`, `allows_manual_confirmation`
        - 403 unless the caller is the uploader — submitted rows included; other admins read those through the reconciliation detail endpoint
        - _Requirements: 4.6, 4.7_

- [x] 4. Confirm, submit, discard
    - [x] 4.1 `SubmitAdminPaperUploadAction` + request
        - Ordered guards producing the three new `ErrorCode` cases, with `ocr_run_count` / `max_ocr_runs` on the unreadable error
        - Whitelisted field validation per the design's table; unknown keys → 422
        - `residence_status_raw` carried over from `ocr_fields` by the server on the OCR path (client value ignored), accepted as free text only on the manual-fallback path; stored, never compared
        - Conditional update on `submitted_at IS NULL`; 0 rows affected → already-submitted error
        - Persist `confirmed_fields`, `confirmed_without_ocr`, `submitted_by_id`, `submitted_at`
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 6.2_

    - [x] 4.2 `DiscardAdminPaperUploadAction`
        - Owner-only, draft-only; row deleted, file deleted after commit; 403 on a submitted row or another admin's draft
        - _Requirements: 5.8_

    - [x] 4.3 Stale draft prune
        - `App\Jobs\DeleteStaleAdminPaperUploadDrafts` deleting drafts older than 24 h with their files, in chunks, following `DeleteStaleShiftPlanDrafts`
        - Register in `routes/console.php` at `dailyAt('02:16')`
        - _Requirements: 5.9_

- [x] 5. Capture pipeline tests
    - [x] 5.1 Lifecycle test through the real endpoints
        - upload → run the queued job → poll → submit → assert stored row and audit fields; then a second upload for the same employee → submit → assert both rows exist with the newest current
        - No fixture inserts an intermediate status; this test is what legitimises the factory states used elsewhere
        - _Requirements: 3.6, 5.1, 6.1, 6.2_

    - [x] 5.2 State × event matrix
        - Every cell of the design's matrix, including the surprising ones: the OCR job completing after a submit, after a discard, and after a retake replaced the draft — each must leave the row untouched
        - Retake budget: three unreadable reads then a manual submit succeeding with `confirmed_without_ocr = true`; a manual submit attempted at count < 3 rejected
        - A fourth retake at count = 3 is refused at **upload**: assert 422, no new file on disk, and `ocr_run_count` still 3 — a test checking only the status code would pass an implementation that stored the image and spent the read before refusing
        - Two admins, one employee: each gets their own draft, both submit, both land in history with the newest current, and the second upload response carries the recent-submission warning
        - _Requirements: 3.8, 4.5, 5.4, 5.5, 5.6, 5.7_

    - [x] 5.3 Transaction failure and cleanup
        - Force a failure after the file is stored and before commit: no row, no orphan file
        - Force a failure on a retake: the previous draft's file still exists and its row is intact
        - _Requirements: 3.3, 3.4_

    - [x] 5.4 Independence assertions
        - After a full capture lifecycle, assert `user_papers`, `user_paper_items`, `user_paper_steps`, `user_paper_ocr_results`, `user_infos`, and `users.paper_status` are byte-for-byte unchanged
        - Assert the employee document-step overview response is identical before and after
        - _Requirements: 2.1, 2.2, 2.3, 2.4_

    - [x] 5.5 Permission matrix
        - Each capture endpoint with no permission, with `user.paper.list` only, with `user.paper.upload`, and as a different admin
        - _Requirements: 1.3, 1.5, 4.7, 5.8_

### Phase 3: Reconciliation read layer

- [x] 6. Resolution and comparison
    - [x] 6.1 `ResolveEmployeeSideAction`
        - Batch resolution for many user ids, two queries: one joining `users` to `user_infos` for the compared and display-only fields, one for `foreigner_card_scan` items fed through `CurrentPaperItemSelector` (Task 1.4)
        - Current slot from `ResidenceSlotResolver::latestSlotFromCards($cards)` — the array-based entry point, since a `users` ⋈ `user_infos` join produces no `UserInfo` model for `latestSlot()` to take; it is already public and already the body `latestSlot()` delegates to, so both callers stay on one rule
        - Return all three slots as `cards` so the comparator can spot a Previous_Card_Match without another query
        - **`fields` is always returned.** No residence card in any slot sets `residence_missing` and nulls only `residence_number` / `residence_expiry_date` / `residence_status`; `name`, `birthday`, `gender`, `nationality` and the display-only fields keep their profile values. Blanking the whole employee side would hide a scanned card bearing somebody else's name on exactly the employees most likely to have a problem
        - A single null column is a null field, not a missing profile
        - Reads no `user_paper_ocr_results` row at all
        - _Requirements: 7.1, 7.2, 7.7, 8.7_

    - [x] 6.2 `AdminScanFieldComparator`
        - Compared vs display-only field lists, `unknown` handling, `mismatch_count`
        - Verdict resolved in the fixed order of Requirement 7.5 — `not_uploaded`, then `mismatched`, then `employee_data_missing`, then `matched`, else `not_comparable` — with `employee_residence_missing` returned as its own boolean whichever branch wins, so a missing card and a mismatch are both reported
        - **Both sides normalized identically before comparing**, since only the admin side passed through `OcrFieldNormalizer`: `name` upper-cased then keyed as an unordered set; `residence_number` upper-cased with spaces and hyphens stripped; `birthday` / `residence_expiry_date` formatted `Y-m-d`; the enum fields as stored
        - Previous_Card_Match: a `residence_number` mismatch that equals another slot stays a mismatch and still counts, but carries `matches_previous_slot`
        - Unit tests for every rule in the design's testing section
        - _Requirements: 7.3, 7.4, 7.5, 7.6, 7.7, 7.8_

- [x] 7. Endpoints
    - [x] 7.1 `GetPaperReconciliationListAction` + controller
        - Employee base query, workplace/shift/date filters via `shift_users` + `shifts`, keyword via the existing user search, `uploaded` / `not_uploaded` in SQL, verdict filters applied after enrichment over the candidate id set
        - `rejected` assignments excluded from the roster `whereExists` under every filter value including the default; `shift_user_status` narrows to `approved` or `pending`, omitted means both; supplied without `date` → 422
        - `workplace_id` **without** `date` filters by `workplace_users` membership, not by roster — the same reading `UserService` already gives the admin user list
        - `employee_data_missing` narrows in SQL to *all slots null* **and** *a Submitted_Upload exists*; the second half matters, because slots-null alone would sweep in employees whose verdict is `not_uploaded`. Whether anything mismatches is settled during enrichment
        - `matched` / `mismatched` / `not_comparable` filter after enrichment over the candidate id set, and require at least one of `date`, `workplace_id`, or `keyword` alongside them → 422 otherwise, since enriching a whole payroll per page request misses the 2-second budget
        - 422 when `workplace_shift_id` arrives without `workplace_id` and `date`
        - Bounded enrichment: one current-admin-scan query, one `ResolveEmployeeSideAction` call, comparison in memory
        - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7, 8.8_

    - [x] 7.2 `GetPaperReconciliationDetailAction` + controller
        - Both images, full field table, warnings from both sides, `residence_status_raw` beside the mapped status on both sides, `confirmed_without_ocr` flag, full Submitted_Upload history with recomputed verdicts, 404 for unknown users
        - `matches_previous_slot` on the `residence_number` row when it applies, and `employee_residence_missing` as its own boolean, so the client names each situation rather than re-deriving it from the verdict
        - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 6.3, 6.4, 6.5_

    - [x] 7.3 `GetPaperReconciliationFilterOptionsAction` + route ordering
        - `workplace_id` + `date` both required; declare the literal route before `/{user}`
        - _Requirements: 9.1, 9.2, 9.3, 9.4_

- [x] 8. Read layer tests
    - [x] 8.1 Filters with accumulated history
        - Seed an employee with an old Submitted_Upload, an approved `foreigner_card_scan` from a previous cycle, and a newer pending one; assert the right pair is chosen
        - Each filter individually and combined; `workplace_shift_id` alone rejected; unfiltered list returns active employees only
        - `workplace_id` without `date` filters by `workplace_users` membership: registered but not rostered that day appears; rostered there but not registered does not
        - Roster status: a `rejected` assignment never appears under `approved`, under `pending`, or under the default; default returns approved + pending; `shift_user_status` without `date` → 422
        - _Requirements: 8.2, 8.3, 8.4, 8.5, 8.8_

    - [x] 8.2 Status filter covers every verdict
        - For a seeded set, compute each employee's verdict and assert each `status` value returns exactly the employees whose verdict equals it — set against set, not a hand-written expectation of the SQL
        - The case a `whereNull` shortcut gets wrong: empty residence slots with **no** admin scan must appear under `not_uploaded`, never under `employee_data_missing`
        - `not_comparable` is reachable; a verdict filter sent without `date`, `workplace_id`, or `keyword` → 422
        - _Requirements: 8.5, Performance_

    - [x] 8.3 Verdict precedence
        - Only the cells where two criteria are true at once, since that is the only place an order can be got wrong: no Submitted_Upload + empty profile → `not_uploaded`; empty profile + name mismatch → `mismatched` with `employee_residence_missing` still true; empty profile, nothing mismatching → `employee_data_missing`; all fields `unknown` with both sides present → `not_comparable`
        - _Requirements: 7.5, 7.7, 7.8_

    - [x] 8.4 Employee side and the card history
        - Profile with no residence card in any slot → `employee_data_missing` with `employee_residence_missing` true, admin side and both images still returned, **and `name` / `birthday` / `gender` / `nationality` still carrying their profile values and verdicts** — the regression against blanking the whole employee side
        - A profile missing only `birthday` → that one field `unknown`, row not `mismatched`, `employee_residence_missing` false
        - Profile name in mixed case against an upper-case admin read → `match`; name parts split differently between the columns and the card → `match`
        - `residence_number` typed in lower case or with a hyphen on the employee's confirm screen → `match`, since `DocumentStepConfirmRules` permits both and only the admin side is normalized
        - `birthday` / `residence_expiry_date` equal but arriving in different shapes → `match` after both sides are formatted `Y-m-d`
        - Slots seeded by driving real confirmations so they rotate as production fills them: admin scans the current card → `match`; scans the card now in slot 1 after two renewals → `mismatch` with `matches_previous_slot = 1`; scans a card in no slot → plain `mismatch`
        - Equal expiry dates across slots → the compared slot is asserted against `ResidenceSlotResolver`, not a hard-coded number
        - Employee confirms a renewed card after an admin submission → next read flips to `mismatch` with the slot flag, no admin action in between
        - Assert the list and detail issue no query against `user_paper_ocr_results`
        - _Requirements: 7.1, 7.2, 7.3, 7.6, 7.7, 2.1_

    - [x] 8.5 Verdict freshness
        - After a `matched` result, drive the employee through a real re-confirmation with different card data and assert the next read returns `mismatched` without any admin action — the verdict is computed on read, never cached
        - _Requirements: 7.10_

    - [x] 8.6 Shared extractions do not drift
        - An employee holding both a pending and an older approved scan resolves to the **same** item on the employee detail page and on the reconciliation surface
        - _Requirements: 10.1, 8.1 (the current-item rule the two surfaces share)_

    - [x] 8.7 Read-layer permission matrix and query budget
        - 403 without `user.paper.list`; assert the enrichment query count does not grow with page size
        - _Requirements: 1.4, 8.7_

### Phase 4: Web admin

- [x] 9. Routing, config, and page shell
    - [x] 9.1 Route, sidebar, and API config
        - `ADMIN_USER_PAPER_RECONCILIATION` → `/admin/user-paper-reconciliation`, permissions `[USER_PAPER_LIST]`, plus the `RouteName` union member
        - Sidebar entry in the existing `User` group after `User list`, `icon: LuFileSearch`
        - Three `ApiConfig` entries and `src/enums/paper_reconciliation_verdict.enum.ts`
        - _Requirements: 11.1_

    - [x] 9.2 `api.ts` and types
        - Typed client functions for list, detail, and filter options under `src/pages/admin/user-paper-reconciliation/`
        - _Requirements: 11.2, 11.3_

- [x] 10. Page and modal
    - [x] 10.1 Filters
        - Workplace select from the existing workplace endpoint, date picker, shift select repopulated from filter-options on workplace/date change, roster status select (approved / pending, default both, disabled until a date is picked), reconciliation status select, keyword input; state reflected in the query string so a filtered view is linkable
        - _Requirements: 11.2_

    - [x] 10.2 Results table
        - One row per employee with identity, employee-scan indicator, admin-scan indicator with submitter and time, verdict badge, and the compare button; translated empty state naming the active filters
        - _Requirements: 11.3, 11.8_

    - [x] 10.3 Comparison modal
        - Both images side by side at a legible size with full-size viewing via the existing preview modal; field table with employee value, admin value, and match/mismatch/cannot-compare marker, mismatching rows visually distinct; display-only fields shown without a verdict
        - Clear statement plus no upload action when no admin scan exists
        - `employee_residence_missing` states the profile carries no residence card yet and points at the employee's document flow; no action of its own — while still rendering the `name` / `birthday` / `gender` / `nationality` rows with their verdicts, since those still compare
        - `employee_residence_missing` together with a `mismatched` verdict states both facts: no card on record *and* the scan disagrees with the profile
        - A Previous_Card_Match says in words that the scanned card is one the record has superseded, and which slot it matched — styled as a mismatch, worded as a different situation
        - No employee scan image: show the admin's image alone and say so, without implying the comparison failed
        - Display-only `residence_status_raw` shown beside the mapped status on both sides
        - Upload history list with submitter, timestamp, and per-entry image viewing
        - _Requirements: 11.4, 11.5, 11.6_

    - [x] 10.4 Frontend tests
        - Filter state and dependent shift dropdown; roster status defaulting to both and disabled without a date; verdict badge rendering including `unknown` fields as "cannot compare" and a `not_comparable` badge; modal field table; `employee_residence_missing` (alone, and together with a `mismatched` verdict) and Previous_Card_Match rendering distinct copy, and a missing employee image not reading as a failed comparison; empty state
        - _Requirements: 11.2, 11.3, 11.4, 11.8_

- [x] 11. Translations and completion checks
    - [x] 11.1 Translations
        - Convert every new string to `t(...)` with explicit keys first, then `pnpm trans:prepare`, fill `vi` and `ja` in `.translation-workflow/translation-batch.json` without touching `key` / `en` / `sources`, then `pnpm trans:apply`, then `pnpm trans:cleanup`
        - _Requirements: 11.7_

    - [x] 11.2 Completion checks
        - `./frontend`: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`
        - `./backend`: `npm run prettier`, `npm run pint`, `docker compose exec php php artisan test --parallel`
        - Report anything intentionally left uncovered and why

## Coverage Notes

Intentionally uncovered, to be raised with the reviewer rather than silently skipped:

- The permission-grant migration and the `routes/console.php` schedule line. Both are thin and follow existing precedents; the prune job's row-selection logic *is* tested.
- The `OcrImagePreparer` extraction has no new tests of its own — the body is unchanged and the existing employee OCR suite covers it, which is the point of doing it before anything depends on it. `CurrentPaperItemSelector` is the same kind of move, but does get one new test (Task 8.5) because two surfaces now depend on it agreeing.
- The `ExtractPapersOcrAction` group-mapping defect (see requirements Technical Constraints) is not fixed or tested here. Nothing in this feature reads those rows any more, so it is genuinely out of scope — but it is still wrong and still uncovered, and needs raising as its own change rather than being forgotten with this spec.
- No concurrency test is written for two admins submitting at once. Per the repository's rules, the race is prevented by design (conditional update on `submitted_at IS NULL`) and the 0-rows-affected branch is unit-tested directly instead.
