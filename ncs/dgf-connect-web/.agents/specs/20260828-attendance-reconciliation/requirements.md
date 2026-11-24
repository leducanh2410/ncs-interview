# Requirements Document

## Introduction

This feature lets an admin compare an external attendance file (first source: Amazon 稼働実績一覧) against DGF `check_ins` without writing payroll data, decide per field whether to keep DGF or take the external values, and export a Work History detail `.xlsx` that is the input to the existing bulk edit-request import (`.agents/specs/20260825-bulk-work-history-edit-import/`). The comparison is by employee name and work date; only check-in time, check-out time, and break minutes are compared. Applying those values to `check_ins` is explicitly out of scope — that remains the import/approve flow. The design is source-pluggable so a later client file with a different layout is a new parser only.

### Feature Summary

An admin with the new Reconciliation permission uploads an external attendance file, reviews matched and unmatched rows against DGF check-ins, chooses Amazon or DGF per field, confirms the batch (which freezes it), and exports one Work History detail workbook per workplace for the existing "Yêu cầu sửa công" import.

### Business Value

Client systems such as Amazon already record when dispatched employees actually punched. Today that comparison against DGF face-check-in is manual and error-prone. A dedicated reconciliation pass shows exactly which punches and breaks differ, produces an import-ready attendance file, and keeps an audit of who reconciled what — without bypassing the existing attendance-edit approval gate that payroll depends on.

### Scope

**In scope:**

- A new permission group **Đối soát** with one permission, Reconciliation_Work ("Đối soát công làm nhân viên"). Holding the permission grants access to every workplace's check-in data for this feature (not `workplace_access` / team-scoped).
- Admin_Web pages: a Reconciliation_Batch list (history) and a Reconciliation_Review_Screen (draft merge + read-only view of a confirmed batch).
- A Source_Parser interface plus the first parser, Amazon_Kadou_Parser, for 稼働実績一覧 `.xlsx`.
- Matching Canonical_Rows to DGF users by name (kana / short name / similar / manual search) and to a `Check_In` by date; workplace is taken from that `Check_In`.
- Per-field merge of check-in time, check-out time, and Effective_Break_Minutes.
- Confirm (freeze) + export of Work_History_Detail_Export-compatible files, one workplace per `.xlsx` (zipped when the batch spans more than one workplace).
- Persistence of each batch: original file, parsed rows, match/merge decisions, actor timestamps, export artifacts.

**Out of scope:**

- Writing `check_ins`, creating or approving `Work_History_Edit_Request` records, or calling the bulk-import commit endpoint from this feature.
- Auto-creating a shift assignment when Amazon has a punch and DGF has no `Check_In` that day.
- Comparing scheduled time (予定時間), paid window (作業時間), actual working hours (実働時間), overtime, night, or holiday columns.
- Transliteration between katakana and Latin (no kana↔romaji converter).
- A new Nepal name-shortener; Nepal continues to use the existing `short_name` accessor (full name when no derived short name exists).
- Surfacing DGF-only check-ins that have no matching Amazon punch row (only Amazon rows with a punch log are in scope).
- Changing Work_History_Edit_Request_List import rules (still one workplace per `.xlsx`, still requires Work_History_Edit_Request_Process for that workplace).
- Notifications (email/push) on upload, confirm, or export.

## Glossary

