# Requirements Document

## Introduction

DGFC-148 collects each employee's salary payout details inside DGF Connect. An employee chooses between bank transfer and cash pickup at a Seven Bank ATM. For bank transfer the employee photographs a Japanese cash card or the first page of a passbook, the system reads it with OCR, the employee confirms the details, and the system runs two AI-assisted checks: the registered name against the account holder name, and the photographed card against the values the employee typed. An admin reviews and approves the record. To reach employees, the existing admin notification screen gains a target selector so an admin-created notification can be sent to the members of one or more workplaces, and a new notification type "bank account request" opens the bank account screen when tapped. No breaking changes: existing broadcast and recruitment notifications keep their current behavior.

### Feature Summary
Collect, OCR-verify, and approve per-employee salary payout details (bank transfer or Seven Bank ATM pickup), and let admins send workplace-targeted notifications that deep-link to the collection screen.

### Business Value
Payroll currently has no structured source for employee bank accounts. Collecting them in-app with OCR pre-fill and automated cross-checks reduces manual data entry errors, catches accounts that belong to someone other than the employee, and gives payroll an approved, auditable record per employee. Workplace-targeted notifications let the company roll the collection out shop by shop without spamming every employee.

### Scope

**Included**
- Admin notification form: target selector (all employees / selected workplaces, multiple allowed) for the normal type and the new bank account request type.
- New admin-selectable notification type `bank_account_required` that opens the bank account screen on the mobile app.
- Delivery of workplace-targeted notifications to the resolved members only, with in-app visibility limited to those members.
- Mobile deep link from a notification to the bank account screen.
- Mobile bank account flow: payout method selection, card/passbook capture, OCR, confirm form, submission, status display, re-submission.
- Backend bank account module: storage, state machine, OCR group, name check, input-vs-image mismatch detection, admin list/detail/approve/reject, notifications to the employee on decision.
- Admin web page to review bank accounts.

**Excluded (Phase 2)**
- Bank / branch master data (金融機関コード, 支店コード) lookup and validation.
- Conversion of Japan Post Bank (ゆうちょ銀行) symbol/number into transfer-format branch/account numbers; the raw values are stored only.
- Zengin (全銀) transfer file export.
- Blocking salary requests until a bank account is approved.
- Accountant-specific roles; permissions are created and assigned to admin only.

## Glossary

| Term | Definition |
|------|------------|
| Employee | An end user of the mobile app who works at one or more workplaces |
| Admin | A staff member using the web admin with the relevant permissions |
| Workplace | A shop/factory (xưởng) an Employee is assigned to through `workplace_users` |
| Notification_Form | The admin web form that creates or edits a notification |
| Notification_Dispatcher | The scheduled backend job that resolves recipients and sends FCM pushes for pending notifications |
| Notification_Target | The audience of a notification: all employees, or the members of selected Workplaces |
| Notification_Type | The `type` of a notification; admins can create `normal`, `recruitment`, and, with this feature, `bank_account_required`. Other types are system-generated |
| Bank_Account_Request | A notification of type `bank_account_required`, created by an Admin to ask Employees to submit payout details |
| Mobile_App | The Flutter employee app |
| Bank_Account_Screen | The Mobile_App screen where an Employee views and submits payout details |
| Bank_Account | The payout record of one Employee, either bank transfer details or a Seven Bank ATM pickup choice |
| Payout_Method | `bank_transfer` or `seven_bank_atm` |
| Seven_Bank_ATM | Cash pickup at a Seven Bank ATM; requires no account details or image |
| Bank_Image | A photo of the front of a Japanese cash card or of the first page of a passbook (通帳) |
| OCR_Service | The existing OpenRouter-based document reading service (`OpenRouterOcrService` + `OcrResultCacheService`) |
| OCR_Result | One OCR run: status, extracted fields, warnings, model |
| OCR_Budget | The maximum number of OCR runs an Employee may trigger per review cycle (3, same as document steps) |
| Account_Type | The Japanese deposit type: ordinary (普通), current (当座), savings (貯蓄), time deposit (定期) |
| Name_Checker | The backend component that compares the Employee's registered name with the account holder name |
| Input_Comparator | The backend component that compares the values the Employee typed with the OCR_Result fields |
| Bank_Account_Review | The admin web page listing Bank_Accounts with detail, approve, and reject actions |
| Confirmed_Fields | The account values the Employee submitted after reviewing the OCR pre-fill |

## Requirements

### Requirement 1: Workplace Target on Admin-Created Notifications

