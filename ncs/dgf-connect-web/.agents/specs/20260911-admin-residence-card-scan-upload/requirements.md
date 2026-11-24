# Requirements Document

## Introduction

Employees upload their own residence card documents through the employee app (front side, back side, and an A4 scan copy), and the backend already OCRs and stores those. Nobody verifies that the scan an employee uploaded is actually a copy of the card that person carries. This feature gives an admin a second, independent record: an admin scans the employee's residence card themselves, the image goes through OCR, the admin confirms the read fields, and the submission is stored as an **Admin_Paper_Upload** — a record that lives entirely outside `user_papers` and never touches the employee's approval flow, `paper_status`, or `user_infos`. A new **Paper_Reconciliation** surface then holds that submission against **the residence information already on the employee's profile** and states which fields agree, which differ, and which employees have no admin scan on file yet.

The employee side of the comparison is the profile record — the `users` name columns plus the `user_infos` residence fields — not any particular image. Which image a profile value originally came from (the card's front, its back, or the scanned copy) is not recorded anywhere and does not matter here. The question this surface answers is whether **the card the employee is carrying matches what the company has on file**, because the profile is what the company acts on when it rosters that person onto a shift.

Uploading is admin-app only (see `.agents/specs/20260911-admin-residence-card-scan-upload-mobile/` in `dgf-connect-app-admin`). The web admin is read-only for this feature: it hosts the reconciliation list and the comparison popup. The only document type in scope is the residence card scan (`foreigner_card_scan`); the data model is typed so a second document can be added later without a schema change.

### Feature Summary

A new `user.paper.upload` permission lets an admin upload one residence-card scan per employee from the admin app. Each upload is OCRed asynchronously, confirmed by the admin, and stored with the submitting admin's identity. A new Paper_Reconciliation page under the User menu lists employees filtered by workplace, shift, date, and roster status, shows whether an admin scan exists, and opens a comparison popup with the admin's image beside the employee's own scan image and a field-by-field verdict against the employee's profile.

### Business Value

The employee-uploaded scan is currently self-reported: nothing stops an employee from uploading a copy of somebody else's card, an expired card, or a doctored image, and the existing cross-check only compares the employee's own images against each other. An admin-captured scan is an independent observation of the physical card, so a card number, expiry date, or name that differs between the two sources is a concrete signal to investigate — before that employee is rostered onto a shift they are not legally allowed to work. Recording which admin submitted which scan also gives the company an audit trail for immigration compliance checks.

### Scope

**In scope:**

- A new permission `user.paper.upload` ("Upload hồ sơ nhân viên"), created and granted to the built-in Global Admin and Admin roles by migration.
- An `admin_paper_uploads` table storing one row per admin upload attempt, fully independent of `user_papers` / `user_paper_items` / `user_paper_steps` / `user_paper_ocr_results`.
- Backend endpoints to upload, poll OCR, discard a draft, and submit a confirmed Admin_Paper_Upload.
- Asynchronous OCR of the admin's image reusing the existing `OpenRouterOcrService` (`residence_card_scan` group), `OcrFieldNormalizer`, and `ValidateOcrRequiredFieldsAction`, in a dedicated job mirroring `ExtractDocumentStepOcrJob`.
- A Paper_Reconciliation list endpoint filtered by workplace, workplace shift, date, reconciliation status, and keyword.
- A Paper_Reconciliation detail endpoint returning both images and the field-by-field comparison plus the admin upload history for one employee.
- Web admin: a new sidebar item under the **User** group, the reconciliation list page, and the comparison popup.
- A daily job pruning abandoned drafts and their files.
- Vietnamese and Japanese translations for all new web copy, via the `pnpm trans:prepare` / `pnpm trans:apply` flow.
- Test coverage per the repository's test design rules, including a full lifecycle test through the real endpoints.

**Out of scope:**

- Uploading from the web admin. Requirement 4 of the task is explicit: capture happens on the admin app only. The web surfaces are read-only.
- Any document type other than the residence card scan. `paper_type` is validated to `foreigner_card_scan` only.
- Writing anything the admin scan reads into `user_infos`, `users`, or the employee's `user_papers`, and any effect on `paper_status`, step statuses, `document_mismatches`, approval, or rejection.
- Running OCR on any employee image. The employee side is read from the profile columns as stored; no `user_paper_ocr_results` row is read for field values and none is created.
- Attributing a profile value back to a particular image. The profile is one flat set merged across the card's two sides and the scanned copy, so a mismatch says the card disagrees with the record, not which image was wrong. Working out which image caused it is the reviewer's job with both pictures in front of them.
- Any change to how the employee's own documents are OCRed, including the `foreigner_card_scan` group mapping in `ExtractPapersOcrAction` (see Technical Constraints — a real defect, deliberately left for its own change).
- Showing Admin_Paper_Uploads to the employee, in the employee app, or on any employee-facing endpoint.
- Approving, rejecting, or acting on a mismatch. The reconciliation surface reports; the existing paper approval flow remains the only place decisions are made.
- Notifications (push, mail, or in-app) on upload, mismatch, or missing scan.
- Bulk upload of many employees' scans in one request.
- Comparison of `address`, `prefecture`, or the kana names. They are displayed side by side but never produce a mismatch verdict (Requirement 7).

## Glossary

| Term | Definition |
|------|------------|
| Admin_Paper_Upload | One row of `admin_paper_uploads`: an image an admin captured for an employee, its OCR outcome, the fields the admin confirmed, and who submitted it. |
| Draft_Upload | An Admin_Paper_Upload with `submitted_at IS NULL`, i.e. still being OCRed, read, or confirmed. Never appears on the reconciliation surface. |
| Submitted_Upload | An Admin_Paper_Upload with `submitted_at` set. Immutable. |
| Current_Admin_Scan | The newest Submitted_Upload for an employee and paper type. The one shown on the reconciliation list. |
| Employee_Scan | The employee's own `foreigner_card_scan` `user_paper_item` chosen by the Current_Item_Rule. **Shown in the popup for the reviewer's own eyes; never a source of compared values.** |
| Current_Item_Rule | Newest non-deleted item of the type, preferring an item awaiting review over the newest approved item in the `current` folder — the rule `ExtractPapersOcrAction::eligibleItemsByType()` already applies. |
| Employee_Fields | The employee side of every comparison, read from the profile as stored: `name` from `users.last_name` + `users.first_name`; `last_name_kana` / `first_name_kana` from the `users` columns of the same name; `birthday`, `gender`, `nationality`, `address`, `prefecture` from `user_infos`; `residence_number`, `residence_expiry_date`, `residence_status` from the Current_Card_Slot. No OCR result is read and no OCR is run. |
| Current_Card_Slot | `user_infos` keeps up to three residence cards (`residence_number_1..3` and friends). The current one is the slot with the latest expiry date, exactly as `ResidenceSlotResolver::latestSlot()` decides — the existing single source of truth for that question. |
| Previous_Card_Match | The admin's `residence_number` equals a card in a slot other than the Current_Card_Slot. Still a mismatch, but a distinguishable one: the admin scanned a card the profile has already superseded, rather than a card the profile has never seen. |
| Confirmed_Fields | The field values the admin accepted or corrected on the confirm screen, stored on the Admin_Paper_Upload and used as the admin side of every comparison. |
| Compared_Field | One of `name`, `birthday`, `gender`, `nationality`, `residence_number`, `residence_expiry_date`, `residence_status` — the only fields that can produce a mismatch. |
| Field_Verdict | Per Compared_Field: `match`, `mismatch`, or `unknown` (one or both sides have no value). |
| Reconciliation_Verdict | Per employee, exactly one of `not_uploaded`, `mismatched`, `employee_data_missing`, `matched`, `not_comparable` — resolved in that fixed order (Requirement 7.5), since more than one can be true of the same row. |
| Employee_Residence_Missing | A separate boolean on every reconciliation response: the profile holds no residence card in any slot. It travels alongside the verdict rather than being one, because a profile can lack a card *and* disagree with the scanned one, and the surface must report both. |
| Paper_Reconciliation | The read-only surface (list + comparison popup) built on the verdicts. |
| Upload_Permission | `user.paper.upload` — required to create, poll, discard, or submit an Admin_Paper_Upload. |
| View_Permission | `user.paper.list` — the existing permission, required for the Paper_Reconciliation list and detail. |
| OCR_Run_Budget | At most 3 OCR runs per draft cycle per admin. Enforced at upload (Requirement 3.8), not only at submit (Requirement 5.5/5.6): submit-time enforcement never reaches the request that spends the money. The counter resets when a submission closes the cycle. |

## Requirements

### Requirement 1: Upload permission

**User Story:** As a system administrator, I want a dedicated permission for uploading employee documents, so that I can let workshop staff capture residence cards without also giving them the power to approve or delete an employee's own documents.

#### Acceptance Criteria

1. WHEN the permission set is migrated THEN Backend_API SHALL create `user.paper.upload` and grant it to the built-in Global Admin and Admin roles, using the idempotent `firstOrCreate` + `syncWithoutDetaching` + `flushCache` shape of `2026_08_28_000001_grant_reconciliation_work_permission.php`, with the permission string hardcoded in the migration.
2. WHEN an administrator opens Role Management THEN Admin_Web SHALL display the new toggle inside the existing **User** permission group next to `user.paper.list` / `user.paper.approve`.
3. IF a user lacks Upload_Permission THEN Backend_API SHALL reject `POST /admin/users/{user}/paper-uploads`, `GET /admin/paper-uploads/{upload}`, `POST /admin/paper-uploads/{upload}/submit`, and `DELETE /admin/paper-uploads/{upload}` with 403.
4. IF a user lacks View_Permission THEN Backend_API SHALL reject the Paper_Reconciliation list and detail endpoints with 403, and Admin_Web SHALL hide the sidebar entry.
5. WHEN Upload_Permission is held THEN Backend_API SHALL treat the holder as authorized for every employee, consistent with the other `user.paper.*` permissions, and SHALL NOT apply `applyWorkplaceFilter` to the employee being uploaded for.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None

### Requirement 2: Admin uploads are independent of the employee's documents

**User Story:** As a compliance officer, I want the admin's scan stored completely apart from the employee's own submission, so that comparing the two can never alter, approve, or pollute what the employee filed.

#### Acceptance Criteria

1. WHEN an Admin_Paper_Upload is created, OCRed, confirmed, submitted, or discarded THEN Backend_API SHALL NOT create, update, or delete any row in `user_papers`, `user_paper_items`, `user_paper_steps`, or `user_paper_ocr_results`. This holds without exception: the reconciliation surface reads the employee's profile and images and writes nothing on that side, ever.
2. WHEN an Admin_Paper_Upload is submitted THEN Backend_API SHALL NOT write any value into `user_infos` or `users`, and SHALL NOT change `users.paper_status`.
3. WHEN an Admin_Paper_Upload exists for an employee THEN the employee's document step flow (`GET /user/document-steps`, upload, confirm, submit) SHALL behave exactly as if it did not exist, including `missing_required_steps` and `document_mismatches`.
4. WHEN any employee-facing endpoint returns paper data THEN it SHALL NOT include Admin_Paper_Upload rows, their images, or their fields.
5. WHEN admin scan files are stored THEN Backend_API SHALL write them under a path separate from `users/{id}/papers`, so a bulk operation on employee papers cannot touch them, while using the same disk so the existing static/CDN serving path works unchanged.
6. WHERE `DeleteOldUserPaperItems` or any other existing paper maintenance job runs THEN it SHALL NOT select Admin_Paper_Upload rows or files.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 3

### Requirement 3: Upload one residence card scan

**User Story:** As an admin at a workplace, I want to send one photo of an employee's residence card scan to the server, so that the system can read it and I can confirm what it read.

#### Acceptance Criteria

1. WHEN an admin calls `POST /admin/users/{user}/paper-uploads` with a `file` and `paper_type` THEN Backend_API SHALL validate `paper_type` is `foreigner_card_scan`, validate `file` is an image of an accepted mime type and at most 10 MB, store it, create an Admin_Paper_Upload in status `ocr_processing`, and dispatch the OCR job after commit.
2. WHEN the image is stored THEN Backend_API SHALL downscale it to at most 2000 px wide — not the 1200 px default of `ImageHelper::handleImageUpload` — because an A4 scan reduced to 1200 px loses the residence number's small print that OCR depends on.
3. IF storing the file succeeds but the transaction fails THEN Backend_API SHALL delete the stored file, matching `UploadDocumentStepAction`'s cleanup.
4. WHEN the admin already has a Draft_Upload for the same employee and paper type THEN Backend_API SHALL discard that draft and its file and carry its `ocr_run_count` forward onto the new row, so a retake replaces the previous attempt instead of accumulating drafts.
5. WHEN the target user is not an employee the admin may act on (deleted user, non-existent id) THEN Backend_API SHALL return 404.
6. WHEN an admin uploads for an employee who already has a Submitted_Upload THEN Backend_API SHALL accept it and create a new draft; the previous Submitted_Upload SHALL remain untouched in history.
   - WHERE a Submitted_Upload for that employee and paper type was made within the last 24 hours THEN the upload response SHALL carry it as `recent_submission: { submitted_by: { id, name }, submitted_at }`, `null` when there is none, so the admin app can say "a colleague scanned this employee at 14:05 — continue?" before the admin spends another read. It is a notice, not a block: a deliberate re-scan is a legitimate reason to continue.
   - Drafts are per admin, so two admins scanning the same employee never overwrite each other; both submissions land in history and the newest is the Current_Admin_Scan.
7. WHEN `paper_type` is any value other than `foreigner_card_scan` THEN Backend_API SHALL return 422 without storing a file.
8. IF the admin's current Draft_Upload for this employee and paper type is `ocr_failed` with `ocr_run_count >= 3` THEN Backend_API SHALL reject the upload with 422 and the unreadable error, without storing a file and without spending a fourth OCR run.
   - The budget exists for cost (see Technical Constraints), and cost is spent by the upload, not by the submit. Enforcing it only at submit — Requirement 5.5/5.6 — leaves the paid call unguarded, so the cap has to sit here as well.
   - This is the guard the employee flow already applies: `UploadDocumentStepAction` refuses a replacement unless `UserPaperStep::canReplaceUnreadableUpload()` allows it, which is false once `MAX_OCR_RUNS_PER_CYCLE` is reached. The admin flow claims to mirror that flow and must mirror this part of it too.
   - The way out of a spent budget is the manual confirmation of Requirement 5.6, not another read. A retake from any other status (`ocr_processing`, `ocr_ready`, or `ocr_failed` under the cap) is still accepted per 3.4.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 4: Asynchronous OCR of the admin scan

**User Story:** As an admin on a phone with a weak connection, I want the upload request to return immediately and the reading to happen in the background, so that a slow OCR provider does not time out my upload.

#### Acceptance Criteria

1. WHEN an Admin_Paper_Upload is created THEN Backend_API SHALL run OCR in a queued job, not inside the HTTP request, using `OpenRouterOcrService::extract()` with group `residence_card_scan`, `retryWithFallback: true`, followed by `OcrFieldNormalizer::normalize()`.
2. WHEN OCR completes successfully and `ValidateOcrRequiredFieldsAction` passes for `residence_card_scan` THEN Backend_API SHALL store the normalized fields, warnings, and answering model on the row and set status `ocr_ready`.
3. WHEN `ValidateOcrRequiredFieldsAction` reports missing required fields THEN Backend_API SHALL store the partial fields and warnings, set status `ocr_failed` with the `OcrRequiredFieldsMissingException::ERROR_CODE` error, and SHALL NOT spend a queue retry on the same image.
4. WHEN the provider errors and every queue attempt is exhausted THEN Backend_API SHALL set status `ocr_failed` with a truncated error message.
5. WHEN the job starts and the row is missing, already `submitted`, or no longer `ocr_processing` THEN the job SHALL return without calling the provider.
6. WHEN an admin calls `GET /admin/paper-uploads/{upload}` THEN Backend_API SHALL return the current status, the OCR fields, the warnings, the error, and `ocr_run_count`, so a client can poll until the status leaves `ocr_processing`.
7. IF the poll target was created by a different admin THEN Backend_API SHALL return 403 — draft or submitted alike, since the capture endpoints are the acting admin's own workspace. A Submitted_Upload is readable by any holder of View_Permission through the Paper_Reconciliation detail endpoint, which is the surface built for reading it.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 3

### Requirement 5: Confirm before submit, with a bounded OCR budget

**User Story:** As an admin, I want to review what OCR read and correct it before the record is stored, so that a misread card number is not saved as fact — and I want a way through when OCR simply cannot read the card.

#### Acceptance Criteria

1. WHEN an Admin_Paper_Upload is in status `ocr_ready` THEN Backend_API SHALL accept `POST /admin/paper-uploads/{upload}/submit` with a `fields` object, store it as Confirmed_Fields, set `submitted_by_id` to the acting admin, set `submitted_at`, and move the row to status `submitted`.
2. WHEN `fields` is submitted THEN Backend_API SHALL accept only the residence-card keys the normalizer produces (`name`, `last_name_kana`, `first_name_kana`, `birthday`, `gender`, `nationality`, `address`, `prefecture`, `residence_number`, `residence_expiry_date`, `residence_status`, `residence_status_raw`), reject unknown keys with 422, and validate each value's type/format (dates `Y-m-d`, `gender` in `Gender`, `nationality` in `Nationality`, `residence_status` in `ResidenceStatus`).
   - **`prefecture` is validated as a plain string, not against the `Prefecture` enum.** `ExtractJapanesePrefectureAction` emits the prefecture's *Japanese name* (`東京都`), not the enum's canonical key (`tokyo`), so an enum rule would reject the pipeline's own output. `DocumentStepConfirmRules` validates the employee's entry the same loose way. It is a display-only field in the comparison, so nothing downstream depends on its shape.
   - `residence_status_raw` is the residence status exactly as printed on the card, before it is mapped onto `ResidenceStatus`. It is stored and displayed but never compared, because the printed wording legitimately varies.
   - WHEN the row reached `ocr_ready` THEN Backend_API SHALL carry `residence_status_raw` over from `ocr_fields` itself and SHALL ignore any client-supplied value, so the record keeps what the card actually said.
   - WHEN the row is submitted through the manual fallback (5.6) THEN the admin MAY supply `residence_status_raw` as free text, since no machine read exists to carry over.
3. WHEN Confirmed_Fields differ from the stored `ocr_fields` THEN Backend_API SHALL keep both, so the record shows whether the admin corrected the machine read.
4. IF the row is in status `ocr_processing` THEN Backend_API SHALL reject submit with 422 — a submission must go through OCR, never around it.
5. IF the row is in status `ocr_failed` AND `ocr_run_count < 3` THEN Backend_API SHALL reject submit with 422 and an error telling the client to retake the photo.
6. IF the row is in status `ocr_failed` AND `ocr_run_count >= 3` THEN Backend_API SHALL accept submit with admin-typed `fields`, mirroring the manual fallback the employee step flow opens once its OCR budget is spent, and SHALL record on the row that it was confirmed without a successful read.
7. WHEN a row is already `submitted` THEN Backend_API SHALL reject a second submit with 422, so a Submitted_Upload is immutable.
8. WHEN an admin calls `DELETE /admin/paper-uploads/{upload}` on their own Draft_Upload THEN Backend_API SHALL delete the row and its file; on a Submitted_Upload or another admin's draft it SHALL return 403.
9. WHEN a Draft_Upload has not been submitted within 24 hours THEN a daily job SHALL delete the row and its file, following the `DeleteStaleShiftPlanDrafts` pattern already scheduled in `routes/console.php`.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4

### Requirement 6: Who submitted what is recorded

**User Story:** As a compliance officer, I want every admin scan to name the admin who submitted it, so that a disputed record can be traced to a person and a time.

#### Acceptance Criteria

1. WHEN an Admin_Paper_Upload is created THEN Backend_API SHALL record `uploaded_by_id` as the authenticated admin.
2. WHEN it is submitted THEN Backend_API SHALL record `submitted_by_id` and `submitted_at`, without overwriting `uploaded_by_id`.
3. WHEN the reconciliation list or detail returns a Current_Admin_Scan THEN it SHALL include the submitting admin's id and display name and the submission timestamp.
4. WHEN the reconciliation detail returns the upload history THEN every Submitted_Upload for that employee SHALL appear with its submitter, timestamp, image, and the verdict recomputed against the current employee data.
5. WHEN a submitting admin's account is later deleted THEN the reconciliation surfaces SHALL still render the row without failing, falling back to a placeholder name.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 5

### Requirement 7: Field-by-field comparison against the employee's profile

**User Story:** As an admin reviewing documents, I want to see exactly which fields the card in my hand and the employee's record disagree on, so that I can spot a changed card number without reading an image against a form field by field.

#### Acceptance Criteria

1. WHEN comparing an employee THEN Backend_API SHALL take the admin side from the Current_Admin_Scan's Confirmed_Fields and the employee side from Employee_Fields, and SHALL NOT call the OCR provider or read any `user_paper_ocr_results` row.
2. WHEN building Employee_Fields THEN Backend_API SHALL read:

   | Compared_Field | Source |
   |---|---|
   | `name` | `users.last_name` and `users.first_name`, joined with a single space |
   | `birthday` | `user_infos.birthday` |
   | `gender` | `user_infos.gender` |
   | `nationality` | `user_infos.nationality` |
   | `residence_number` | `user_infos.residence_number_{Current_Card_Slot}` |
   | `residence_expiry_date` | `user_infos.residence_expiry_date_{Current_Card_Slot}` |
   | `residence_status` | `user_infos.residence_status_{Current_Card_Slot}` |

   - Backend_API SHALL determine the Current_Card_Slot through `ResidenceSlotResolver::latestSlot()` rather than a rule of its own, so the surface and the rest of the system never disagree about which card is current.
3. WHEN both sides have a non-empty value for a Compared_Field THEN Backend_API SHALL emit `match` or `mismatch`, bringing **both** sides to the same shape before comparing. The admin side arrives already normalized by `OcrFieldNormalizer`; the profile side never passed through it, so each field says explicitly what is done to it:

   | Compared_Field | Normalization applied to both sides before comparing |
   |---|---|
   | `name` | Upper-cased, then compared as an unordered set of whitespace-separated parts, exactly as `DocumentFieldComparator::comparisonKey()` does. The card prints the family name first; `users.first_name` / `last_name` hold whatever was confirmed or typed by an admin creating the account, in whatever casing. |
   | `residence_number` | Upper-cased with spaces and hyphens stripped — the same shape `OcrFieldNormalizer` already gives the admin side. |
   | `birthday`, `residence_expiry_date` | Formatted as `Y-m-d` strings. |
   | `gender`, `nationality`, `residence_status` | Compared as stored: both sides are values of the same backing enum. |

   - The `residence_number` rule is not cosmetic. The employee side is stored exactly as the employee typed it — `DocumentStepConfirmRules::residenceCardRules()` validates only `required|string|min:12|max:12` and `ApplyDocumentStepConfirmationAction` writes the string through unchanged — so comparing it as stored would report a lower-cased card number as a mismatch and send a reviewer after nothing.
   - The date rule guards a trap rather than a present bug: `UserInfo` declares no casts today, so its `date` columns already read back as `Y-m-d`. Pinning the format keeps the comparison correct if a cast is ever added.
4. WHEN either side has no value for a Compared_Field THEN the Field_Verdict SHALL be `unknown` and it SHALL NOT make the employee `mismatched`.
5. WHEN the Reconciliation_Verdict is resolved THEN Backend_API SHALL apply exactly this order, first match winning, so no two criteria can both claim the row:

   1. no Submitted_Upload exists → `not_uploaded` (7.8);
   2. at least one Compared_Field is `mismatch` → `mismatched`;
   3. the profile carries no residence information at all → `employee_data_missing` (7.7);
   4. at least one Compared_Field is `match` → `matched`;
   5. otherwise → `not_comparable`.

   - The order is part of the requirement, not an implementation detail: without it, an employee with no card on file **and** a name that disagrees with the scanned card satisfies two criteria at once, and which badge they get would be left to whichever branch happened to run first.
6. WHEN `residence_number` mismatches the Current_Card_Slot but equals the number in another slot THEN the Field_Verdict SHALL remain `mismatch` and the response SHALL additionally flag it as a Previous_Card_Match, so the popup can say the admin scanned a superseded card rather than implying the employee presented a card the record has never seen. The two situations call for different follow-up and must not look alike.
7. WHEN the employee's profile carries no residence information at all — no slot holds a number, expiry, or status — THEN Backend_API SHALL set the response flag `employee_residence_missing`, return `null` for the employee side of `residence_number`, `residence_expiry_date`, and `residence_status`, and still return the admin side and both available images.
   - **An absent card blanks the three residence fields and nothing else.** Backend_API SHALL still read `name`, `birthday`, `gender`, `nationality`, and the display-only fields from the profile and SHALL still emit a Field_Verdict for each Compared_Field among them. Dropping the whole employee side would hide a scanned card carrying somebody else's name on precisely the employees most likely to have a problem.
   - WHEN no Compared_Field is a `mismatch` THEN the Reconciliation_Verdict SHALL be `employee_data_missing` per the order in 7.5. WHEN one is, the verdict SHALL be `mismatched` and `employee_residence_missing` SHALL still be set, so the surface reports both facts rather than hiding one behind the other.
8. WHEN there is no Submitted_Upload for the employee THEN the Reconciliation_Verdict SHALL be `not_uploaded`, ahead of every other criterion per 7.5: with no admin side there is nothing to compare, whatever the profile holds. `employee_residence_missing` MAY still be set on such a row as a plain fact about the profile.
9. WHEN the response is built THEN `address`, `prefecture`, `last_name_kana`, `first_name_kana`, and `residence_status_raw` SHALL be returned side by side for display but SHALL NOT carry a Field_Verdict and SHALL NOT affect the Reconciliation_Verdict, because address formatting, kana transliteration, and the wording printed for a residence status all differ legitimately between a record and a fresh read.
10. WHEN the employee's profile changes after an admin scan was submitted — a renewed card confirmed, a name corrected — THEN the next read of the reconciliation surface SHALL reflect it, because the verdict is computed on read and never cached.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 5

### Requirement 8: Reconciliation list filtered by workplace, shift, and date

**User Story:** As an admin working a shift, I want to pull up exactly the employees on this shift today and see who I have already scanned, so that I can work through the line without guessing who is left.

#### Acceptance Criteria

1. WHEN `GET /admin/paper-reconciliation` is called THEN Backend_API SHALL return a paginated list of employees with, per row: the employee's identity fields, the Employee_Scan image reference and its capture time when present, the Current_Admin_Scan reference with submitter and timestamp when present, the Reconciliation_Verdict, and the number of mismatching Compared_Fields.
2. WHEN `date` is supplied THEN Backend_API SHALL restrict the list to employees having a `shift_users` row on a `shift` whose date matches; WHEN `workplace_id` is supplied it SHALL further restrict by that workplace; WHEN `workplace_shift_id` is supplied it SHALL further restrict by that shift template.
   - WHEN `shift_user_status` is supplied THEN Backend_API SHALL further restrict to `approved` or `pending` assignments accordingly; WHEN it is omitted THEN both are included.
   - WHERE a `shift_users` row is `rejected` THEN Backend_API SHALL exclude it under every value of `shift_user_status`, including the default, because a rejected assignment means the employee is not on that shift at all.
   - IF `shift_user_status` is supplied without `date` THEN Backend_API SHALL return 422, since a roster status is meaningless without a roster day.
   - WHEN `workplace_id` is supplied **without** `date` THEN Backend_API SHALL restrict the list to employees registered to that workplace through `workplace_users`, the membership reading the existing admin user list already gives a workplace filter (`UserService`). A workplace on its own is a membership question; only with a date does it become a roster question. The page offers the two controls independently, so this combination has to be defined rather than left to whichever branch happens to run.
3. IF `workplace_shift_id` is supplied without `workplace_id` or `date` THEN Backend_API SHALL return 422, because a shift template alone does not identify a roster.
4. WHEN no filters are supplied THEN Backend_API SHALL list active employees — role `user` and `is_disabled = false` — ordered deterministically, so the page is usable before any filter is chosen.
   - There is no third condition to apply: `users` carries no `deleted_at` and no "deleted" status, and the existing admin user list scopes the same two ways.
5. WHEN `status` is supplied THEN Backend_API SHALL filter to one of `uploaded`, `not_uploaded`, `matched`, `mismatched`, `employee_data_missing`, `not_comparable`, where `uploaded` means any Submitted_Upload exists. Every Reconciliation_Verdict SHALL be reachable by some filter value, so no employee can settle into a state the list cannot be narrowed down to.
6. WHEN `keyword` is supplied THEN Backend_API SHALL match it against the employee's staff number and names, reusing the existing user search behaviour.
7. WHEN the list is built for N employees THEN Backend_API SHALL resolve the Current_Admin_Scan, Employee_Scan, and Employee_Fields in a bounded number of queries independent of N, not one query per row.
8. WHEN a filter combination selects an employee with neither scan THEN the row SHALL still appear with verdict `not_uploaded`, because "nobody has scanned this person" is the main thing the list is for.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 7

### Requirement 9: Filter options for workplace and shift

**User Story:** As an admin, I want the shift dropdown to offer only shifts that actually ran at the chosen workplace on the chosen day, so that I do not pick a combination that returns nothing.

#### Acceptance Criteria

1. WHEN `GET /admin/paper-reconciliation/filter-options?workplace_id=&date=` is called THEN Backend_API SHALL return the workplace shifts that have a `shift` row for that workplace and date, with id, start time, end time, and a `label`.
   - `workplace_shifts` carries **no name column**. The app already labels a shift by its hours (`AnalyzeDeploymentWindowAction` builds `start_time . '-' . end_time`), so `label` is that same string rather than a field this feature would have to invent.
2. IF `workplace_id` or `date` is missing THEN Backend_API SHALL return 422.
3. WHEN a client needs the workplace list THEN it SHALL use the existing workplace endpoints; this feature SHALL NOT add a second workplace list.
4. WHEN the endpoint is called THEN it SHALL require View_Permission.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 8

### Requirement 10: Reconciliation detail for one employee

**User Story:** As an admin, I want one call that gives me everything the comparison popup shows, so that opening the popup does not fan out into several requests.

#### Acceptance Criteria

1. WHEN `GET /admin/paper-reconciliation/{user}` is called THEN Backend_API SHALL return the employee's identity, the Employee_Scan image reference with its upload time, the Current_Admin_Scan image reference with submitter and time, the full field table (compared and display-only fields, both sides, per-field verdict), the Reconciliation_Verdict, the Previous_Card_Match flag when it applies, and the list of previous Submitted_Uploads.
2. WHEN the employee has no Employee_Scan image THEN the response SHALL return `null` for that image, without affecting the field table — the image is shown for the reviewer's eyes and is not where compared values come from.
2a. WHEN the employee's profile carries no residence information THEN the employee column of the **three residence fields** SHALL be `null` and `employee_residence_missing` SHALL be set, while `name`, `birthday`, `gender`, `nationality`, and the display-only fields SHALL still carry their profile values and their Field_Verdicts. The Reconciliation_Verdict follows the order in Requirement 7.5: `employee_data_missing` when nothing mismatches, `mismatched` when something does.
3. WHEN the Current_Admin_Scan was submitted without a successful OCR read (Requirement 5.6) THEN the response SHALL flag it, so the popup can tell the reviewer the admin side was typed by hand.
4. WHEN the response includes OCR warnings from either side (e.g. `residence_number_format`, `residence_status_unmapped`) THEN it SHALL surface them, so a badly formatted card number is visible even when both sides agree on it.
5. WHEN the endpoint is called for a user id that does not exist or is not an employee THEN it SHALL return 404.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 7

### Requirement 11: Web admin reconciliation page

**User Story:** As an admin at a desk, I want a page under the employee menu that lists reconciliation status and lets me open a detailed comparison, so that I can audit documents without a phone.

#### Acceptance Criteria

1. WHEN an admin holding View_Permission opens Admin_Web THEN Admin_Web SHALL show a new item inside the existing **User** sidebar group, beneath "User list", linking to the Paper_Reconciliation page.
2. WHEN the page loads THEN Admin_Web SHALL render filters for workplace, shift, date, roster status, reconciliation status, and a keyword search, and SHALL repopulate the shift options from the filter-options endpoint whenever workplace or date changes. The roster status filter SHALL offer "approved" and "pending" and SHALL default to showing both.
3. WHEN results render THEN Admin_Web SHALL show one row per employee with identity, an employee-scan indicator, an admin-scan indicator with submitter and submission time, a colour-coded verdict badge, and a button opening the comparison popup.
4. WHEN the comparison popup opens THEN Admin_Web SHALL show both images side by side at a size where the card is legible, with a way to view each at full size, and beneath them a table of fields with the employee value, the admin value, and a per-field match/mismatch/unknown marker, mismatching rows visually distinct.
5. WHEN an employee has no admin scan THEN the popup SHALL state that clearly and SHALL NOT offer an upload action, because upload is admin-app only.
5a. WHEN `employee_residence_missing` is set THEN the popup SHALL say the employee's profile carries no residence card yet and SHALL point the reviewer at the employee's document flow rather than offering an action of its own. It SHALL keep rendering the `name`, `birthday`, `gender`, and `nationality` rows with their verdicts, because those still compare and a mismatch among them on an employee with no card on file is the strongest signal the surface can produce.
5b. WHEN a `residence_number` mismatch is a Previous_Card_Match THEN the popup SHALL say so in words — that the scanned card matches an earlier card on the employee's record — instead of presenting it as an unknown card, and SHALL show which slot it matched.
5c. WHEN the employee has no Employee_Scan image THEN the popup SHALL show the admin's image alone and say the employee has not submitted a scanned copy, without implying the comparison failed: the field verdicts stand on the profile regardless.
5d. WHEN `employee_residence_missing` is set **and** the Reconciliation_Verdict is `mismatched` THEN the popup SHALL state both facts — the card is absent from the record *and* what was scanned disagrees with the profile — rather than letting the badge stand for only one of them.
6. WHEN the popup shows the upload history THEN it SHALL list previous Submitted_Uploads with submitter and timestamp and let the reviewer view each image.
7. WHERE any copy is user-facing THEN Admin_Web SHALL use `t(...)` with explicit keys and SHALL NOT hardcode text, and all new keys SHALL be translated into `vi` and `ja` through `pnpm trans:prepare` / `pnpm trans:apply`.
8. WHEN the filters produce no rows THEN Admin_Web SHALL render a translated empty state naming the active filters rather than a blank table.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirements 8, 9, 10

## Non-Functional Requirements

### Performance Requirements
- WHEN the reconciliation list returns a page of up to 50 employees THEN Backend_API SHALL respond within 2 seconds, using bounded queries (Requirement 8.7) rather than per-row lookups.
- WHERE `status` is `matched`, `mismatched`, or `not_comparable` — the verdicts that cannot be expressed in SQL, because one side is a JSON blob — THEN Backend_API SHALL require at least one of `date`, `workplace_id`, or `keyword` alongside it and SHALL return 422 otherwise. Those filters have to enrich every candidate employee before they can paginate, which is unbounded across a whole company and would break the 2-second budget above; requiring a narrowing filter keeps the candidate set the size of a shift rather than the size of the payroll.
- WHEN an admin uploads a scan THEN the upload request SHALL return before OCR runs, so the client never waits on the provider inside the request (Requirement 4.1).
- WHEN the OCR job runs THEN it SHALL bound its own runtime the way `ExtractDocumentStepOcrJob` does (`tries = 2`, `timeout = 130`, `backoff [10]`), so two provider attempts cannot pile up on the queue.

### Security Requirements
- WHEN admin scan images are stored THEN Backend_API SHALL place them on the same storage disk employee paper images already use, under a separate path prefix (Requirement 2.5).
- WHEN an admin scan image is served THEN it SHALL be reachable the same way employee paper images already are — by path through the configured static/CDN base, with no additional per-request authorization. This is parity with the existing paper images, not an improvement on them: **the same unauthenticated-by-path exposure that applies to employee residence card images will apply to admin scans.** The stored filename is the random name Laravel's `store()` generates, so the exposure is "anyone holding the link can open it", not "anyone can enumerate the folder" — a distinction the reviewer needs in order to weigh the risk correctly. Flagged for the reviewer: if that is unacceptable for a second copy of identity documents, serving both through an authenticated endpoint is a separate change affecting the existing paper surfaces too, and is out of scope here.
- WHEN a Draft_Upload is polled, submitted, or discarded THEN Backend_API SHALL require the acting admin to be the one who created it (Requirements 4.7, 5.8).
- WHEN the reconciliation list returns employee data THEN it SHALL include only the identity fields the surface needs, not unrelated PII.

### Usability Requirements
- WHEN OCR fails to read the card THEN the response SHALL distinguish "unreadable, retake the photo" from "provider error, try again" so the client can say which happened.
- WHEN a Field_Verdict is `unknown` THEN the surfaces SHALL render it as "cannot compare", never as a match, so a blank value is not mistaken for agreement.
- WHEN a `residence_number` mismatch is a Previous_Card_Match THEN the surfaces SHALL say the scanned card is one the record has superseded, never leave it looking like a card the record has never seen — the two lead a reviewer to opposite conclusions.
- WHEN a residence status could not be mapped onto `ResidenceStatus` THEN the surfaces SHALL show the printed wording (`residence_status_raw`) next to the mapped value on both sides, so the reviewer can judge the card without reopening the image.
- WHEN the reviewer looks at a mismatch THEN the popup SHALL make the differing values readable next to each other without scrolling between them.

### Reliability Requirements
- IF the OCR job is retried after the row already reached `submitted` THEN it SHALL make no change (Requirement 4.5).
- IF file storage succeeds but the row insert fails THEN the stored file SHALL be deleted (Requirement 3.3).
- IF an admin retakes a photo repeatedly THEN at most one Draft_Upload per admin, employee, and paper type SHALL exist at a time (Requirement 3.4).
- IF the reconciliation surface is read while an employee is midway through re-confirming their documents THEN it SHALL compare against the profile as currently committed and never a half-written one, because both sides are read inside a single request and no verdict is cached.

## Constraints and Assumptions

### Technical Constraints
- `OpenRouterOcrService::GROUP_RESIDENCE_CARD_SCAN` already exists and is prompted for a single scanned copy, so the admin image is a natural fit; its required fields per `ValidateOcrRequiredFieldsAction` are `name`, `residence_number`, `residence_expiry_date`, `residence_status_raw`.
- `OcrResultCacheService::prepareImage()` is private and typed to `UserPaperItem`. Preparing an image (mime sniffing, converting bmp/heic to jpeg, base64) must be reachable from the new job without coupling it to `user_paper_items`; the design extracts it.
- `ImageHelper::handleImageUpload()` defaults to 1200 px width, which is too small for an A4 scan (Requirement 3.2).
- Permission grouping in Role Management derives from the first segment of the permission name, so `user.paper.upload` lands in the existing User group automatically once registered in both permission enums.
- `user_paper_ocr_results.item_ids` is a JSON array; matching an item to a result uses `whereJsonContains`, as `OcrResultCacheService::invalidateForItem()` already does.
- Every OCR run is a paid LLM call; the budget in Requirement 5 exists for cost, not correctness.
- The employee's confirmed profile is one flat field set. `DocumentStepConfirmRules::residenceCardRules()` validates a single payload and `ApplyDocumentStepConfirmationAction` writes it to `user_infos` and the `users` name columns; nothing records which image a value came from, and the employee may edit any of it before submitting. That is precisely why it is the right side to compare against: it is the record the company acts on, however it was arrived at.
- `user_infos` keeps three residence card slots and rotates them on each confirmation (`ResidenceSlotResolver::prepareSlotForWrite()`), so an employee who has renewed twice still has the older numbers on file. Comparing against the current slot alone would report a scanned older card as an entirely unknown one, which Requirement 7.6 exists to prevent.
- **Known defect, deliberately out of scope.** `ExtractPapersOcrAction::TYPE_GROUPS` maps `foreigner_card_scan` onto `GROUP_RESIDENCE_CARD`, folding the scanned copy into the same run as the card's two sides. This contradicts `UserPaperStepKey::ocrGroupTypes()`, whose docblock states the two are read apart on purpose so a scan not belonging to the card in hand can be spotted. It no longer affects this feature — nothing here reads those rows — but it remains wrong, and no existing test asserts the scan's group, so nothing would catch a regression either. Raise it as its own change.
- The admin user list is already company-wide: `UserController::index()` passes `workplace_ids` through as a user-chosen filter and applies no scoping of its own, unlike the shift, work-history, and check-in surfaces which call `applyWorkplaceFilter`. Requirement 1.5 keeps that existing behaviour for the `user.paper.*` family rather than introducing a different rule for one screen.

### Assumptions
- **The admin uploads exactly one image, the A4 scan copy.** Confirmed by stakeholder. The physical card's two sides are out of scope, so fields printed only on the back (extended expiry, newest address) may be absent from the admin read; that shows up as `unknown`, not as a mismatch.
- **The employee side is the profile record — the `users` name columns plus the `user_infos` residence fields — read as stored and never recomputed.** Confirmed by stakeholder. No `user_paper_ocr_results` row takes part in the comparison; the employee's own scan image is displayed for the reviewer and is never read for a value.
- **Both images are shown in the popup.** Confirmed by stakeholder.
- **The full upload history is kept and the newest submission is the current one.** Confirmed by stakeholder.
- **OCR reuses the employee flow's mechanism** — queued job plus client polling, not a synchronous request. Confirmed by stakeholder.
- **Upload_Permission and View_Permission are company-wide, like the other `user.paper.*` permissions** (Requirement 1.5). This matches how the admin user list already behaves — see the Technical Constraint above — so the reconciliation surface follows the existing rule rather than inventing a narrower one. Changing it would be a change to the whole `user.*` family, not to this feature.
- **The list without filters shows all active employees**, not only employees who legally require a residence card. `Nationality` has no Japanese case, so every employee the system can record is a foreign worker who needs one; a "requires a residence card" rule would exclude nobody.
- The admin app is the only client that uploads; the web admin never posts to the upload endpoints.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria in Requirements 1–11 are met.
- [ ] `admin_paper_uploads` exists with the migration, model, enum, and factory.
- [ ] `user.paper.upload` is created and granted by migration and appears in both permission enums.
- [ ] Upload, poll, submit, discard, list, detail, and filter-options endpoints are implemented as Actions per the repository's Action pattern rule.
- [ ] The OCR job, the shared image preparer, and the stale-draft prune job are implemented and scheduled.
- [ ] The web reconciliation page and comparison popup are implemented, permission-gated, and fully translated into `vi` and `ja`.
- [ ] Backend tests cover the permission matrix, validation, the OCR state machine including events arriving in states they were not designed for, the comparison rules, the list filters with accumulated history, and a mid-transaction failure leaving no orphan file — with all test methods in camelCase.
- [ ] An employee with no residence information on file shows `employee_data_missing` while still comparing `name`, `birthday`, `gender`, and `nationality`, and flips to `mismatched` with `employee_residence_missing` still set when one of those disagrees; an employee whose scanned card matches a superseded slot shows a mismatch flagged as a Previous_Card_Match, not an unknown card.
- [ ] The OCR budget is refused at upload, not only at submit, so a fourth read is never paid for; the manual confirmation of 5.6 is the only way past a spent budget.
- [ ] `residence_number` and the two dates compare equal across a casing or format difference that exists only because the profile side never passed through `OcrFieldNormalizer`.
- [ ] Every Reconciliation_Verdict, `not_comparable` included, is reachable through the `status` filter, and a verdict filter without a narrowing filter is refused with 422.
- [ ] The roster status filter defaults to approved + pending, and a rejected assignment never appears under any value.
- [ ] At least one lifecycle test drives upload → OCR job → confirm → submit → re-upload → submit through the real endpoints and jobs, with no fixture inserting an intermediate status directly.
- [ ] Frontend tests cover the filter behaviour, verdict rendering, and the comparison popup.
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` pass in `./frontend`; `npm run prettier` and `npm run pint` pass in `./backend`.