| Term | Definition |
|------|------------|
| Check_In | An employee's attendance record for one shift (`check_ins` table) |
| Editable_Field | Check-in time, check-out time, or break minutes — the only fields this feature compares and the only fields Work_History_Detail_Export import can apply |
| Effective_Break_Minutes | Break used for comparison and for the exported rest column: `Check_In.break_minutes` if set, else the shift-level override, else the workplace-shift template duration, else 0 — the same resolution Work_History_Detail_Export and bulk-import preview already use |
| Reconciliation_Work | The permission `reconciliation.work` ("Đối soát công làm nhân viên") in the new `reconciliation` permission group |
| Reconciliation_Admin | A user who holds Reconciliation_Work |
| Source | A named external file type (first: `amazon_kadou`). Each Source has one Source_Parser |
| Source_Parser | Component that turns a raw upload into Canonical_Rows or a format error. Adding a Source means adding a parser; match/merge/export stay unchanged |
| Canonical_Row | Normalized external attendance: external name, work date, check-in datetime, check-out datetime, break minutes, plus the raw cells for audit |
| Amazon_Kadou_Parser | Source_Parser for Amazon 稼働実績一覧 (sheet name `DGF人材開発` in the sample file). Reads 氏名, 作業日, 実打刻時間, 休憩時間. Ignores rows without a punch log |
| Punch_Log | A Canonical_Row whose 実打刻時間 start and end cells are both present (Excel datetimes around a `～`), i.e. the `HH:MM ~ HH:MM` pattern. A row with only one end is not a Punch_Log |
| Work_Date | The Amazon 作業日 (Excel serial date) compared to DGF `Shift.date`, then `Shift.adjusted_date` for overnight shifts |
| Name_Match | The result of comparing an external 氏名 to a DGF user: exact, similar, ambiguous, or unmatched |
| Short_Name | `User.short_name` accessor: derived Latin short form for Sri Lanka nationality, otherwise the full `name` |
| Name_Kana | `User.name_kana` (`last_name_kana` + space + `first_name_kana`) |
| Reconciliation_Batch | One upload through confirm/export: original file, Canonical_Rows, matches, field decisions, status, actors |
| Reconciliation_Row | One Punch_Log inside a Reconciliation_Batch, with match status, optional Check_In, per-field decisions, and snapshots |
| Draft | Batch status after upload/match, before Confirm. Decisions may still change |
| Confirmed | Batch status after the admin freezes decisions. Read-only except re-export |
| Reconciliation_Review_Screen | Admin_Web page for a batch: merge UI while Draft, read-only while Confirmed |
| Reconciliation_Batch_List | Admin_Web history list of Reconciliation_Batches |
| Work_History_Detail_Export | Existing per-workplace Excel shape (columns A–P plus hidden Check_In_ID) produced from `/manage/work-history` and accepted by bulk edit-request import |
| Work_History_Edit_Request_Process | Existing permission that gates the import on Work_History_Edit_Request_List; unchanged and independent of Reconciliation_Work |
| Managed_Workplace | A workplace the user may process edit requests for (team-scoped). Used only when that user later imports the exported file; not used to filter reconciliation itself |
| Admin_Web | The Next.js admin web application |
| Backend_API | The Laravel REST API |

## Requirements

### Requirement 1: Reconciliation permission, global across workplaces

**User Story:** As a system administrator, I want a dedicated "Đối soát" permission that is not tied to the workplaces on the role, so that a reconciliation manager can compare client punches for every site without being a workshop leader of those sites.

#### Acceptance Criteria

1. WHEN an administrator opens Role Management THEN Admin_Web SHALL display a permission group "Đối soát" (`reconciliation`) containing exactly one toggle: Reconciliation_Work ("Đối soát công làm nhân viên"), independently of the Work History permission group.
2. WHEN Backend_API seeds or migrates this permission THEN it SHALL grant Reconciliation_Work to the built-in Global Admin and Admin roles, using an idempotent attach plus Laratrust cache flush consistent with how `work_history.process_edit_request` was introduced.
3. IF a user lacks Reconciliation_Work THEN Admin_Web SHALL hide Reconciliation_Batch_List and the sidebar entry, and Backend_API SHALL reject every reconciliation endpoint with 403.
4. IF a user holds Reconciliation_Work, including when that permission arrives only through a role assigned to a single workplace team, THEN Backend_API SHALL treat the user as authorized for check-ins and users in every workplace for this feature.
5. WHERE master-data `workplace_access` is assembled THEN Backend_API SHALL NOT add a `reconciliation` prefix; this feature SHALL NOT call `applyWorkplaceFilter` / `getWorkplaceIdsByPermission` to restrict rows.
6. WHEN a Reconciliation_Admin is assigned Reconciliation_Work on a workplace-scoped workshop-leader role THEN Backend_API SHALL still return Check_Ins from other workplaces on that batch (documented operational caveat: do not attach this permission to a single-site leader role unless that person is allowed to reconcile company-wide).

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None

### Requirement 2: History list and new-batch entry point

**User Story:** As a Reconciliation_Admin, I want a list of past reconciliations and a way to start a new one, so that I can see who uploaded what and open a batch without losing the audit trail.

#### Acceptance Criteria

