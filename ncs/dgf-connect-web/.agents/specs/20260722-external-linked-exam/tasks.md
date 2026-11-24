# Implementation Plan: External-Linked Exam (External_Form_Prep_Exam)

Source of truth: `requirements.md` (what/why) and `design.md` (how). Task numbers below map to `design.md` Components where noted.

## 1. Database & Config

- [x] 1.1 Migration: add `type` (string, nullable), `external_url` (string, nullable) to `exams` table.
- [x] 1.2 Migration: add `external_completed_at` (nullable timestamp) to `exam_results` table.
- [x] 1.3 Add `config/exam.php` with `default_external_complete_pattern` and `default_external_time_limit` entries.

## 2. Enums

- [x] 2.1 New `App\Enums\ExamType` (`Assessment = 'assessment'`, `ExternalFormPrep = 'external_form_prep'`).
- [x] 2.2 Extend `App\Enums\ExamResultStatus` with `PendingExternal = 'pending_external'`.

## 3. Models

- [x] 3.1 `Exam.php`: cast `type`, add `external_url` to fillable/guarded scope as needed; add a `type` accessor/helper that treats `null` as `ExamType::Assessment`.
- [x] 3.2 `ExamResult.php`: cast `external_completed_at`.

## 4. Admin Exam Create/Update (Component 1, 1b)

- [x] 4.1 `Http/Requests/Admin/Exam/CreateUpdateExamRequest.php`: add `type` (required, enum) and `external_url` (required when `type = external_form_prep`) validation rules; skip `validateMinScore` when `type = external_form_prep`.
- [x] 4.2 `Services/Admin/ExamService.php` (`create()`/`update()`): when `type === external_form_prep`, unconditionally persist `min_score = 0`, `retry_limit = 0`, `time_limit = config('exam.default_external_time_limit')`, `is_required = true`, regardless of submitted payload. Unchanged behavior for `assessment`/`null`.
- [x] 4.3 `ModelFilters/ExamFilter.php`: add filter by `type`.

## 5. Employee-Facing Exam Flow (Components 2, 3, 4)

- [x] 5.1 `Actions/User/Exam/StartOrResumeExamAction.php`: skip `retry_limit` and "already passed" (`score >= min_score`) blocking when `exam->type === ExamType::ExternalFormPrep`.
- [x] 5.2 `Actions/User/Exam/SubmitExamAnswerAction.php`: on final submit for `external_form_prep`, set `status = PendingExternal` unconditionally (never `Failed`), still persisting `completed_at`/`score`/`correct_answers`.
- [x] 5.3 `Actions/User/Exam/GetExamResultAction.php`: reveal answers for `external_form_prep` when `status` is `PendingExternal` or `Completed` (bypass the `end_at` gate for this type only); include `external_url` + `config('exam.default_external_complete_pattern')` in the response when `status = PendingExternal`.

## 6. Complete External Step (Component 5)

- [x] 6.1 New `Actions/User/Exam/CompleteExternalExamStepAction.php`: conditional `UPDATE ... WHERE id=? AND user_id=? AND status='pending_external'` → `status = Completed`, `external_completed_at = now()`. Idempotent no-op otherwise; owner check → 403 if `result.user_id` mismatch.
- [x] 6.2 `Http/Controllers/User/ExamController.php` + `routes/api.php`: new `POST /exams/{exam}/results/{result}/complete-external`.

## 7. Pending-Exam Gating (Component 6)

- [x] 7.1 `Actions/Common/Exam/GetPendingRequiredExamsAction.php`: branch by `exam.type` — `assessment` unchanged (`score >= min_score`); `external_form_prep` → outstanding until `status = Completed`.
- [x] 7.2 `Actions/Common/Exam/GetPendingRequiredExamsForAuthAction.php`: add `type` to the serialized payload.

## 8. FCM Activation Notification Fix (Component 8)

- [x] 8.1 `Services/ExamNotificationService.php::getRecipientsForExam()`: branch by `exam.type` — `assessment` unchanged; `external_form_prep` → exclude only `status = Completed` (not `score >= min_score`).
- [x] 8.2 `Services/ExamNotificationService.php::serializeExam()`: add `type` to the FCM payload.

## 9. Admin Exam-Result Visibility & Manual Override (Component 5, 7)

- [x] 9.1 New admin action/endpoint: `POST /exam-results/{examResult}/complete-external-override` on `Http/Controllers/Admin/ExamResultController.php` + `routes/api.php` — sets `status = Completed`, `external_completed_at = now()`; 422 if exam is `assessment` type; permission reuses existing exam-result edit/list permission. Also added `exam_results.external_completed_by` (nullable FK to `users`) to record which admin performed the override, per Requirement 7.3 — this column was missing from the original design and has been added.
- [x] 9.2 `ModelFilters/ExamResultFilter.php`: verify `status()` filter already accepts `pending_external` (confirmed generic — no code change needed; test coverage in Task 14).

## 10. Frontend Types & Enums

- [x] 10.1 `frontend/src/types/models/exam.d.ts`: add `type`, `external_url`; document `min_score`/`retry_limit`/`time_limit` as server-defaulted for `external_form_prep`.
- [x] 10.2 `frontend/src/types/models/exam_result.d.ts`: add `external_completed_at`.
- [x] 10.3 `frontend/src/enums/exam_status.enum.ts`: add `PENDING_EXTERNAL: { value: 'pending_external', text: 'Pending external form' }`.
- [x] 10.4 **Correction**: `components/ExamStatusBadge.tsx` is the *Exam schedule* status badge (scheduled/active/ended) — unrelated to `ExamResultStatus`. The `PendingExternal` color/label belongs in `exam-result/[id]/index.page.tsx`'s `getStatusBadge()` colorMap instead (handled in Task 12.2). `design.md`/`requirements.md` will be corrected to match at the end.
- [x] 10.5 `frontend/src/pages/manage/exam/api.ts`: make `retry_limit`/`min_score`/`time_limit` optional in the create/update payload type; add API call for the manual override endpoint.