**User Story:** As an Admin, I want to send a normal notification or a Bank_Account_Request to the members of one or more selected Workplaces, so that I can address specific shops without notifying everyone.

#### Acceptance Criteria

1. WHEN an Admin selects the Notification_Type `normal` or `bank_account_required` THEN Notification_Form SHALL display a Notification_Target selector with the options "All employees" and "Selected workplaces".
2. WHEN "Selected workplaces" is chosen THEN Notification_Form SHALL display a multi-select of active Workplaces and SHALL require at least one selection.
3. IF the Notification_Type is `recruitment` THEN Notification_Form SHALL hide the Notification_Target selector and SHALL keep the existing recruitment criteria fields unchanged.
4. WHEN a workplace-targeted notification is saved THEN the backend SHALL persist the target type and the full list of selected Workplace ids.
5. WHEN an Admin edits a pending workplace-targeted notification THEN Notification_Form SHALL show the previously selected Workplaces and SHALL allow changing them until the notification is sent.
6. WHERE the admin notification list is displayed THEN it SHALL show the Notification_Target of each notification (all, or the count and names of selected Workplaces).
7. IF a selected Workplace id does not exist THEN the backend SHALL reject the request with a validation error on the workplace field.
8. IF the Notification_Target is "all" THEN the backend SHALL store no Workplace ids and SHALL treat the notification as a broadcast exactly as today.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None

### Requirement 2: Delivery to Workplace Members

**User Story:** As an Employee, I want to receive only the notifications addressed to my Workplace, so that my notification list is relevant to me.

#### Acceptance Criteria

1. WHEN Notification_Dispatcher processes a pending workplace-targeted notification THEN it SHALL resolve the recipient set as the distinct Employees linked to any selected Workplace, excluding disabled accounts.
2. WHEN the recipient set is resolved THEN Notification_Dispatcher SHALL persist the recipients and the resolved count on the notification before sending pushes.
3. WHEN pushes are sent for a workplace-targeted notification THEN Notification_Dispatcher SHALL send to the device tokens of the resolved recipients only, never to a broadcast topic.
4. WHEN an Employee loads the notification list or a single notification THEN the backend SHALL include a workplace-targeted notification only if the Employee is a persisted recipient.
5. IF recipient resolution fails THEN Notification_Dispatcher SHALL mark the notification as failed and SHALL not send any push.
6. IF the resolved recipient set is empty THEN Notification_Dispatcher SHALL mark the notification as sent with a resolved count of zero and SHALL not fall back to a broadcast.
7. WHILE a notification targets all employees THEN Notification_Dispatcher SHALL keep the existing topic broadcast behavior unchanged.
8. WHILE a notification is of the recruitment type THEN Notification_Dispatcher SHALL keep the existing recruitment resolution behavior unchanged.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 3: Bank Account Request Notification Type

**User Story:** As an Admin, I want a dedicated notification type that opens the Bank_Account_Screen when the Employee taps it, so that the Employee lands directly on the form.

#### Acceptance Criteria

1. WHEN an Admin opens Notification_Form THEN the Notification_Type selector SHALL offer `normal`, `recruitment`, and `bank_account_required` (labelled "Bank account request").
2. WHEN `bank_account_required` is selected THEN Notification_Form SHALL show title, content, translations, icon, Notification_Target, and scheduling fields, and SHALL hide the external link field.
3. WHEN a Bank_Account_Request is saved THEN the backend SHALL persist the type, SHALL include it in the FCM data payload as `type`, and SHALL return it in the notification API responses like any other type.
4. WHEN an Employee taps a push notification or a list item of type `bank_account_required`, `bank_account_approved`, or `bank_account_rejected` THEN Mobile_App SHALL mark it read and SHALL open the Bank_Account_Screen.
5. IF the Employee's Bank_Account is already approved when a Bank_Account_Request is tapped THEN Mobile_App SHALL still open the Bank_Account_Screen, showing the approved state.
6. WHILE the Notification_Type is `normal` THEN Mobile_App SHALL keep the existing URL handling unchanged.
7. IF Mobile_App receives an unknown Notification_Type THEN it SHALL keep mapping it to `normal` as today.
8. WHERE the admin notification list is filtered by type THEN `bank_account_required` SHALL be available as a filter value.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1, Requirement 12

### Requirement 4: Payout Method Selection

**User Story:** As an Employee, I want to choose how I receive my salary, so that the company pays me the way I prefer.

#### Acceptance Criteria