1. WHEN a Reconciliation_Admin opens Admin_Web THEN Admin_Web SHALL show a "Đối soát công" item under the Check in sidebar group, linking to Reconciliation_Batch_List at `/manage/work-history/reconciliation`.
2. WHEN Reconciliation_Batch_List loads THEN Admin_Web SHALL list batches with: uploaded_at, uploaded_by, Source, original filename, Punch_Log count, skipped non-punch count, matched/differing/unmatched/manual-map counts, status (Draft or Confirmed), last exported_at / exported_by when present.
3. WHEN the admin uses list filters THEN Admin_Web SHALL filter by Source, uploader, and upload date range.
4. WHEN the admin clicks "Đối soát mới" THEN Admin_Web SHALL open an upload dialog that requires a Source choice and a single `.xlsx` file (type and max size validated client-side and again by Backend_API).
5. WHEN the admin opens a Draft batch THEN Admin_Web SHALL show Reconciliation_Review_Screen in editable mode (Requirement 8).
6. WHEN the admin opens a Confirmed batch THEN Admin_Web SHALL show Reconciliation_Review_Screen read-only with a re-export action (Requirement 11).

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 3: Pluggable Source_Parser producing Canonical_Rows

**User Story:** As the system, I want every external file reduced to the same Canonical_Row shape, so that a future client format is a new parser and not a rewrite of matching, merge, or export.

#### Acceptance Criteria

1. WHEN an upload specifies a Source THEN Backend_API SHALL dispatch only that Source's Source_Parser; an unknown Source SHALL be rejected (422) without creating a batch.
2. WHEN a Source_Parser succeeds THEN Backend_API SHALL persist Canonical_Rows of `{ external_name, work_date, check_in, check_out, break_minutes, raw }` and SHALL NOT keep source-specific columns in the match/merge/export path.
3. IF the file is not `.xlsx`, exceeds the configured size, cannot be parsed as a workbook, or fails that Source's header/sheet contract THEN Backend_API SHALL reject the upload with an actionable format error and SHALL NOT create a Reconciliation_Batch.
4. IF the parser produces zero Punch_Log rows THEN Backend_API SHALL reject the upload with a "nothing to reconcile" error and SHALL NOT create a batch.
5. WHEN a second Source is registered in a later iteration THEN match (Requirement 5–6), merge (Requirement 7–9), history, and export (Requirement 10) SHALL operate on Canonical_Rows without Source-specific branches.
6. WHERE automated tests cover adapter isolation THEN a fake second Source_Parser SHALL be able to drive the same match/export path as Amazon_Kadou_Parser.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2

### Requirement 4: Amazon_Kadou_Parser (稼働実績一覧)

**User Story:** As a Reconciliation_Admin, I want Amazon's 稼働実績 file accepted as the first Source, so that only rows with an actual punch log are compared.

#### Acceptance Criteria

1. WHEN Source is `amazon_kadou` THEN Amazon_Kadou_Parser SHALL read the 稼働実績一覧 workbook (sample sheet `DGF人材開発`) and require columns 氏名, 作業日, 実打刻時間 (start `～` end), and 休憩時間.
2. IF a data row lacks both 実打刻 start and end (no Punch_Log), or has only one of them, THEN the parser SHALL skip it and increment the batch's skipped-non-punch count; it SHALL NOT become a Reconciliation_Row. Summary rows whose 氏名 starts with `計` / `合計` / `小計` and whose 作業日 is empty SHALL be ignored and SHALL NOT increment skipped-non-punch.
3. WHEN a Punch_Log is parsed THEN Canonical_Row.check_in / check_out SHALL come from 実打刻時間 (not 予定時間 or 作業時間), Work_Date from 作業日, external_name from 氏名 (whitespace including ideographic space collapsed), and break_minutes from 休憩時間 converted from Excel day-fraction to integer minutes.
4. WHEN 実打刻 end is on the following Excel serial day THEN Canonical_Row.check_out SHALL keep that calendar datetime (overnight).
5. IF 会社名 is present THEN the parser SHALL store it on the batch for display/audit only and SHALL NOT use it to select a DGF workplace.
6. WHEN the sample file `20260825_DGF人材開発.xlsx` is parsed THEN the parser SHALL produce 48 Punch_Logs for Work_Date 2026-08-25 and skip the 208 rows without 実打刻.
7. IF two Punch_Logs share the same normalized 氏名 and Work_Date THEN Backend_API SHALL keep both rows and mark them as a duplicate-external-key conflict that the admin must resolve or skip; it SHALL NOT silently merge them.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 3

