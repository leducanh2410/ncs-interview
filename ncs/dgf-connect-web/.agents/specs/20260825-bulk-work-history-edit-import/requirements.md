# Requirements Document

## Introduction

This feature lets an admin correct many employees' attendance records at once by re-uploading the Excel file already produced by the Work History detail export. The admin exports the "Chấm công" detail report from `/manage/work-history`, edits the check-in time, check-out time, and/or break-minutes cells directly in that spreadsheet for as many rows as needed, then uploads the edited file from a new "Import" button on the "Yêu cầu sửa công" (Work_History_Edit_Request_List) screen. The system re-reads the file, compares every editable cell against the live `check_ins` data, and — instead of applying anything immediately — shows the admin a review list of every detected change (employee, date, field, old value, new value) so they can deliberately reject specific rows (with a reason) before a single final action processes the whole batch: rows left as-is are approved and applied, rows marked rejected are recorded as rejected with the given reason. A structurally invalid file (wrong headers/columns, not an exported file) is rejected immediately, before any row is compared. This is an additive extension of the existing single-record edit-request workflow (`.agents/specs/20260822-work-history-edit-approval/`) — it reuses the same `Work_History_Edit_Request` / `Work_History_Edit_Request_Item` model and the same approve/reject semantics, adding a bulk, file-driven way to create and resolve many requests in one step. It also requires a small, additive change to the existing Work History detail export: a hidden row-identifier column, without which an uploaded file cannot be reliably matched back to the exact `Check_In` it came from.

### Feature Summary

An admin exports the Work History detail report, edits attendance values offline in the spreadsheet, re-uploads it from the Work_History_Edit_Request_List screen, reviews every detected change in a confirmation screen (optionally rejecting specific rows with a reason), and confirms once to apply all non-rejected changes and record the rejected ones — all in a single guided flow.

### Business Value

Admins who need to correct attendance for many employees today must open each `Check_In` individually and submit one edit request at a time, which does not scale for corrections affecting a whole workplace or pay period (e.g. after reconciling against a paper log or a payroll audit). Letting them edit a familiar spreadsheet and re-import it turns a correction that could take dozens of individual edits into one file upload, while keeping the same accountability guarantees as the single-record flow (every change is reviewed with old/new values before it takes effect, and every outcome — applied or rejected — is recorded).

### Scope

**In scope:**