1. WHEN an Employee opens the Bank_Account_Screen with no submitted Bank_Account THEN Mobile_App SHALL present the Payout_Method options "Bank transfer (銀行振込)" and "Seven Bank ATM pickup (セブン銀行ATM受取)" with a short description of each.
2. WHEN an Employee selects Seven_Bank_ATM THEN Mobile_App SHALL show the Employee's registered name and phone number from the profile and SHALL ask for confirmation.
3. WHEN an Employee confirms Seven_Bank_ATM THEN the backend SHALL store a Bank_Account with Payout_Method `seven_bank_atm` in approved state immediately, without image, OCR, or admin review.
4. IF the Employee's profile has no phone number THEN Mobile_App SHALL block the Seven_Bank_ATM confirmation and SHALL link to the profile edit screen.
5. WHEN an Employee selects bank transfer THEN Mobile_App SHALL continue to the Bank_Image capture step (Requirement 5).

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 12

### Requirement 5: Bank Image Capture and OCR Trigger

**User Story:** As an Employee, I want to photograph my cash card or passbook, so that the account details are read automatically instead of typed.

#### Acceptance Criteria

1. WHEN the capture step opens THEN Mobile_App SHALL show guidance stating that either the front of a cash card or the first page of a passbook is accepted and that the account holder name must be visible when possible.
2. WHEN an Employee captures or picks images THEN Mobile_App SHALL accept 1 to 2 images of type jpg, jpeg, png, webp, or heic, each at most 8 MB, using the existing document camera with frame cropping.
3. WHEN images are uploaded THEN the backend SHALL store them under the Employee's private storage folder, SHALL create an OCR_Result in processing state, SHALL set the Bank_Account status to `ocr_processing`, and SHALL dispatch the OCR job after commit.
4. IF a Bank_Account is in `ocr_processing` or `wait_for_approval` THEN the backend SHALL reject a new upload with a locked error.
5. IF the OCR_Budget for the current review cycle is exhausted THEN the backend SHALL reject a new upload with a budget error and SHALL report `can_reupload = false` in the Bank_Account response.
6. WHEN a new upload is accepted THEN the backend SHALL invalidate earlier OCR_Results of the current cycle and SHALL move earlier images to an old folder.
7. WHILE the OCR job is running THEN Mobile_App SHALL poll the Bank_Account every 3 seconds for up to 60 seconds and SHALL offer "keep waiting" or "enter manually" on timeout.
8. WHEN the OCR job completes THEN the backend SHALL set the status to `ocr_ready` on success or `ocr_failed` on failure, unless the Bank_Account has already left `ocr_processing`.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4

### Requirement 6: OCR Extraction of Bank Account Fields

**User Story:** As the system, I want to extract structured bank account fields from a Japanese cash card or passbook photo, so that the confirm form can be pre-filled and later compared.

#### Acceptance Criteria

1. WHEN OCR_Service reads Bank_Images THEN it SHALL return a strict JSON object with the fields `bank_name`, `bank_code`, `branch_name`, `branch_code`, `account_type`, `account_number`, `holder_name_kana`, `holder_name_latin`, `is_yucho`, `yucho_symbol`, `yucho_number`, each nullable.
2. WHEN a field is not clearly readable THEN OCR_Service SHALL return null for it rather than a guessed value.
3. WHEN `account_type` is readable THEN OCR_Service SHALL map it to one of `ordinary`, `current`, `savings`, `time_deposit`.
4. WHEN `account_number` is readable THEN the backend SHALL normalize it to digits only and SHALL left-pad it with zeros to 7 digits when shorter.
5. WHEN `branch_code` or `bank_code` is readable THEN the backend SHALL normalize them to 3 and 4 digits respectively, left-padded with zeros.
6. WHEN `holder_name_kana` is readable THEN the backend SHALL normalize it to full-width katakana with single spaces between name parts.
7. WHEN the image shows a Japan Post Bank (ゆうちょ銀行) card or passbook THEN OCR_Service SHALL set `is_yucho = true` and SHALL fill `yucho_symbol` (記号, 5 digits) and `yucho_number` (番号, up to 8 digits) when readable.
8. WHEN normalization cannot parse a value THEN the backend SHALL drop the value and SHALL record a warning code for that field on the OCR_Result.
9. WHEN OCR_Service is called THEN it SHALL use the existing primary model, fallback model, cache, and timeout configuration of the document OCR pipeline.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 5

### Requirement 7: Confirm and Submit Bank Transfer Details