### Requirement 5: Name matching and manual map with search

**User Story:** As a Reconciliation_Admin, I want 氏名 matched to DGF the way names actually appear (given name, family+given, or shortened form), and a search when they only look similar, so that I do not guess or auto-attach the wrong person.

#### Acceptance Criteria

1. WHEN matching a Canonical_Row THEN Backend_API SHALL normalize names (NFKC, hiragana→katakana, collapse all Unicode spaces, trim, uppercase Latin) and SHALL NOT transliterate kana to Latin.
2. IF the normalized 氏名 is a single token THEN Backend_API SHALL treat an exact match against `first_name_kana`, `last_name_kana`, or Short_Name as an exact Name_Match only when exactly one DGF user in the full directory qualifies; two or more SHALL be ambiguous (no auto-map). Users with a Check_In on that Work_Date are ranked first in candidate lists and are searched for similar matches first, but they do not change exact-match uniqueness.
3. IF the normalized 氏名 has two or more tokens THEN Backend_API SHALL treat equality with Name_Kana (`last_name_kana` + space + `first_name_kana`), the reversed given+family kana, or Short_Name as an exact Name_Match, again only when exactly one user in the full directory qualifies.
4. IF no exact Name_Match exists THEN Backend_API SHALL collect similar candidates (every Amazon token is a substring of Name_Kana or Short_Name, or the reverse) and SHALL NOT auto-map them.
5. WHEN presenting similar or ambiguous candidates THEN Admin_Web SHALL list users who have a Check_In on that Work_Date first, then other users, each with staff_no, avatar, Name_Kana, Short_Name, and the Check_In workplace/time when present, and SHALL provide a user search (name, kana, staff_no) so the admin is not limited to a short suggestion list.
6. WHEN the admin picks a user on a similar/ambiguous/unmatched row THEN Backend_API SHALL store `match_method = manual` and that user_id on the Reconciliation_Row.
7. IF the admin marks a row skipped THEN Backend_API SHALL exclude it from export and SHALL allow Confirm without mapping it.
8. IF nationality is Nepal and `users.short_name` is null THEN Short_Name SHALL be the existing accessor fallback (full `name`); Backend_API SHALL NOT introduce a Nepal shortener.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 3

### Requirement 6: Check_In, Work_Date, and workplace from the shift

**User Story:** As a Reconciliation_Admin, I want each mapped employee tied to the DGF check-in for that work date and the workplace of that check-in, so that I do not pick a factory by hand and overnight shifts still land on the right day.

#### Acceptance Criteria

1. WHEN a Reconciliation_Row has a mapped user THEN Backend_API SHALL look up that user's Check_Ins whose `Shift.date` equals Work_Date, and IF none THEN whose `Shift.adjusted_date` equals Work_Date.
2. IF exactly one Check_In matches THEN Backend_API SHALL attach it and set workplace from `Check_In.shift.workplace_id`.
3. IF two or more Check_Ins match (same day, possibly two workplaces) THEN Admin_Web SHALL require the admin to pick one Check_In; the row SHALL NOT be exportable until picked.
4. IF the mapped user has no Check_In on that Work_Date THEN the row status SHALL be "no DGF attendance"; the row SHALL NOT be exportable (no Check_In_ID).
5. IF a Check_In is marked absent (`shift_users.is_absent`) but the row still exists THEN Backend_API SHALL still compare Editable_Fields when a Check_In is attached.
6. IF the attached Check_In has no check-out THEN a present Canonical_Row.check_out SHALL count as a difference on check-out.
7. WHERE test workplaces are hidden for the actor by existing `ShouldShowTestWorkplacesAction` rules THEN those Check_Ins SHALL be omitted from matching, consistent with other admin lists.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 5

### Requirement 7: Compare check-in, check-out, and Effective_Break_Minutes only

**User Story:** As a Reconciliation_Admin, I want to see whether Amazon's actual punch and break differ from DGF at minute precision, using the same break the Chấm công export shows, so that I do not review false break diffs or paid-hour columns.

#### Acceptance Criteria