## 11. Admin Exam Form & List (Component 7)

- [x] 11.1 `frontend/src/pages/manage/exam/partials/exam_form.tsx`: add `type` selector ("Đánh giá kiến thức" / "Ôn tập cho form ngoài"); show `external_url` input only when `external_form_prep`; hide (not disable) `min_score`/`retry_limit`/`time_limit` inputs for this type; force-check and disable `is_required` toggle for this type.
- [x] 11.2 `frontend/src/pages/manage/exam/index.page.tsx`: add type badge/column; hide `retry_limit`/`min_score` columns' values for `external_form_prep` rows.
- [x] 11.3 `frontend/src/pages/manage/exam/partials/InfoExam.tsx`: hide the `Retry limit`/`Min score`/`Time limit` block for `external_form_prep` exams (shows `external_url` instead).

## 12. Admin Exam-Result UI (Component 7)

- [x] 12.1 `frontend/src/pages/manage/exam-result/index.page.tsx`: status filter includes `pending_external` (came for free once `ExamStatusEnum` was extended in Task 10.3 — filter options and column rendering both derive from `Object.values(ExamStatusEnum)`).
- [x] 12.2 `frontend/src/pages/manage/exam-result/[id]/index.page.tsx`: show `status`/`external_completed_at`; replace `min_score`/`time_limit` display with Google Form link + external completion time for `external_form_prep`; add manual "mark completed" override button (visible only when exam is `external_form_prep` and status is `pending_external`).

## 13. Translations

- [x] 13.1 Convert all new UI copy to `t(...)`; ran `pnpm trans:prepare`, translated all new keys directly in `vi`/`ja` locale files (`translation.json` + `field.json`), registered the one dynamic-lookup key (`Pending external form`) in `for_scanner.ts`, verified with a final `pnpm trans:prepare` reporting 0 new keys, `pnpm trans:cleanup`.

## 14. Backend Tests

- [x] 14.1 Unit: `Unit/Models/ExamTest.php` (new) — `type` accessor treats `null` as `assessment`, resolves explicit `external_form_prep`.
- [x] 14.2 Feature: new `Feature/Api/Admin/ExternalFormPrepExamAdminTest.php` — creating `external_form_prep` without scoring fields persists server-assigned defaults (min_score=0, retry_limit=0, time_limit=120, is_required=true); a crafted payload with min_score/retry_limit/time_limit/is_required is still overridden; missing `external_url` → 422. Existing `ExamCreateUpdateTest.php` (assessment) verified unaffected.
- [x] 14.3 Covered via `ExternalFormPrepExamTest` (Feature, real HTTP submit) — `external_form_prep` submit sets `status = pending_external` regardless of score; existing `ExamSubmitTest`/`ExamScheduleAccessTest` (assessment) verified unaffected.
- [x] 14.4 Covered via `ExternalFormPrepExamTest::testCompleteExternalIsIdempotent` / `testCompleteExternalRejectsAnotherUsersResult` (real HTTP endpoint, matching this codebase's Feature-test-over-Action-unit-test convention).
- [x] 14.5 Unit: new `Unit/Jobs/ExamResultCalculationTest.php` — a `pending_external` result past `expires_at` is untouched by the job; an abandoned `assessment` session is still auto-finalized (regression).
- [x] 14.6 Unit: extended `Unit/Services/ExamNotificationServiceTest.php` — `external_form_prep` recipients excluded only by `status = Completed`, not by starting alone; `assessment` regression unchanged; `serializeExam` includes `type`.
- [x] 14.7 Feature: `ExternalFormPrepExamTest::testFullLifecycleFromStartToExternalCompletionRemovesExamFromPendingExams` (real start → submit final → assert reveal + `pending_external` → complete-external → assert `Completed` → disappears from `pending_exams`), plus retry/re-submission (`testRestartingAfterSubmissionIsNotBlockedByAlreadyPassedOrRetryLimit`).
- [x] 14.8 Feature: `ExternalFormPrepExamAdminTest::testAdminOverrideCompletesExternalStepAndAppearsInPendingExamsCheck` / `testAdminOverrideRejectsAssessmentTypeExam` — sets `Completed` + `external_completed_by`, 422 for `assessment`, reflected in `pending_exams` on next login.
- [x] 14.9 Assessment regression — confirmed via full existing suite (`ExamSubmitTest`, `ExamScheduleAccessTest`, `ExamCreateUpdateTest`, `ExamResultDetailTest`, `ExamIndexStatusFilterTest`, `ExamNotificationServiceTest`, `NotifyRequiredExamActivationJobTest`) all passing unmodified in behavior. All 69 Exam-related tests pass (`paratest --filter=Exam -p 1`); parallel workers (`-p` default) race on the shared test DB during `migrate:fresh` — a pre-existing environment characteristic unrelated to this feature, confirmed by testing with `-p 1`.

## 15. Final Checks (checkpoint)

- [x] 15.1 Backend: `npm run prettier` and `npm run pint` — clean (pint: no changes needed; prettier reformatted 1 test file, no logic change).
- [ ] 15.2 Backend: run full test suite (`docker compose exec php ./vendor/bin/paratest`). All 69 Exam-related tests pass with `-p 1`. Full-suite run in progress to confirm no unrelated regressions (parallel `-p` default races on the shared test DB during `migrate:fresh` — pre-existing environment characteristic, not caused by this feature).
- [x] 15.3 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` — all clean.