**User Story:** As an Employee, I want to review the values read from my card and correct them before submitting, so that payroll receives accurate details.

#### Acceptance Criteria

1. WHEN the Bank_Account is `ocr_ready` THEN Mobile_App SHALL open the confirm form pre-filled from the OCR_Result and SHALL mark each pre-filled field as read automatically.
2. WHEN the Bank_Account is `ocr_failed` and the OCR_Budget is exhausted THEN Mobile_App SHALL open the confirm form empty for manual entry.
3. WHEN the Bank_Account is `ocr_failed` and the OCR_Budget is not exhausted THEN Mobile_App SHALL offer "retake photos" and "enter manually".
4. IF the OCR_Result `account_type` is `current`, `savings`, or `time_deposit` THEN Mobile_App SHALL disable the form, SHALL display that only ordinary deposit accounts (普通預金) are accepted, and SHALL offer "retake photos" only.
5. WHEN the form is displayed THEN Mobile_App SHALL show Account_Type as a read-only value fixed to ordinary (普通預金); the Employee SHALL NOT be able to submit any other Account_Type.
6. WHEN the Employee submits THEN Mobile_App SHALL require `bank_name`, `account_number` (exactly 7 digits after zero-padding), `account_holder_kana` (full-width katakana and spaces only), and at least one of `branch_name` or `branch_code` (3 digits).
7. WHEN the Employee submits THEN the backend SHALL validate the same rules, SHALL require the OCR_Result id echoed back to match the current OCR_Result when one exists, and SHALL reject with a changed error when it does not match.
8. WHEN the OCR_Result `is_yucho` is true THEN the form SHALL additionally show `yucho_symbol` and `yucho_number` as editable fields and SHALL relax the `branch_name`/`branch_code` requirement.
9. WHEN the submission is valid THEN the backend SHALL store the Confirmed_Fields, SHALL run Name_Checker (Requirement 8) and Input_Comparator (Requirement 9), and SHALL set the status to `wait_for_approval` in the same transaction.
10. IF the Bank_Account is not in `ocr_ready` or `ocr_failed` THEN the backend SHALL reject the submission with a not-confirmable error.
11. WHEN the backend rejects with validation errors THEN Mobile_App SHALL display each error under its field.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 6

### Requirement 8: Registered Name vs Account Holder Name Check

**User Story:** As an Admin, I want the system to tell me whether the account holder is the same person as the registered Employee, so that salary is not sent to someone else's account.

#### Acceptance Criteria

1. WHEN a bank transfer submission is stored THEN Name_Checker SHALL compare the submitted `account_holder_kana` with the Employee's registered kana name (`last_name_kana`, `first_name_kana`) after normalization to full-width katakana, removal of spaces and middle dots, and unification of long vowel marks.
2. WHEN the OCR_Result contains `holder_name_latin` THEN Name_Checker SHALL also compare it, case-insensitively and ignoring spaces and hyphens, with the Employee's registered Latin name (`last_name`, `first_name`).
3. WHEN the normalized name parts match as a set regardless of order THEN Name_Checker SHALL record the verdict `match`.
4. WHEN one registered name part is missing from the account holder name but every present part matches THEN Name_Checker SHALL record the verdict `partial`.
5. WHEN neither `match` nor `partial` applies THEN Name_Checker SHALL request a second opinion from the AI model with the registered names, nationality, and account holder name, and SHALL store the model's verdict (`same_person`, `different_person`, `uncertain`), confidence, and one-sentence reason alongside the deterministic verdict `mismatch`.
6. IF the AI second opinion fails or times out THEN Name_Checker SHALL store the deterministic verdict with the AI part marked unavailable and SHALL NOT fail the submission.
7. WHEN the verdict is stored THEN the Bank_Account API SHALL expose it to the Employee as a non-blocking warning and to the Admin with the full detail.
8. IF the Employee has no registered kana name THEN Name_Checker SHALL rely on the Latin comparison only and SHALL record a warning that kana was unavailable.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 7

### Requirement 9: Image vs Typed Input Mismatch Detection

**User Story:** As an Admin, I want to see which submitted values differ from what the photo shows, so that I can catch typos or substituted account numbers.

#### Acceptance Criteria