1. WHEN a Reconciliation_Row has an attached Check_In THEN Backend_API SHALL compare only Canonical_Row.check_in, check_out, and break_minutes against `Check_In.check_in_time`, `Check_In.check_out_time`, and Effective_Break_Minutes.
2. WHEN comparing times THEN Backend_API SHALL compare clock minutes (ignore seconds and the date component for equality of the time-of-day; overnight still uses the full datetime to know the +1 day) and SHALL NOT treat `check_in_time_rounded` / `check_out_time_rounded` as the DGF side.
3. IF Effective_Break_Minutes equals Canonical_Row.break_minutes THEN Backend_API SHALL treat break as matched even when `Check_In.break_minutes` is null.
4. IF all three Editable_Fields match THEN the row SHALL be "matched"; it SHALL NOT appear as a conflict and SHALL NOT be included in the export.
5. IF at least one Editable_Field differs THEN the row SHALL be "differs" and each differing field SHALL default to unresolved until the admin picks Amazon or DGF.
6. WHEN displaying an overnight check-out THEN Admin_Web SHALL show a +1-day indicator (e.g. `06:03 (+1)`).
7. WHERE Amazon 予定時間 / 作業時間 exist THEN Admin_Web MAY show them as non-compared reference tooltips and SHALL NOT use them in difference detection.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 6

### Requirement 8: Draft merge UI (Amazon vs DGF per field)

**User Story:** As a Reconciliation_Admin, I want to accept Amazon or keep DGF per field the way I resolve a merge conflict, so that the exported file contains only the values I chose.

#### Acceptance Criteria

1. WHEN Reconciliation_Review_Screen opens a Draft THEN Admin_Web SHALL group rows by employee/date and show Amazon vs DGF for check-in, check-out, and break, with filters: all, differs, matched, unmatched/similar, no DGF attendance, pending-edit-request.
2. WHEN a field matches THEN Admin_Web SHALL mark it resolved as DGF without a required click.
3. WHEN a field differs THEN Admin_Web SHALL require an explicit Amazon or DGF choice; Confirm SHALL stay disabled while any required choice or Check_In pick is missing (skipped unmatched rows excepted).
4. WHEN the admin uses bulk "Accept remaining Amazon" or "Keep remaining DGF" THEN Admin_Web SHALL apply that side to every still-unresolved differing field.
5. WHEN the admin chooses Amazon for a field THEN the exported value for that field SHALL be the Canonical_Row value; WHEN they choose DGF THEN the exported value SHALL be the live Check_In / Effective_Break_Minutes snapshot.
6. WHILE the batch is Draft THEN Backend_API SHALL persist decision and manual-map changes so a refresh does not lose work.
7. WHERE the attached Check_In has a `pending` Work_History_Edit_Request THEN Admin_Web SHALL flag the row and Backend_API SHALL exclude it from export (Requirement 10), because bulk import rejects an entire file when any row is already pending.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 7

### Requirement 9: Confirm freezes the batch

**User Story:** As a Reconciliation_Admin, I want confirming a review to freeze who decided what, so that history cannot be silently rewritten.

#### Acceptance Criteria

1. WHEN the admin confirms a Draft whose required decisions are complete THEN Backend_API SHALL set status to Confirmed, store confirmed_by and confirmed_at, and snapshot every row's match, Check_In id, workplace, DGF values, Canonical_Row values, and per-field choices.
2. IF required decisions are incomplete THEN Backend_API SHALL reject Confirm (422) and SHALL NOT change status.
3. WHEN a batch is Confirmed THEN Backend_API SHALL reject mutations of matches or field choices (409) and Admin_Web SHALL render the review read-only.
4. WHEN Confirm succeeds THEN Admin_Web SHALL offer export (Requirement 10) without requiring a second visit.
5. WHEN Confirm, row PATCH, or bulk-decide run THEN Backend_API SHALL lock the Reconciliation_Batch row (`SELECT ... FOR UPDATE`) in the same transaction as the status/decision write, so a concurrent mutation cannot land after the unfinished check and before the status flip.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 8

### Requirement 10: Export Work_History_Detail_Export files, one workplace each

**User Story:** As a Reconciliation_Admin, I want the accepted Amazon values written into the same Excel shape Chấm công already exports, split per workplace, so that Yêu cầu sửa công Import accepts the file.

#### Acceptance Criteria