- A hidden `Check_In_Id_Column` added to `Work_History_Detail_Export` so a re-uploaded row can be matched back to an exact `Check_In` unambiguously (today's export has no such identifier; matching on workplace/date/staff-no/shift-time alone is not reliable — e.g. two employees on the same shift).
- An "Import" entry point on Work_History_Edit_Request_List that accepts one `.xlsx` file for one workplace at a time (not the multi-workplace `.zip` the export produces when scoped to more than one workplace).
- Immediate, whole-file rejection when the uploaded file's structure (headers/columns/sheet shape) does not match the expected exported format — before any row is compared.
- A reconciliation step that, for every row, compares the three editable fields (check-in time, check-out time, break minutes) against the corresponding `Check_In`'s current live values and computes only the fields that actually changed. This step is read-only: it does not write anything to the database.
- Whole-file rejection (no partial processing) when any row has a content problem that prevents reconciliation: an unrecognized/missing/duplicate `Check_In_Id_Column` value, a `Check_In` outside the importing admin's permission scope, or a `Check_In` that already has a `pending` `Work_History_Edit_Request`.
- An `Import_Review_Screen` listing every `Check_In` with at least one detected change (employee, date, changed field(s), old value, new value), where every row defaults to "will be applied" and the admin may mark individual rows "Từ chối" with a mandatory reason before confirming.
- A single final confirmation action that processes the entire reviewed batch atomically: for each row still marked to apply, creates and immediately approves a `Work_History_Edit_Request` (same effect as approving a normal request); for each row marked rejected, creates a `Work_History_Edit_Request` with `status = rejected` and the given reason, without touching the `Check_In`.
- Reuse of the existing `Work_History_Edit_Request_Process` permission (already gating this screen) to authorize the import action, scoped per workplace exactly as approve/reject already is.
- Backend API additions to support file upload/validation/reconciliation (preview) and the final batch-commit action.

**Out of scope:**

- Accepting the multi-workplace `.zip` export directly (the admin uploads one per-workplace `.xlsx` at a time).
- Any change to the single-record edit/approve/reject flow, the Work History list, or the monthly aggregate export/view — this feature only adds a new entry point on Work_History_Edit_Request_List.
- Partial-success imports: a file with any row-level content problem is rejected in full at the reconciliation step (Requirement 5); there is no "skip the bad rows, import the rest" mode.
- Editing any field of `Check_In` other than check-in time, check-out time, and break minutes (the same three fields the single-record flow already supports).
- Notifications (email/push) on import or on the batch's resolution.
- A generic reusable file-import framework — this feature is specific to the Work History detail export/import round trip.

## Glossary

| Term | Definition |
|------|------------|
| Check_In | An employee's attendance record for one shift (`check_ins` table); see `.agents/specs/20260822-work-history-edit-approval/requirements.md` |
| Work_History_Edit_Request | A proposed edit to one Check_In, pending/approved/rejected, with a reason; see prior spec |
| Work_History_Edit_Request_Item | One changed-field line item within a Work_History_Edit_Request |
| Work_History_Edit_Request_List | The existing "Yêu cầu sửa công" admin screen (`/manage/work-history/edit-requests`) this feature adds an Import button to |
| Work_History_Detail_Export | The existing per-workplace Excel export produced from `/manage/work-history` ("Xưởng, Ngày làm, Giờ làm, Mã nhân viên, ..." columns) |
| Check_In_Id_Column | A new hidden column added to Work_History_Detail_Export carrying each row's `Check_In` id, used to match an uploaded row back to its exact record |
| Editable_Field | One of the three fields this feature (and the single-record flow) can change: check-in time, check-out time, break minutes |
| Import_Batch | The set of rows read from one uploaded file, from upload through reconciliation and final commit |
| Import_Preview | The read-only reconciliation result for an Import_Batch: the list of Check_Ins with detected changes, returned to Admin_Web without writing to the database |
| Import_Review_Screen | The confirmation UI presenting the Import_Preview, letting the admin mark specific rows "Từ chối" (with reason) before final commit |
| Managed_Workplace | A workplace within the importing admin's `workplace_access` scope for the Work_History_Edit_Request_Process permission |
| Admin_Web | The Next.js admin web application |
| Backend_API | The Laravel REST API |

## Requirements

### Requirement 1: Hidden row identifier on the Work History detail export

**User Story:** As the system, I want every exported attendance row to carry an unambiguous identifier, so that a later re-imported row can be matched back to the exact Check_In it came from instead of being guessed from human-readable columns.

#### Acceptance Criteria

1. WHEN Work_History_Detail_Export is generated THEN Backend_API SHALL include, for every data row, a Check_In_Id_Column containing that row's `Check_In` id.
2. WHERE the Check_In_Id_Column appears in the workbook THEN Backend_API SHALL place it so it does not disrupt the existing visible columns (A–P) or their order, and SHALL NOT require the admin to interact with it while editing attendance values by hand.
3. WHEN a workplace-name header row or a blank separator row is written (existing per-workplace grouping) THEN Backend_API SHALL leave the Check_In_Id_Column empty on those rows, consistent with how other columns behave on non-data rows.
4. IF a `Check_In` is included in the export THEN Backend_API SHALL guarantee its Check_In_Id_Column value uniquely identifies that `Check_In` within the file (no two data rows share the same value).

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None (extends the existing `WorkHistoryDetailExport`/`WorkHistoryService::buildDetailExportData`)

### Requirement 2: Import entry point, file constraints, and authorization

**User Story:** As an admin with edit-request processing rights, I want an Import action on the Yêu cầu sửa công screen, so that I can start a bulk correction from a file instead of one record at a time.

#### Acceptance Criteria

1. WHEN a user with Work_History_Edit_Request_Process permission opens Work_History_Edit_Request_List THEN Admin_Web SHALL display an "Import" action that opens a file-selection dialog accepting a single `.xlsx` file.
2. IF the selected file is not a `.xlsx` file OR exceeds the configured maximum size THEN Admin_Web SHALL reject the selection client-side before any upload request is sent.
3. WHEN the file is uploaded THEN Backend_API SHALL require Work_History_Edit_Request_Process permission, and SHALL reject the request (403) if the caller lacks it for every workplace referenced in the file.
4. IF the uploaded file's rows resolve to Check_Ins across more than one workplace, OR resolve to a workplace outside the caller's Managed_Workplace scope THEN Backend_API SHALL reject the entire import (this feature accepts one workplace's exported `.xlsx` at a time, per Requirement 5's Check_In-scope validation).
5. WHERE a user lacks Work_History_Edit_Request_Process permission entirely THEN Admin_Web SHALL NOT display the Import action, consistent with the rest of the screen's permission gating.
6. IF the uploaded file is not `.xlsx` OR exceeds the configured maximum size THEN Backend_API SHALL also reject it server-side, mirroring the file-type/size validation the existing Shift CSV import already applies server-side — the client-side check in Criterion 2 is a UX convenience, not the enforcement point.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1

### Requirement 3: Whole-file rejection on structural format errors

**User Story:** As an admin, I want to be told immediately if I uploaded the wrong file, so that I don't waste time waiting for a review screen that can never be produced correctly.

#### Acceptance Criteria

1. WHEN an uploaded file is parsed THEN Backend_API SHALL validate the header row and column layout against the expected Work_History_Detail_Export format (including the Check_In_Id_Column from Requirement 1) before evaluating any data row.
2. IF the file cannot be parsed as a valid `.xlsx` workbook, is empty, OR its header/column layout does not match the expected format THEN Backend_API SHALL reject the import with a specific, actionable error identifying the problem, and SHALL NOT proceed to reconciliation (Requirement 4).
3. IF the file is structurally valid but contains zero data rows THEN Backend_API SHALL reject the import with a "nothing to import" error.
4. WHEN a structural format error is returned THEN Admin_Web SHALL display it immediately at the upload step, without opening Import_Review_Screen.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 4: Read-only reconciliation against live data

**User Story:** As an admin, I want the system to tell me exactly what changed between my edited file and the current system data, so that I only review real differences, not the whole file.

#### Acceptance Criteria

1. WHEN a structurally valid file (Requirement 3) is processed THEN Backend_API SHALL, for each data row, look up the `Check_In` by its Check_In_Id_Column value and compare each Editable_Field's cell value against that `Check_In`'s current live value.
2. IF a row's Editable_Field values all match the corresponding `Check_In`'s current live values THEN Backend_API SHALL exclude that row from the Import_Preview (no change detected, nothing to review).
3. IF a row has at least one Editable_Field whose cell value differs from the `Check_In`'s current live value THEN Backend_API SHALL include that `Check_In` in the Import_Preview with one entry per changed field (old value, new value), consistent with how Work_History_Edit_Request_Item represents changed fields today.
4. WHEN reconciliation runs THEN Backend_API SHALL NOT create, update, or delete any `Work_History_Edit_Request`, `Work_History_Edit_Request_Item`, or `Check_In` row — it SHALL only compute and return the Import_Preview.
5. IF the Import_Preview is empty (every row unchanged) THEN Backend_API SHALL return a "no changes detected" result, and Admin_Web SHALL inform the admin instead of opening an empty Import_Review_Screen.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 1, Requirement 3

### Requirement 5: Whole-file rejection on row-content problems

**User Story:** As an admin, I want the import to refuse the whole file if any row can't be safely reconciled, so that I never end up applying a correct subset while silently losing track of a problem row.

#### Acceptance Criteria

1. IF a row's Check_In_Id_Column value is empty, duplicated within the file, or does not match any existing `Check_In` THEN Backend_API SHALL reject the entire import and report which row(s) and what problem.
2. IF a row's `Check_In` already has a `pending` `Work_History_Edit_Request` (necessarily from the single-record flow — an import never leaves a request `pending`, since commit resolves every request it creates in the same transaction it creates it in, per Requirement 7) THEN Backend_API SHALL reject the entire import and identify the conflicting row(s), consistent with the single-record flow's one-pending-request-per-Check_In rule.
3. IF a row's `Check_In` belongs to a workplace the caller does not have Work_History_Edit_Request_Process permission for THEN Backend_API SHALL reject the entire import (see Requirement 2.4).
4. WHEN any condition in this requirement is met THEN Backend_API SHALL NOT return a partial Import_Preview and SHALL NOT open Import_Review_Screen — the admin must fix the file and re-upload.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4

### Requirement 6: Import review screen with per-row reject

**User Story:** As an admin, I want to see every detected change before it takes effect and be able to deliberately exclude specific ones, so that a spreadsheet mistake on one row doesn't get applied along with everything else.

#### Acceptance Criteria

1. WHEN Import_Preview is non-empty (Requirement 4) THEN Admin_Web SHALL open Import_Review_Screen listing every included `Check_In`: employee, workplace, date, and for each changed Editable_Field its old and new value.
2. WHEN Import_Review_Screen is opened THEN Admin_Web SHALL default every row's decision to "apply" (no action needed from the admin to accept a correct row).
3. WHEN the admin marks a row "Từ chối" THEN Admin_Web SHALL require a non-empty rejection reason for that row before the final confirmation can be submitted, consistent with the single-record reject requirement.
4. WHILE Import_Review_Screen is open THEN Admin_Web SHALL let the admin toggle a row between "apply" and "Từ chối" (with reason) any number of times before confirming.
5. IF the admin closes Import_Review_Screen without confirming THEN Admin_Web SHALL discard the Import_Preview; per Requirement 4.4, nothing was written to the database, so no cleanup is required.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4

### Requirement 7: Atomic final commit

**User Story:** As an admin, I want one confirmation to apply everything I reviewed, so that the whole batch resolves in a single, all-or-nothing step.

#### Acceptance Criteria

1. WHEN the admin confirms Import_Review_Screen THEN Backend_API SHALL, in one transaction, re-verify every row against the same conditions as Requirement 4/5 (live values and Check_In state may have changed since the preview was computed) and abort the entire commit if any row now fails those conditions, reporting which row and why — consistent with the existing single-record "data changed since submission" conflict handling.
2. WHEN all rows pass re-verification THEN Backend_API SHALL, within that same transaction, for each row still marked "apply": create a `Work_History_Edit_Request` with one `Work_History_Edit_Request_Item` per changed field, and immediately approve it (apply the new values to the `Check_In`, recalculate `work_minutes*`, trigger the same downstream effects the single-record approve action triggers).
3. WHEN all rows pass re-verification THEN Backend_API SHALL, within that same transaction, for each row marked "Từ chối": create a `Work_History_Edit_Request` with `status = rejected`, the admin-supplied reason, `processed_by_id`, and `processed_at`, and SHALL NOT modify that row's `Check_In`.
4. IF any step of the commit transaction fails THEN Backend_API SHALL roll back the entire batch — no `Check_In` is changed and no `Work_History_Edit_Request` from this batch is created.
5. WHEN the commit succeeds THEN Admin_Web SHALL show a summary (counts applied / rejected) and refresh Work_History_Edit_Request_List.
6. WHEN Work_History_Edit_Request_List or a request's detail is later viewed THEN Admin_Web SHALL display batch-created requests the same way as single-record requests (Requirement 8), with no visual distinction required to identify them as import-originated.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 5, Requirement 6

### Requirement 8: Auditability of import-originated requests

**User Story:** As an approver reviewing history, I want import-originated requests to show up in the normal request list and filters, so that I have one place to audit every attendance correction regardless of how it was submitted.

#### Acceptance Criteria

1. WHEN a `Work_History_Edit_Request` is created via Requirement 7 (applied or rejected) THEN Backend_API SHALL make it retrievable through the existing Work_History_Edit_Request_List filters (workplace, employee name, status, submission date range) exactly like a single-record request.
2. WHEN the admin opens the detail of an import-originated request THEN Admin_Web SHALL show its items (field, old value, new value) and, for a rejected one, the rejection reason and who processed it, using the existing detail presentation.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 7

### Requirement 9: Supporting API additions

**User Story:** As a developer, I want the backend to expose the endpoints the import flow needs, so that Admin_Web has a complete API surface for upload, preview, and commit.

#### Acceptance Criteria

1. WHEN Admin_Web uploads a file THEN Backend_API SHALL expose an endpoint that performs Requirements 2–5 (auth, structural validation, reconciliation, content validation) and returns either a structural/content error or the Import_Preview — without persisting anything.
2. WHEN Admin_Web confirms Import_Review_Screen THEN Backend_API SHALL expose a commit endpoint implementing Requirement 7, accepting the reviewed row decisions (apply/reject + reasons) and returning the applied/rejected counts.
3. WHEN Work_History_Detail_Export runs THEN Backend_API SHALL include the Check_In_Id_Column (Requirement 1) without changing the response shape of unrelated existing endpoints.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 1, 4, 5, 7

### Requirement 10: Clear, actionable error reporting

**User Story:** As an admin, I want every rejection of my file to tell me exactly what is wrong and where, so that I can fix the spreadsheet myself and re-upload without guessing or asking a developer.

#### Acceptance Criteria

1. WHEN a structural format error occurs (Requirement 3.2) THEN Backend_API SHALL report which column/header is wrong, what was expected there, and what was found instead — sufficient to identify the mismatch without inspecting the file's raw bytes.
2. WHEN a row-content error occurs (Requirement 5) THEN Backend_API SHALL identify the offending row(s) in human-readable terms (employee name and date, matching what the admin sees in the spreadsheet — not only the internal Check_In id) together with the specific reason (e.g. "không tìm thấy bản ghi chấm công tương ứng", "đã có yêu cầu khác đang chờ duyệt cho dòng này", "trùng ID trong file"). Note: `Check_In` is never soft-deleted in this system, so Backend_API SHALL NOT claim a row "was deleted" — an unresolvable id is reported as not found, without asserting why it doesn't resolve.
3. IF a file has more than one row-content error THEN Backend_API SHALL report all of them together in a single response, not only the first encountered, so the admin can fix every problem before re-uploading once.
4. WHEN a commit-time conflict is detected (Requirement 7.1) THEN Backend_API SHALL report which row's underlying data changed since the preview was generated, using the same human-readable identification as Criterion 2.
5. WHEN Admin_Web receives a structural, content, or commit-time error THEN Admin_Web SHALL render it as a readable list the admin can act on directly (row/employee/date/reason), never a raw error code or an unexplained generic failure message.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 3, Requirement 5, Requirement 7

## Non-Functional Requirements

### Performance Requirements
- WHEN a file is uploaded for preview THEN Backend_API SHALL process it synchronously within the request/response cycle (no background job), consistent with the file-size/row caps below.
- WHEN reconciling a file's rows against `check_ins` THEN Backend_API SHALL avoid N+1 queries (batch-load Check_Ins by their Check_In_Id_Column values).

### Security Requirements
- WHEN the upload or commit endpoint is called THEN Backend_API SHALL enforce Work_History_Edit_Request_Process permission and Managed_Workplace scoping server-side (Requirement 2.3–2.4, 5.3), not only in the UI.
- WHEN the commit endpoint is called THEN Backend_API SHALL re-verify the acting user's authorization and every row's live state at commit time (Requirement 7.1), not rely on the preview response being trustworthy client-supplied input.

### Usability Requirements
- WHEN a structural, content, or commit-time error occurs THEN Admin_Web SHALL surface it per Requirement 10 — specific and actionable (which row / which problem / expected vs. found), never a generic failure.
- WHEN Import_Review_Screen is open THEN Admin_Web SHALL make old vs. new values visually easy to compare per row, consistent with the existing single-record request detail view.

### Reliability Requirements
- IF the commit transaction (Requirement 7) partially fails THEN Backend_API SHALL roll back all of it — a batch never ends up partially applied.
- IF the data an Import_Preview was based on changes before the admin confirms (e.g. another edit is approved for one of the same Check_Ins in the meantime) THEN Backend_API SHALL detect this at commit time (Requirement 7.1) and abort rather than apply against stale assumptions.
- IF a commit and a concurrent single-record edit submission (or another concurrent commit) target the same `Check_In` at the same time THEN Backend_API SHALL guarantee at most one succeeds in creating a `pending` `Work_History_Edit_Request` for it, via a row lock on the `Check_In` held across the pending-check and the insert within the same transaction — not a check-then-act read followed by a separate write — consistent with how the single-record flow (`.agents/specs/20260822-work-history-edit-approval/requirements.md`) already guards this.

## Constraints and Assumptions

### Technical Constraints
- No `.xlsx` reading capability exists in the codebase today (existing imports are CSV-only via `League\Csv`/`CsvHelper`); this feature introduces `.xlsx` parsing (e.g. via the `Maatwebsite/Excel`/`PhpOffice/PhpSpreadsheet` stack already used for writing exports).
- Reuses the existing `Work_History_Edit_Request` / `Work_History_Edit_Request_Item` schema as-is; no new tables are introduced by this feature beyond the Check_In_Id_Column addition to the export.
- Reuses the existing approve-application logic (`work_minutes*` recalculation and downstream effects) from `.agents/specs/20260822-work-history-edit-approval/` for each applied row.

### Assumptions
- **Import accepts exactly one workplace's `.xlsx` per upload.** A multi-workplace export (`.zip`) must be unzipped and its files imported one at a time. Confirmed by stakeholder.
- **Whole-file rejection on any row-content problem, no partial import.** If any row can't be resolved to a valid, permitted, not-already-pending `Check_In`, the entire file is rejected before Import_Review_Screen opens. Confirmed by stakeholder.
- **Rows the admin marks "Từ chối" in the review screen still create a recorded `Work_History_Edit_Request` with `status = rejected`**, so they appear in history/audit filters exactly like a manually rejected request. Confirmed by stakeholder.
- **Nothing is persisted during preview.** The reconciliation step is pure read/compute; only the final commit (Requirement 7) writes to the database. Confirmed by stakeholder.
- **Authorization uses the existing `Work_History_Edit_Request_Process` permission only** — importing does not additionally require the propose-only `work_history.edit` permission, since the import action itself performs both proposal and resolution in one step and is already gated by the same permission that gates this entire screen.
- **Self-service is expected**: the importing admin typically reconciles their own export, so no separate "propose vs approve" split is needed for import-created requests, mirroring how a dual-permission user can already self-approve a single-record request.
- A maximum file size and row count will be enforced (exact values are a design/implementation decision, informed by the existing Shift CSV import's `max:2048` KB precedent) to keep preview synchronous.
- Export changes (Requirement 1) are additive and backward compatible: existing consumers of the export who ignore the new column are unaffected.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria in Requirements 1–10 are met
- [ ] Non-functional requirements (performance, security, usability, reliability) are satisfied
- [ ] A file with a wrong/missing header is rejected immediately with no review screen shown, verified by test
- [ ] A file with one unresolvable row (an id matching no `Check_In`, a cross-workplace row, or an already-pending `Check_In`) is rejected in full — zero requests created, verified by test
- [ ] A commit can never create two `pending` requests for the same `Check_In` (row lock held across the pending-check and the insert, not check-then-act), verified by test
- [ ] A valid file's unchanged rows never appear in Import_Preview, and changed rows show the correct old/new values per field, verified by test
- [ ] Confirming a review with a mix of applied and rejected rows results in exactly the expected `Check_In` updates and exactly the expected rejected `Work_History_Edit_Request` records, in one transaction, verified by test
- [ ] A commit that re-detects a conflict (Check_In changed since preview) aborts the whole batch without partial writes, verified by test
- [ ] Import-originated requests are indistinguishable in the existing list/detail/filter UI from single-record requests, verified by test
- [ ] Every format/content/commit-time rejection message identifies the specific row/column and problem in human-readable terms, verified by test
- [ ] Testing criteria are passed (backend feature/unit tests per workspace conventions; frontend typecheck/lint/prettier clean)