1. WHEN a bank transfer submission is stored and a completed OCR_Result exists THEN Input_Comparator SHALL compare `bank_name`, `branch_name`, `branch_code`, `account_number`, and `account_holder_kana` between Confirmed_Fields and the OCR_Result.
2. WHEN comparing numeric fields THEN Input_Comparator SHALL compare digits only after zero-padding.
3. WHEN comparing text fields THEN Input_Comparator SHALL compare after full-width normalization, whitespace removal, and removal of the suffixes 銀行, 支店, 信用金庫 for bank and branch names.
4. IF an OCR field is null THEN Input_Comparator SHALL skip that field rather than report a mismatch.
5. WHEN one or more fields differ THEN Input_Comparator SHALL store the list of `{field, ocr_value, input_value}` on the Bank_Account.
6. IF no completed OCR_Result exists (manual entry after budget exhaustion) THEN Input_Comparator SHALL store an empty list and SHALL flag the record as entered manually.
7. WHEN mismatches exist THEN Bank_Account_Review SHALL display a warning badge on the list row and SHALL highlight the differing fields in the detail view.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 7

### Requirement 10: Admin Review and Decision

**User Story:** As an Admin, I want to review submitted payout details next to the card photo and approve or reject them, so that payroll only uses verified accounts.

#### Acceptance Criteria

1. WHEN an Admin with the bank account list permission opens Bank_Account_Review THEN it SHALL list Employees with their Payout_Method, status, submission time, Workplaces, name check verdict, and mismatch badge, filterable by Workplace, status, Payout_Method, and warning presence, and searchable by name or staff number.
2. WHEN an Admin opens a Bank_Account detail THEN Bank_Account_Review SHALL show the Bank_Images, the OCR_Result fields, the Confirmed_Fields side by side with differing fields highlighted, the Name_Checker result with the AI reason, and the OCR warnings.
3. WHERE the account number is displayed in the list THEN Bank_Account_Review SHALL mask all but the last 3 digits; the detail view SHALL show the full number to an Admin with the approve permission.
4. WHEN an Admin approves a Bank_Account in `wait_for_approval` THEN the backend SHALL set it to `approved`, record the approver and time, supersede any previously approved Bank_Account of the Employee, and create a per-Employee notification of type `bank_account_approved` with translations and push.
5. WHEN an Admin rejects a Bank_Account in `wait_for_approval` THEN the backend SHALL require a rejection note, set it to `rejected`, and create a per-Employee notification of type `bank_account_rejected` containing the note.
6. IF the Bank_Account is not in `wait_for_approval` THEN the backend SHALL reject approve and reject actions with a state error.
7. WHEN two Admins act on the same Bank_Account THEN the backend SHALL apply only the first decision, using a conditional update on the expected status.
8. IF an Admin lacks the approve permission THEN the backend SHALL return 403 for approve and reject.
9. WHEN a Seven_Bank_ATM Bank_Account is listed THEN Bank_Account_Review SHALL show it as approved with no image, and approve/reject actions SHALL be hidden.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 7, 8, 9

### Requirement 11: Re-submission and History

**User Story:** As an Employee, I want to change my payout details after they were approved or rejected, so that my record stays correct when I change banks.

#### Acceptance Criteria

1. WHEN an Employee starts a new submission while an approved Bank_Account exists THEN the backend SHALL create a new Bank_Account record in draft state and SHALL keep the approved record effective until the new one is approved.
2. WHEN the new Bank_Account is approved THEN the backend SHALL mark the previous approved record as superseded with a timestamp.
3. WHEN an Employee starts a new submission after a rejection THEN the backend SHALL reuse the rejected record, reset its OCR_Budget, clear its OCR_Results and Confirmed_Fields, and move its images to an old folder.
4. WHEN Bank_Account_Review shows an Employee THEN it SHALL show the current effective record and SHALL list previous records in a history section.
5. IF an Employee changes from bank transfer to Seven_Bank_ATM THEN the backend SHALL create the Seven_Bank_ATM record as approved and SHALL supersede the previous approved record immediately.
6. WHEN an Employee switches Payout_Method during a draft THEN Mobile_App SHALL discard the draft and SHALL restart from the method selection.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 10

### Requirement 12: Bank Account Status on the Profile

**User Story:** As an Employee, I want to see the status of my payout details on my profile, so that I know whether anything is still required from me.

#### Acceptance Criteria