1. WHEN export runs on a Confirmed batch THEN Backend_API SHALL produce Work_History_Detail_Export workbooks: visible columns A–P in the current order, hidden Check_In_ID, workplace header/separator rows as today's exporter writes them.
2. WHEN a Reconciliation_Row is exported THEN it SHALL be included only if it has a Check_In_ID, at least one Editable_Field chosen as Amazon that still differs from the snapshot DGF value, and no pending Work_History_Edit_Request.
3. WHEN writing G/H (check-in/out) THEN Backend_API SHALL write Excel day-fractions via the same `toDayFraction` rule as Work_History_Detail_Export (time-of-day only, no calendar datetime string). Break SHALL be written as minutes in the same numeric representation the exporter uses for the rest column.
4. WHEN rows in the batch belong to more than one workplace THEN Backend_API SHALL emit one `.xlsx` per workplace and SHALL package them in a `.zip`; WHEN they belong to one workplace THEN Backend_API SHALL emit a single `.xlsx`. Admin_Web SHALL state that Import accepts one workplace file at a time (not the zip).
5. IF every included row would be unchanged (no Amazon-accepted diffs, or only pending/unmatched/no-Check_In rows remain) THEN Backend_API SHALL NOT emit an empty-looking workbook; Admin_Web SHALL explain that there is nothing to import.
6. WHEN the admin later imports an exported `.xlsx` through Work_History_Edit_Request_List THEN that existing feature SHALL accept the file whenever it would accept a hand-edited Chấm công export of the same Check_Ins (lifecycle test: confirm → export → import preview shows old DGF / new Amazon for the accepted fields).
7. WHERE Admin_Web finishes an export THEN it SHALL record exported_by / exported_at / stored export path on the batch and MAY link to Work_History_Edit_Request_List. It SHALL remind the admin that Import still requires Work_History_Edit_Request_Process for that workplace — Reconciliation_Work alone does not authorize Import.
8. WHEN those exported values are later applied by import/approve THEN paid minutes SHALL continue to follow existing DGF rounding and overtime-clip rules; this feature SHALL NOT claim Amazon 実働/作業時間 was copied into payroll.
9. WHERE Work_History_Detail_Export on the current branch does not yet emit Check_In_ID (DGFC_132 / MR 304 not merged) THEN Backend_API SHALL still write that hidden last column on the reconciliation export so Import can match rows.
10. WHERE Work_History_Detail_Export already emits Check_In_ID THEN Backend_API SHALL reuse it and SHALL NOT append a second Check_In_ID column.
11. WHERE this feature implements Check_In_ID THEN it SHALL do so in a reconciliation-specific Excel wrapper, not by editing `WorkHistoryDetailExport` or `WorkHistoryService::buildDetailExportData`, so merging DGFC_132 does not conflict on those files.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 9, Work_History_Detail_Export / bulk import as specified in `.agents/specs/20260825-bulk-work-history-edit-import/`

### Requirement 11: Re-export, stale detection, and audit history

**User Story:** As a Reconciliation_Admin, I want to download the same confirmed result again, and to be stopped if DGF attendance has moved since I confirmed, so that I never import against a snapshot that is no longer true.

#### Acceptance Criteria

1. WHEN a Confirmed batch is re-exported and every exported Check_In's live Editable_Fields still equal the snapshot DGF values THEN Backend_API SHALL produce the same logical workbook contents and SHALL update last exported_by / exported_at.
2. IF any exported Check_In's live check-in time, check-out time, or `break_minutes` column differs from the confirm snapshot, or the Check_In no longer exists at all, THEN Backend_API SHALL refuse re-export (409 stale) and SHALL NOT invent a new merge. The response SHALL name every offending row (`row_ids`), not only the first, so the admin can resolve them in one pass; Admin_Web SHALL highlight and scroll to them.
3. WHEN listing or opening a batch THEN Admin_Web SHALL show uploaded_by/at, confirmed_by/at, exported_by/at, Source, original filename, and the stored original file as an audit artifact.
4. IF two admins upload the same bytes THEN Backend_API SHALL create two independent batches; the second SHALL NOT overwrite the first.
5. WHILE a Draft exists THEN only decision/map writes (Requirement 8.6) are allowed; deleting a Draft MAY be offered, Confirmed batches SHALL NOT be deleted in v1.
6. IF a Confirmed batch's row has lost its Check_In (hard-deleted by shift re-planning — `check_in_id` is `nullOnDelete` and Check_In has no soft deletes) THEN Backend_API SHALL accept `skipped: true` on that row alone as the single exception to 11.5, and Admin_Web SHALL offer it as "skip and export the rest". Rationale: unlike a drifted time, a deleted Check_In cannot be restored under its old id, the batch cannot return to Draft, and one such row would otherwise block the whole workbook forever — leaving "delete the batch and redo the reconciliation" as the only recovery. Skipping SHALL NOT alter the row's mapping, decisions or snapshot, so the audit trail still shows what was confirmed and against what.
7. WHILE a Confirmed batch is being re-exported, Backend_API SHALL build the artifact under a staging path and publish it only once complete, so a failed re-export leaves the previous downloadable export and its exported_by/at untouched.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 9, Requirement 10

