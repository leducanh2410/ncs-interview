# Requirements Document

> **DRAFT** — bản nháp sơ bộ để trao đổi tiếp, chưa chốt 100%. Xem mục "Open Questions" ở cuối file cho những phần còn lại. Đã chốt: field `type` trên Exam (mặc định/`null` = `assessment`); cách track hoàn thành form ngoài tái sử dụng `ExamResultStatus` hiện có (thêm case `PendingExternal`) thay vì thêm field/enum song song; `external_complete_pattern` là **giá trị config server duy nhất, không phải field trên Exam** (admin không nhập); `min_score`/`retry_limit`/`time_limit` bị **ẩn khỏi UI và luôn được backend gán giá trị mặc định an toàn** cho `external_form_prep` (không để `null`, vì `min_score`/`time_limit` là cột NOT NULL); `is_required` bị **ép luôn là `true`** cho `external_form_prep`.

## Introduction

This feature adds a new Exam mode, External_Form_Prep_Exam, to the existing multilingual Exam system. Unlike the current Assessment_Exam mode (score-gated, answers revealed only after `end_at`), an External_Form_Prep_Exam exists purely as study material for a mandatory external Google Form: after the employee submits it, the correct answers are revealed immediately regardless of score, and the employee is then expected to open a linked external form (in the mobile app's WebView) and complete it for real. The system tracks completion of that external step by reusing the existing `ExamResult.status` lifecycle — extended with one new value, `PendingExternal` — and treats external completion (`status = Completed`), not the internal score, as the condition that satisfies the "required" obligation. No breaking changes to existing Assessment_Exam behavior are introduced; the new mode is additive and gated by a new `type` field on Exam.

### Feature Summary

Adds an Exam mode that reveals answers immediately after submission (ignoring score) and tracks completion of a linked external Google Form as the real completion condition, since the exam itself is only reference material.

### Business Value

- Lets the company require employees to complete an external (non-integrated) Google Form exam while still providing a verifiable, trackable internal record of preparation.
- Removes the pointless friction of score-gating/retry-limits on a "reference material" exam whose answers are shown immediately anyway.
- Gives admins visibility into who has reviewed the material but not yet completed the real external form, so they can follow up.
- Avoids mobile app releases when the external form's completion-detection URL pattern changes, by keeping that pattern server-configured.

### Scope

**Included:**
- New `Exam.type` field distinguishing `assessment` (existing behavior; `null` on legacy rows is treated as `assessment`) from `external_form_prep` (new).
- For `external_form_prep` exams: mandatory `external_url`; bypass of score/retry-limit gating; immediate answer reveal on final submit; forced `is_required = true`; `min_score`/`retry_limit`/`time_limit` hidden from the admin UI and always persisted as safe server-assigned defaults (never `null`).
- The external-completion detection substring (`external_complete_pattern`) is a single server config value (`config('exam.default_external_complete_pattern')`) shared by every `external_form_prep` exam — not a per-exam field, not admin-editable.
- `ExamResultStatus` enum gains one new case, `PendingExternal` (`pending_external`), used only for `external_form_prep` results between internal submission and external-form completion. New `ExamResult.external_completed_at` column (audit/display timestamp only — completion gating uses `status`, not this timestamp).
- New API endpoint for the mobile client to report external-form completion.
- Updated `pending_exams` / required-exam gating logic — and the FCM activation-notification recipient logic in `ExamNotificationService` — so both key off external completion (`status = Completed`), not internal submission or score, for this exam type.
- Admin web UI: exam type selector, external URL field, exam list badge, exam-result list/detail columns for status (including `PendingExternal`), manual "mark completed" override for admins.

**Excluded:**
- The mobile WebView implementation itself (URL-navigation interception, calling the completion API) — mobile team's responsibility, built against the API contract defined here.
- Any server-side verification that the external Google Form was genuinely filled by the employee (inherently impossible without a Google-side webhook/API integration).
- Changing existing `assessment`-type exam behavior (score gating, retry limits, `end_at`-gated answer reveal remain untouched).
- Building a generic multi-step/sequenced onboarding engine; this only adds one more parallel "pending" bucket alongside the existing `pending_exams`/`pending_documents`/`pending_votes`.

---

## Glossary

| Term | Definition |
|------|------------|
| Exam_Type | New enum field on Exam: `assessment` (existing behavior) or `external_form_prep` (new) |
| Assessment_Exam | An Exam with `type = assessment`; existing scored, retry-limited, `end_at`-gated-answer-reveal behavior, unchanged |
| External_Form_Prep_Exam | An Exam with `type = external_form_prep`; score/retry ignored, answers revealed immediately on submit, linked to an External_Form |
| External_Form | The Google Form (or similar) hosted outside the system, opened in the mobile app's WebView, that the employee must complete after reviewing the External_Form_Prep_Exam |
| External_Url | The URL of the External_Form's initial (not-yet-submitted) state, stored on Exam |
| External_Complete_Pattern | A substring that, when found in the WebView's current URL, indicates the External_Form was submitted (e.g. `"formResponse"`); a single server config value (`config('exam.default_external_complete_pattern')`) shared by every External_Form_Prep_Exam — **not** a column on Exam, **not** admin-editable per exam |
| ExamResultStatus (extended) | Existing enum on ExamResult (`InProgress`/`Completed`/`Failed`), gaining one new case: `PendingExternal` — submitted internally, External_Form not yet confirmed complete. No separate field/enum is introduced. `PendingExternal` is only ever used for External_Form_Prep_Exam results; Assessment_Exam results never take this value. Chosen over a parallel `external_step_status` field specifically to avoid colliding with `ExamResultCalculation` (the existing every-minute job that force-finalizes any `InProgress` row past `expires_at`) — see design.md Key Design Decisions. |
| Complete_External_Endpoint | The new API endpoint the mobile client calls after detecting External_Complete_Pattern in the WebView URL |
| Pending_Exams | The existing list of outstanding required exams returned by `AuthController::getAuthData()` and used to drive FCM reminders |

---

## Requirements

### Requirement 1: Exam Type Configuration

**User Story:** As a Global Admin, I want to mark an exam as "external form prep" and attach a Google Form link to it, so that it behaves as reference material instead of a scored assessment.

#### Acceptance Criteria

1. WHEN an admin creates or edits an Exam THEN Exam_Manager SHALL require selecting an Exam_Type of either `assessment` or `external_form_prep`.
2. IF Exam_Type is `external_form_prep` THEN Exam_Manager SHALL require a non-empty `external_url`.
3. IF Exam_Type is `external_form_prep` THEN Exam_Manager SHALL NOT expose or accept an `external_complete_pattern` value from the admin at all — the completion-detection substring is always resolved from a server config default (`config('exam.default_external_complete_pattern')`) at API-response time, identical across every `external_form_prep` exam.
4. IF Exam_Type is `assessment` THEN Exam_Manager SHALL behave exactly as today (no change to existing validation, gating, or reveal logic).
5. WHERE an Exam's Exam_Type is `external_form_prep` THEN Exam_Manager SHALL NOT expose `min_score`/`retry_limit`/`time_limit` as editable inputs; the system SHALL instead persist safe server-assigned defaults for these columns (`min_score = 0`, `retry_limit = 0`, `time_limit = <config default>`) regardless of what the client payload contains, since `min_score`/`time_limit` are non-nullable database columns and none of the three are used to gate this exam type (see Requirement 2).
6. WHEN an Exam is saved with Exam_Type `external_form_prep` THEN Exam_Manager SHALL force `is_required = true` server-side, regardless of the submitted value — this exam type exists solely to enforce a mandatory external step, so it cannot be created as optional.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Existing Exam model/migration; `Admin\ExamService::create()`/`update()` (server-side default assignment)

---

### Requirement 2: Score and Retry Bypass for External_Form_Prep_Exam

**User Story:** As an employee, I want to be able to submit an External_Form_Prep_Exam without being blocked by a minimum score or retry limit, so that reviewing the material isn't gated by an arbitrary pass/fail check that's meaningless once the answers are shown.

#### Acceptance Criteria

1. WHEN an employee submits final answers for an External_Form_Prep_Exam THEN Submit_Exam_Answer_Action SHALL compute and store the score for informational purposes only, and SHALL NOT use `min_score` to determine pass/fail gating of the flow.
2. WHEN an employee starts or resumes an External_Form_Prep_Exam THEN Start_Or_Resume_Exam_Action SHALL NOT enforce `retry_limit`.
3. WHEN an employee submits an External_Form_Prep_Exam for the first time THEN the system SHALL set `ExamResult.status` to `PendingExternal` unconditionally (never `Failed`, regardless of score), while still persisting `completed_at`/`score`/`correct_answers` for audit purposes.
4. IF Exam_Type is `assessment` THEN the system SHALL retain today's `min_score`/`retry_limit` enforcement unchanged, including the existing `InProgress` → `Completed`/`Failed` transition.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1; existing `StartOrResumeExamAction`, `SubmitExamAnswerAction`

---

### Requirement 3: Immediate Answer Reveal

**User Story:** As an employee, I want to see the correct answers immediately after submitting an External_Form_Prep_Exam, so that I can use them as reference material while completing the real external form.

#### Acceptance Criteria

1. WHEN an employee performs the final submit of an External_Form_Prep_Exam THEN Get_Exam_Result_Action SHALL include the correct option for every question in the response once `ExamResult.status` is `PendingExternal` or `Completed`, regardless of `exam.end_at`.
2. WHEN an employee requests the exam result for an External_Form_Prep_Exam at any later time THEN Get_Exam_Result_Action SHALL continue to include correct answers (no re-hiding after the fact).
3. IF Exam_Type is `assessment` THEN Get_Exam_Result_Action SHALL retain the existing gate (answers hidden until `end_at` has passed and the result is `Completed`/`Failed`).

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1; existing `GetExamResultAction`

---

### Requirement 4: External Form Completion Tracking

**User Story:** As the system, I want to record when an employee has completed the linked external form, so that their obligation can be tracked and reported on independently of the internal exam submission.

#### Acceptance Criteria

1. WHEN Get_Exam_Result_Action (or the submit response) returns data for an External_Form_Prep_Exam with `status = PendingExternal` THEN the response SHALL include `external_url` and the resolved `external_complete_pattern` for the mobile client to use.
2. WHEN the mobile client calls Complete_External_Endpoint for a given exam result THEN the system SHALL set `status = Completed` and `external_completed_at = now()`, IF the result belongs to the authenticated user and its current `status` is `PendingExternal`.
3. IF Complete_External_Endpoint is called for a result that is not in `PendingExternal` state (e.g., already `Completed`, or the exam is `assessment` type and so never reaches `PendingExternal`) THEN the system SHALL return an idempotent success without altering state, and SHALL NOT error.
4. IF Complete_External_Endpoint is called for an ExamResult that does not belong to the authenticated user THEN the system SHALL respond with 403 Forbidden.
5. WHERE an Exam is `assessment` type THEN its ExamResult rows SHALL never take the value `PendingExternal` — they remain on the existing `InProgress`/`Completed`/`Failed` lifecycle only.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 1–3

---

### Requirement 5: Required-Exam Gating Based on External Completion

**User Story:** As the system, I want an External_Form_Prep_Exam to remain in the employee's outstanding obligations until the external form is actually completed, so that reminders and login-time checks reflect the true completion state.

#### Acceptance Criteria

1. WHEN evaluating Pending_Exams for a required External_Form_Prep_Exam THEN the system SHALL consider the exam outstanding until `ExamResult.status = Completed`, regardless of whether the internal exam has been submitted.
2. IF an employee has submitted the internal exam but `status` is still `PendingExternal` THEN the exam SHALL continue to appear in Pending_Exams and remain eligible for reminder notifications.
3. WHEN `status` transitions from `PendingExternal` to `Completed` THEN the exam SHALL no longer appear in Pending_Exams for that employee.
4. IF Exam_Type is `assessment` THEN Pending_Exams evaluation SHALL be unchanged (keyed off `ExamResult.status`/score vs. `min_score`, as today).
5. WHEN `ExamNotificationService::getRecipientsForExam` determines who to send a required-exam activation notification to for an `external_form_prep` exam THEN it SHALL exclude only users whose `ExamResult.status = Completed`, and SHALL NOT use `score >= min_score` for this exam type — the latter would incorrectly exclude a user the moment they merely start the exam, since `min_score` defaults to `0` and a freshly-created `ExamResult` also starts at `score = 0`.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4; existing `GetPendingRequiredExamsAction`/`GetPendingRequiredExamsForAuthAction`, `AuthController::getAuthData()`, `ExamNotificationService::getRecipientsForExam`

---

### Requirement 6: Admin Exam Configuration UI

**User Story:** As an admin, I want to configure the exam type and external form link through the same exam management screen, so that I don't need a separate tool to set this up.

#### Acceptance Criteria

1. WHEN an admin opens the exam create/edit form THEN Exam_Manager_UI SHALL display an Exam_Type selector ("Đánh giá kiến thức" / "Ôn tập cho form ngoài").
2. WHEN the admin selects `external_form_prep` THEN Exam_Manager_UI SHALL show the required field "Link Google Form" (`external_url`) only; SHALL NOT show any `external_complete_pattern` input (server-config only, never admin-editable); SHALL hide entirely (not merely disable) the `min_score`/`retry_limit`/`time_limit` inputs; and SHALL default-check and disable the `is_required` toggle, since External_Form_Prep_Exam is always mandatory.
3. WHERE the exam list is displayed THEN Exam_Manager_UI SHALL show a badge/column indicating each exam's Exam_Type.
4. WHERE `min_score`/`retry_limit`/`time_limit` are displayed outside the create/edit form (exam list columns, exam detail view, exam-result detail view) THEN Exam_Manager_UI SHALL also hide/omit them for `external_form_prep` exams, consistent with the create/edit form.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 1

---

### Requirement 7: Admin Exam Result Visibility and Manual Override

**User Story:** As an admin, I want to see which employees have reviewed an External_Form_Prep_Exam but not yet completed the external form, and be able to mark it complete manually, so that I can follow up on stragglers and recover from client-side detection failures.

#### Acceptance Criteria

1. WHERE exam results are listed or viewed in detail for an External_Form_Prep_Exam THEN Exam_Result_Manager_UI SHALL display `status` (including the `PendingExternal` value, labeled distinctly from `InProgress`) and `external_completed_at` (when set).
2. WHEN an admin filters the exam-result list THEN Exam_Result_Manager_UI SHALL support filtering by `status`, including `PendingExternal`.
3. WHEN an admin uses the manual override action on an exam-result detail page THEN the system SHALL set `status = Completed` and `external_completed_at = now()`, recording which admin performed the override.
4. IF Exam_Type is `assessment` THEN Exam_Result_Manager_UI SHALL NOT offer the `PendingExternal` filter option or the manual-override action for that exam's results (it is a status value/action exclusive to External_Form_Prep_Exam).

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirements 4, 6

---

## Non-Functional Requirements

### Performance Requirements
- WHEN Complete_External_Endpoint is called THEN the system SHALL update the ExamResult row in a single indexed lookup (by `id` + `user_id`) without additional heavy queries.
- WHEN Pending_Exams is evaluated at login THEN adding the `status = Completed` check for External_Form_Prep_Exam SHALL NOT introduce N+1 queries (join/eager-load, consistent with the existing eager-load fix for exam translations); no additional column beyond the existing `status` is needed for this check.

### Security Requirements
- WHEN Complete_External_Endpoint is called THEN the system SHALL verify the authenticated user owns the ExamResult before mutating it (403 otherwise).
- WHEN `external_complete_pattern` is resolved (from server config) THEN the system SHALL treat it as a plain substring for client-side `contains` matching only — no server-side or client-side regex evaluation, to avoid unnecessary complexity/injection surface.

### Usability Requirements
- WHEN an admin configures an External_Form_Prep_Exam THEN Exam_Manager_UI SHALL make clear, via inline copy, that score/retry settings are not enforced for this type.
- WHEN an admin views exam results for an External_Form_Prep_Exam THEN Exam_Result_Manager_UI SHALL make the distinction between "reviewed material" and "completed external form" visually unambiguous.

### Reliability Requirements
- IF the mobile client fails to detect the completion URL (e.g., network interruption, form provider changes redirect behavior) THEN the system SHALL provide the admin manual-override path (Requirement 7.3) as a recovery mechanism.
- WHEN Complete_External_Endpoint is called more than once for the same result THEN the system SHALL be idempotent (Requirement 4.3).

### Data Integrity Requirements
- WHEN an `external_form_prep` Exam is created or updated THEN the system SHALL persist non-null safe defaults for `min_score`/`time_limit` (non-nullable database columns with no schema-level default) regardless of what the client submits or omits — omitting them would otherwise fail at the database level, not just misbehave silently.

---

## Constraints and Assumptions

### Technical Constraints
- No server-side mechanism exists (or will exist) to independently verify Google Form submission; completion is inherently self-reported by the mobile client based on WebView URL navigation.
- The mobile app that performs the WebView flow lives in a separate repository, not this monorepo; this spec only defines the API contract it depends on.
- Existing `ExamResultStatus` gating logic (`InProgress`/`Completed`/`Failed`) must remain unchanged for `assessment`-type exams; the enum is extended with one new case (`PendingExternal`) rather than replaced.
- `ExamResultCalculation` (`routes/console.php`, runs every minute) force-finalizes any `ExamResult` still at `status = InProgress` once `expires_at` has passed, grading it `Completed`/`Failed` by score. This is why `external_form_prep` submissions move straight to `PendingExternal` (not left at `InProgress`) — otherwise this job would incorrectly auto-finalize them by score before the external form is done. No changes to this job are required as long as `PendingExternal` is used.
- `exams.min_score` and `exams.time_limit` are non-nullable columns with no schema-level default (`2025_07_08_185600_create_elearning_tables.php:17-19`); omitting them when creating/updating an `external_form_prep` exam would fail at the database level. Server-assigned defaults (Requirement 1.5) are therefore mandatory, not optional polish.
- Existing code reads `min_score`/`time_limit` without null-guards (e.g. `StartOrResumeExamAction`'s `addMinutes($exam->time_limit)` and `$exam->result->score >= $exam->min_score`); PHP's comparison rules convert a `null` vs. number comparison to a `bool` comparison, so `$score >= null` evaluates `true` for any `$score` — a silent-wrong-answer trap, not just a null-pointer risk. This is a second, independent reason the values must be concrete non-null defaults, not `null`.

### Assumptions
- One External_Form_Prep_Exam maps to exactly one external form/link (no multi-step external chains in this iteration).
- `external_complete_pattern` matching is a simple case-sensitive substring `contains` check performed client-side by the mobile app, against the single server-config value (not a per-exam value).
- External_Form_Prep_Exam is always `is_required = true`; the system does not support creating it as optional.

---

## Success Criteria

### Definition of Done
- [ ] Admin can create/edit an exam with `type = external_form_prep` and `external_url`; no `external_complete_pattern` field exists anywhere in the admin payload/UI.
- [ ] Creating/updating an `external_form_prep` exam always persists safe server-assigned `min_score = 0`, `retry_limit = 0`, `time_limit = <config default>`, and forces `is_required = true` — regardless of client payload, with no NOT NULL database errors.
- [ ] Existing `assessment`-type exams are fully unaffected (regression-tested), including `ExamResultCalculation`'s auto-finalize-on-expiry behavior.
- [ ] Submitting an `external_form_prep` exam always reveals correct answers immediately, regardless of score, once `status` is `PendingExternal` or `Completed`.
- [ ] `min_score`/`retry_limit` do not block submission or retries for `external_form_prep` exams.
- [ ] New Complete_External_Endpoint correctly transitions `status` from `PendingExternal` to `Completed` and is idempotent and ownership-checked.
- [ ] Pending_Exams/login gating for `external_form_prep` exams keys off `status = Completed`, not internal submission.
- [ ] `ExamNotificationService::getRecipientsForExam` excludes `external_form_prep` recipients by `status = Completed`, not `score >= min_score` (regression-tested: a user who has only started still receives reminders).
- [ ] Admin UI (create/edit form, exam list, exam detail, exam-result list/detail) shows exam type and `external_url`, hides `min_score`/`retry_limit`/`time_limit` entirely for `external_form_prep`, and shows exam-result status columns/filters (including `PendingExternal`) with manual override.
- [ ] All backend endpoints covered by unit and integration tests (per project's test design rules — real flow lifecycle test for start → submit → external-complete, plus accumulated-history/retry scenarios, plus a regression test proving `ExamResultCalculation` does not touch `PendingExternal` rows).
- [ ] Code passes `npm run prettier`, `npm run pint` (backend) and `pnpm prettier`, `pnpm typecheck` (frontend).

---

## Open Questions

- Should `external_complete_pattern` ever need to be a list (multiple acceptable patterns) instead of a single string, for future non-Google-Form providers?
- Should there be a limit on how many `external_form_prep` exams reference the same `external_url` (e.g. reused across cohorts), or is 1 exam : 1 URL always assumed?
- ~~Does the manual-override action (Requirement 7.3) need its own permission, or does it reuse `exam.result.list`/an existing edit permission?~~ **Resolved**: it's a write action (force-completes another user's result) gated behind a read-only list permission would let read-only roles (leader/HR lookup) escalate to writes, so it uses `exam.edit` (`PermissionName::ExamEdit`), not `exam.result.list`. See `backend/routes/api.php` and `design.md`'s Component 5b/Security Considerations.