1. WHEN an Employee opens the profile tab THEN Mobile_App SHALL show a "Salary payout details" card with a status chip: not submitted, processing, needs confirmation, waiting for approval, approved, rejected.
2. WHEN the card is tapped THEN Mobile_App SHALL open the Bank_Account_Screen in the step matching the current status.
3. WHEN the status is rejected THEN the card and the Bank_Account_Screen SHALL display the Admin's rejection note and a "resubmit" action.
4. WHEN the status is approved THEN the Bank_Account_Screen SHALL show the Payout_Method and masked account details with a "change" action.
5. WHEN a Bank_Account decision notification is received THEN Mobile_App SHALL refresh the Bank_Account state and SHALL open the Bank_Account_Screen on tap.
6. WHERE the Mobile_App is offline THEN the card SHALL show the last loaded status and SHALL retry loading when the tab is reopened.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirements 4, 10

## Non-Functional Requirements

### Performance Requirements
- WHEN a workplace-targeted notification resolves recipients THEN Notification_Dispatcher SHALL complete resolution in a single query set and send pushes in chunks of at most 500 tokens, matching the recruitment path.
- WHEN Bank_Account_Review lists records THEN the API SHALL paginate and SHALL respond within 1 second for 10,000 Employees using indexed status and workplace filters.
- WHEN an OCR job runs THEN it SHALL finish within the existing 130-second job timeout including one fallback retry.

### Security Requirements
- WHEN a Bank_Account is stored THEN the backend SHALL encrypt `account_number` at rest and SHALL never log it in plain text.
- WHEN a Bank_Account is returned to an Employee THEN the API SHALL mask the account number except the last 3 digits after approval, and SHALL return the full value only while the Employee is editing their own draft.
- WHEN Bank_Images are stored THEN they SHALL be on the private disk under the Employee's folder and SHALL be served only through the existing signed/authorized image endpoints.
- WHEN an Admin accesses Bank_Account_Review THEN the backend SHALL enforce the `bank_account.list` permission for reading and `bank_account.approve` for decisions and unmasked numbers.
- WHEN a notification is workplace-targeted THEN non-members SHALL receive 404 when requesting it by id, matching the recruitment visibility rules.

### Usability Requirements
- WHEN any user-facing text is added THEN it SHALL be provided in en, ja, vi, ne, si for the Mobile_App and in en, ja, vi for the admin web, through the existing translation mechanisms.
- WHEN the confirm form shows a field pre-filled from OCR THEN it SHALL be visually tagged as automatic, consistent with the document steps form.
- WHEN a name check warning is shown to the Employee THEN it SHALL explain that the admin will verify it and SHALL not block submission.

### Reliability Requirements
- WHEN the OCR job fails after retries THEN the Bank_Account SHALL be `ocr_failed` with `can_reupload` reflecting the remaining OCR_Budget, and the Employee SHALL always have a path to manual entry.
- WHEN the submission transaction fails after images were moved THEN the backend SHALL roll back status, Confirmed_Fields, and image folder changes together.
- WHEN an OCR job completes after the Employee already submitted manually THEN the job SHALL not overwrite the `wait_for_approval` status.

## Constraints and Assumptions

### Technical Constraints
- OCR runs through OpenRouter with the configured Gemini models; no new AI provider is introduced.
- Mobile deep links remain type-based switches in `fcm_helper.dart` and `notification_item.dart`; no URL-scheme routing or separate screen field is introduced.
- The existing `notifications.url` column keeps its meaning (external link) and applies to the `normal` type only.
- Notification visibility for targeted notifications reuses `notification_recipients` and `recipients_resolved_count`.
- Backend follows the Action pattern, PHPUnit camelCase, and the state-machine testing rules in CLAUDE.md.

### Assumptions
- Every Employee has completed identity documents, so `first_name`/`last_name` exist and `first_name_kana`/`last_name_kana` usually exist from the residence card step.
- A Japanese cash card may not print the holder name; the passbook first page does. The Employee may type the holder name when the card lacks it.
- Seven Bank ATM pickup needs only the Employee's registered name and phone number, which payroll takes from the profile.
- Branch code lookup is done manually by payroll when the Employee provides only a branch name (Phase 2 adds master data).
- One effective Bank_Account per Employee at any time.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria of Requirements 1–12 are met on backend, admin web, and mobile.
- [ ] Non-functional requirements (encryption, masking, permissions, visibility, translations) are satisfied.
- [ ] Backend feature tests cover the notification targeting/visibility, the Bank_Account state machine including out-of-order OCR completion, Name_Checker and Input_Comparator normalization cases, and admin decisions with permission checks.
- [ ] Frontend and mobile unit tests cover the new form fields, the confirm form validation, the account type block, and the deep-link switch.
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` (frontend), `npm run prettier`, `npm run pint` (backend), and `./format.sh fix` (mobile) pass.