### Requirement 12: Reconciliation never writes attendance or edit requests

**User Story:** As payroll, I want reconciliation to be comparison-only, so that no punch changes without going through Yêu cầu sửa công.

#### Acceptance Criteria

1. WHEN a batch is uploaded, matched, decided, confirmed, or exported THEN Backend_API SHALL NOT insert, update, or delete `check_ins`, `work_history_edit_requests`, or `work_history_edit_request_items`.
2. WHERE tests cover this guarantee THEN a full upload → confirm → export lifecycle SHALL leave those tables unchanged aside from unrelated concurrent activity.
3. WHEN Admin_Web describes the export step THEN it SHALL state that changing DGF attendance requires the existing Import on Work_History_Edit_Request_List.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 10

## Non-Functional Requirements

### Performance Requirements
- WHEN an Amazon_Kadou file of up to 500 Punch_Log rows (and up to ~2,000 skipped non-punch rows) is uploaded THEN Backend_API SHALL finish parse + match synchronously within 30 seconds on the production-class app server.
- WHEN matching names THEN Backend_API SHALL first consider users who have a Check_In on that Work_Date, then the remaining user directory, so a global (unscoped) search does not scan every employee with an expensive fuzzy predicate as the first step. An exact hit in that priority pool is still Ambiguous when another user in the remaining directory also exact-matches.
- Rows with only one 実打刻 end (forgot to clock in or out) are skipped as non-punch, matching the Punch_Log glossary (both ends required). They are not surfaced as Reconciliation_Rows in v1.

### Security Requirements
- WHEN any reconciliation endpoint is called THEN Backend_API SHALL require an authenticated Admin_Web session and Reconciliation_Work (Requirement 1.3).
- WHEN original and export files are stored THEN Backend_API SHALL place them in the existing private application storage (not a public disk) and SHALL serve them only to a Reconciliation_Admin.
- WHEN returning unmatched/similar candidates THEN Backend_API SHALL include only the user fields needed to identify a person (id, staff_no, names, avatar, optional Check_In summary), not unrelated PII.

### Usability Requirements
- WHEN a format, match, confirm, or export error occurs THEN Admin_Web SHALL show which file/row/field failed and what to do next (fix file, map user, pick Check_In, import per workplace, obtain process permission).
- WHEN overnight times are shown THEN Admin_Web SHALL keep +1-day visible so 06:03 is not read as the same calendar day as 20:19.
- WHERE copy is user-facing THEN Admin_Web SHALL use `t(...)` (no hardcoded UI strings), including Role Management labels for the new group and permission.

### Reliability Requirements
- IF parse or persist fails after a file was stored THEN Backend_API SHALL not leave an orphan Draft without rows and SHALL not leave an unreferenced stored file without a batch (delete or not-create together).
- IF Confirm is retried after success THEN Backend_API SHALL be idempotent (still Confirmed, no duplicate snapshots).
- IF export fails after Confirm THEN the batch SHALL remain Confirmed so the admin can retry export without re-merging.

## Constraints and Assumptions

### Technical Constraints
- Work_History_Detail_Export import (`.agents/specs/20260825-bulk-work-history-edit-import/`) accepts one workplace per `.xlsx` and rejects a pending edit request on any row by failing the whole file. Reconciliation export MUST obey both rules (Requirements 8.7, 10.2, 10.4).
- DGFC_132 / MR 304 adds hidden `Check_In_ID` on `WorkHistoryDetailExport`. This feature MUST NOT patch that class or `buildDetailExportData`; it wraps the existing exporter and adds `Check_In_ID` only when the wrappee's headings lack it (Requirement 10). After DGFC_132 merges, the wrapper becomes a no-op for that column.
- G/H cells are day-fractions without a date; import reconstructs calendar dates via `ResolveImportedAttendanceDatesAction`. Export MUST use `WorkHistoryService::toDayFraction`.
- Applying imported times still runs `WorkHelper::calculateWorkHour` (rounding, overtime clip). Accepting Amazon punches does not copy Amazon 実働 into `work_minutes*`.
- `.xlsx` parse/write already exists via Maatwebsite/Excel / PhpSpreadsheet (exports and the bulk-import reader).
- Permission grouping in Role Management is the first segment of the permission name; `reconciliation.work` creates the Đối soát group automatically once registered in the permission enums and scanner keys.

### Assumptions
- **Reconciliation_Work is company-wide.** Confirmed by stakeholder: a holder may reconcile every workplace; do not filter by the role's team_id.
- **Export is split per workplace** so MR 304 import can consume it. Confirmed by stakeholder.
- **Confirm freezes decisions; history is view + re-export only.** Confirmed by stakeholder.
- **Workplace comes from the matched Check_In, not 会社名.** Confirmed by stakeholder.
- **Only Punch_Log rows are reconciled; DGF-only check-ins are not listed.** Confirmed by stakeholder.
- **氏名 matching:** one token → kana given/family or Short_Name; family+given → combined kana or Short_Name; similar but inexact → admin maps with search. Confirmed by stakeholder.
- **No kana↔Latin transliteration in v1.** Short_Name exact match applies when the external name is already Latin (or happens to equal Short_Name); the Amazon sample is katakana and will use Name_Kana / similar / manual map.
- **The person who imports the exported file may be different** and must hold Work_History_Edit_Request_Process for that workplace.
- A maximum upload size will be set in design (guided by existing import limits) so parse+match stays synchronous (NFR).

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria in Requirements 1–12 are met
- [ ] Non-functional requirements are satisfied
- [ ] Role Management shows group Đối soát / permission Đối soát công làm nhân viên; users without it receive 403 and no menu item — verified by test
- [ ] A user whose only grant of Reconciliation_Work is via a workplace-A role can still match a Check_In at workplace B — verified by test
- [ ] Amazon sample file yields 48 Punch_Logs, skips 208 non-punch rows, and does not use 会社名 as workplace — verified by test
- [ ] Exact single-user kana match auto-maps; duplicate family-name token does not auto-map; similar names require manual choice; search can attach a user who was not in the top suggestions — verified by test
- [ ] Overnight 実打刻 `20:19 ~ 06:03` compares at minute precision against DGF raw times; Effective_Break_Minutes 60 vs null-with-template-60 is matched — verified by test
- [ ] Confirm freezes the batch; a later decision PATCH returns 409 — verified by test
- [ ] Export of a two-workplace batch is a zip of two Work_History_Detail_Export files; each file's import preview succeeds; a combined file is rejected CROSS_WORKPLACE by existing import — verified by lifecycle test
- [ ] Export includes hidden Check_In_ID and day-fraction G/H; import preview old/new equals DGF vs accepted Amazon for those fields — verified by lifecycle test
- [ ] `WorkHistoryDetailExport.php` and `WorkHistoryService::buildDetailExportData` are unchanged in this feature; Check_In_ID is added only by the reconciliation wrapper when missing, and is not duplicated when the wrappee already has it — verified by test
- [ ] Rows with pending edit requests are omitted from export; unmatched / no-Check_In / keep-all-DGF rows are omitted — verified by test
- [ ] Upload → confirm → export does not insert `work_history_edit_requests` or change `check_ins` — verified by test
- [ ] Re-export after someone edits a snapshotted Check_In returns stale 409 — verified by test
- [ ] Re-export after a snapshotted Check_In is hard-deleted returns stale 409 naming the row; skipping that row on the Confirmed batch unblocks the rest, while every other write to a Confirmed batch still returns 409 — verified by test
- [ ] A re-export that fails mid-write leaves the previous artifact, its bytes and exported_by/at intact, and no staging file behind — verified by test
- [ ] A second registered parser in tests can produce Canonical_Rows that flow through match and export without Amazon-specific code — verified by test
- [ ] Frontend `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`; backend `npm run prettier`, `npm run pint`; new tests camelCase
- [ ] All new Admin_Web strings go through `t(...)` and the translation workflow (`pnpm trans:prepare` / `trans:apply`)
