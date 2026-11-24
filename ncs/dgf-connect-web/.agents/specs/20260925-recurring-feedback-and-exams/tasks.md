# Implementation Plan: Recurring Feedback Polls and Exams

## Overview

The build is risk-first on the backend, then feature-slice per item type, then admin web. The schema and the Recurrence_Resolver come first because every other part depends on them and the trigger walk is the most error-prone logic; it gets exhaustive unit tests before anything consumes it. Recurring Feedback is then delivered end to end (admin validation, pending payload, respond) because it is the simpler slice and proves the resolver against real flows. Recurring assessment exams follow with pending, round-aware start/submit/results and completion recording at both completion points. Admin occurrence filters and the admin web UI come last, since they only read the new columns.

### Implementation Strategy
- Risk-first: schema and resolver with unit tests before any consumer.
- Feature-slice: Feedback end to end, then exams end to end, each closed by a lifecycle feature test that reaches every state through real endpoints.
- Non-recurring code paths change only by an `is_recurring = false` filter; regression tests with accumulated history guard them.
- All business logic lives in Actions; no observers or approval hooks.

## Implementation Plan

### Phase 1: Foundation

- [x] 1. Schema
    - [x] 1.1 Migration adding `is_recurring` to `polls` and `exams`
        - `boolean('is_recurring')->default(false)` after `is_required`
        - Add `@property bool $is_recurring` and a `boolean` cast on `Poll` and `Exam`
        - _Requirements: 1.1, 2.1_

    - [x] 1.2 Migration adding `workplace_id` and `occurrence` to `poll_responses`
        - `foreignId('workplace_id')->nullable()->constrained()->nullOnDelete()`
        - `unsignedSmallInteger('occurrence')->default(1)`
        - Add unique `(user_id, poll_id, occurrence)` before dropping `poll_response_user_poll_unique`, so the `user_id` FK always has an index
        - `down()` restores the old unique only when no duplicate `(user_id, poll_id)` exists; otherwise it throws with a clear message
        - Add the `workplace()` relation and the property docblock on `PollResponse`
        - _Requirements: 7.1, 7.3, 7.4_

    - [x] 1.3 Migration adding `workplace_id` and `occurrence` to `exam_results`
        - Same columns as 1.2; index `(exam_id, occurrence)`
        - Add the `workplace()` relation and the property docblock on `ExamResult`
        - _Requirements: 7.1, 7.3_

    - [x] 1.4 Migration and model for `recurring_completions`
        - Columns, unique and index exactly as in design Entity 1, including `trigger_shift_user_id` and the `anchor_*` position columns
        - `RecurringCompletion` model with `user()`, `workplace()`, `triggerShiftUser()`, `anchorShiftUser()` relations and casts for `anchor_date`, `anchor_start_at`, `completed_at`
        - A `RecurringCompletableType` enum (`poll`, `exam`) and a `RecurringCompletionSourceType` enum (`poll_response`, `exam_result`)
        - _Requirements: 7.2, 7.5_

- [x] 2. Recurrence_Resolver
    - [x] 2.1 Value objects in `app/Data/Recurring/`
        - `ShiftPosition`, `CountableShift`, `RecurringTarget`, `RecurringAnchor`, `RecurringTrigger` as readonly classes
        - `ShiftPosition` compares by `(date, startAt, sortId)`
        - _Requirements: 3.1_

    - [x] 2.2 `LoadCountableShiftsAction`
        - One query as in design Component 1, grouped by workplace, ordered by date, start time and `shift_users.id`
        - "Today" from `now()` in the application timezone
        - _Requirements: 3.5, 3.6, 3.8_

    - [x] 2.3 `ResolveRecurringAnchorsAction`
        - Latest `recurring_completions` row per `(type, id, workplace)`, returning its stored anchor position
        - Legacy_Completion lookup for polls (`workplace_id IS NULL` responses, date `created_at`) and exams (`workplace_id IS NULL` passing results, date `completed_at`)
        - Legacy Anchor = latest Worked_Shift with `date <= completion date`; `lastOccurrence = 1`; no Worked_Shift means no Anchor position and next occurrence 2
        - _Requirements: 6.1, 6.2, 6.3, 7.5_

    - [x] 2.4 `ResolveRecurringTriggersAction`
        - Walk as in design Component 3: Gap_Reference is the later of the Anchor date and the previous Countable_Shift
        - Returns `RecurringTrigger|null` per `"{type}:{id}:{workplaceId}"` with `completionAnchor` = later of the trigger and the latest Worked_Shift
        - One shift load for the union of target workplaces; return early without queries when the target list is empty
        - _Requirements: 3.1–3.13_

    - [x] 2.5 `RecordRecurringCompletionAction`
        - Inserts one row from a `RecurringTrigger` (trigger id, completion anchor, occurrence) and a source model
        - _Requirements: 3.12, 7.2, 7.4_

    - [x] 2.6 Unit tests `tests/Unit/Actions/Common/Recurring/ResolveRecurringTriggersActionTest.php`
        - Every row of the worked-examples table in design Component 3:
            - new employee, upcoming first shift
            - 10-shift cycle: the 11th triggers, the 10th does not
            - two upcoming shifts A/B: B triggers only once A is worked
            - upcoming trigger removed before completion: the next upcoming shift takes over
            - a month of shifts approved in advance: no gap trigger
            - gap of 31 days triggers and restarts the count; exactly 30 days does not
            - late completion: completion anchor is the latest Worked_Shift
            - missed occurrences: only the latest trigger, occurrence +1
            - long history with no Anchor: triggers now, completion anchor is the latest Worked_Shift
            - upcoming Anchor that was never worked: gap measured from the Anchor date
        - Past approved shift without check-out and absent shifts are ignored for count and Gap_Reference
        - Anchor whose shift was deleted still positions the walk
        - Legacy_Completion Anchor with and without a Worked_Shift before it
        - Two workplaces evaluated independently
        - _Requirements: 3.1–3.13, 6.1, 6.2_

- [x] 3. Checkpoint - Resolver verified
    - Run `docker compose exec php php artisan test --parallel --filter Recurring`; ask the user if any rule interpretation fails a test.

### Phase 2: Recurring Feedback

- [x] 4. Admin poll flag
    - [x] 4.1 Validation in `StorePollRequest` and `UpdatePollRequest`
        - `is_recurring` => `['sometimes', 'boolean']`
        - In `after()`, reject when the effective `is_recurring` is true and the type is not feedback or the effective `target_type` is not workplace (stored values on update; `type` is never updatable)
        - _Requirements: 1.3, 1.4, 1.5_

    - [x] 4.2 Persistence in `CreatePollAction` and `UpdatePollAction`
        - Add `is_recurring` to the create payload and the update whitelist; do not add it to the locked fields
        - When the effective `is_recurring` is true, force `is_required = true` and `expires_at = null`
        - Expose `is_recurring` in the admin list and detail
        - _Requirements: 1.1, 1.2, 1.6, 1.9, 6.4, 6.5_

- [x] 5. Pending recurring Feedback
    - [x] 5.1 `GetPendingRecurringPollsAction`
        - Candidates as in design Component 5, filtered through `ResolveRecurringTriggersAction`
        - _Requirements: 4.1, 4.2, 4.6_

    - [x] 5.2 Update `GetPendingPollsAction` and `EvaluatePendingVotesFlagAction`
        - `GetPendingPollsAction` excludes `is_recurring = true`
        - `__invoke` and `getForAuthResponse` merge one-time and recurring lists
        - `getForAuthResponse` skips its early return when an active recurring poll exists at the user's workplaces
        - The flag is written from the merged result
        - _Requirements: 4.1, 4.5, 4.7, 4.8_

- [x] 6. Respond to recurring Feedback
    - [x] 6.1 Recurring branch in `SubmitPollResponseAction`
        - The existing lifetime `already_responded` pre-check runs only in the non-recurring branch
        - Inside the existing transaction after `lockForUpdate()`: resolve the trigger for `poll.workplace_id`, throw `poll.already_responded` when null, create the response with `workplace_id` and `occurrence`, then call `RecordRecurringCompletionAction`
        - Map `UniqueConstraintViolationException` to `poll.already_responded` for both branches
        - _Requirements: 4.3, 4.4, 4.5, 7.1, 7.4_

- [x] 7. Feedback tests
    - [x] 7.1 `tests/Feature/Api/Admin/RecurringPollAdminTest.php`
        - Vote + recurring returns 422; global and specific-users + recurring return 422
        - Recurring stores `is_required = true` and `expires_at = null`, on create and on an update that sends only `is_required = false` or `expires_at`
        - `is_recurring` can be toggled on an active poll with responses
        - Changing the target of an inactive recurring poll away from workplace without `is_recurring = false` returns 422
        - _Requirements: 1.1–1.6, 1.9_

    - [x] 7.2 `tests/Feature/Api/User/RecurringFeedbackFlowTest.php`
        - Lifecycle through real endpoints: admin creates and activates the poll, a shift is approved through the manage endpoint, `/auth/check` lists the poll, respond, not listed; check-in/out through the real flow up to the 11th shift, listed again, respond stores occurrence 2
        - Future shift approved before its date makes the poll pending; rejecting it removes the poll
        - A month of shifts finalized in advance through the shift plan flow does not make the poll pending
        - Gap over 30 days after the previous Countable_Shift makes it pending
        - Late response after extra worked shifts: the next trigger is the 10th shift after the latest worked shift, not after the trigger
        - Poll enabled for an employee with a long shift history: pending immediately, and the next trigger counts from the latest worked shift
        - Out-of-order approval: a past worked shift added through the work history flow after the Anchor counts; one before the Anchor does not
        - Respond without a trigger returns 422; double respond for the same round returns 422
        - User with `has_pending_votes = false` still gets a due recurring poll
        - Legacy response before enabling recurring counts as occurrence 1
        - Vote poll and non-recurring Feedback poll with accumulated history keep one response per employee
        - Rollback: force `RecordRecurringCompletionAction` to throw; no response row and unchanged `result_counters`
        - _Requirements: 3.4, 3.8, 3.11, 3.12, 3.13, 4.1–4.8, 6.1, 6.3, 7.4_

- [x] 8. Checkpoint - Feedback slice complete
    - Run the full backend suite in parallel; ask the user if any question arises.

### Phase 3: Recurring Exams

- [x] 9. Admin exam flag
    - [x] 9.1 Validation in `CreateUpdateExamRequest`
        - `is_recurring` => `['sometimes', 'boolean']`
        - In `after()`, reject when the effective `is_recurring` is true and `workplaces` is empty or the effective type is `external_form_prep`
        - _Requirements: 2.2, 2.7_

    - [x] 9.2 Persistence in `CreateExamAction` and `UpdateExamAction`
        - Persist `is_recurring`; when set, force `is_required = true` and `end_at = null`
        - Expose `is_recurring` in `ExamService::list` / `show`
        - _Requirements: 2.1, 2.3, 2.4, 2.5, 6.4, 6.5_

- [x] 10. Pending and round-aware exam reads
    - [x] 10.1 `GetPendingRecurringExamsAction` and the split in `GetPendingRequiredExamsAction`
        - Existing query gains `is_recurring = false`
        - Recurring query as in design Component 7, triggers resolved for `exam.workplaces ∩ user workplaces`
        - Merge and keep the existing sort; `GetPendingRequiredExamsForAuthAction` output shape unchanged
        - _Requirements: 5.1, 5.2, 5.5, 5.10_

    - [x] 10.2 `ResolveExamRoundAction`
        - Returns `{activeTrigger, roundResults}` as in design Component 7: the workplace with an in-progress attempt wins, otherwise the earliest trigger
        - _Requirements: 5.3, 5.6_

    - [x] 10.3 Round-aware `ExamService::setCustomFieldForExam`
        - Recurring with trigger: `result`, `retry_count` and `can_view_result` from `roundResults`
        - Recurring without trigger: latest Completion, with `retry_count` from that Completion's round
        - _Requirements: 5.6, 5.7_

    - [x] 10.4 Round-aware `StartOrResumeExamAction`
        - Resume only from `roundResults`; a resumed row without a workplace is bound to the active trigger's workplace and occurrence
        - New rows store `workplace_id` and `occurrence` of `activeTrigger`
        - `carryOverCorrectAnswers` reads only `roundResults`
        - _Requirements: 5.3, 5.8_

    - [x] 10.5 `SubmitExamAnswerAction` session lookup
        - For recurring exams, the session is the user's latest `in_progress` result, independent of the round
        - _Requirements: 5.9_

    - [x] 10.6 Hide unavailable recurring exams from the employee exam list
        - `GetUnavailableRecurringExamIdsAction`: no trigger, no Completion and no in-progress attempt
        - _Requirements: 5.11_

- [x] 11. Completion recording
    - [x] 11.1 `RecordRecurringExamCompletionAction`
        - Skip when the exam is not recurring or the result has no `workplace_id`
        - Resolve the trigger for the result's workplace only and record one completion; no trigger means no completion
        - _Requirements: 5.4, 5.5, 5.9, 3.9, 7.2_

    - [x] 11.2 Call it at both completion points
        - `SubmitExamAnswerAction` final submit that passes, inside its existing transaction
        - `ExamResultCalculation` expiry grading that passes: wrap the conditional `where status = in_progress` update and the call in one `DB::transaction`, and call it only when the update affected one row
        - _Requirements: 5.4_

    - [x] 11.3 Confirm that `NotifyRequiredExamActivationJob` still sends only the first activation push
        - No code change expected; covered by a test
        - _Requirements: 5.12_

- [x] 12. Exam tests
    - [x] 12.1 `tests/Feature/Api/Admin/RecurringExamAdminTest.php`
        - Recurring without workplaces returns 422; recurring external-form exam returns 422, including an update that changes the type to external form
        - Recurring forces required and clears `end_at`
        - _Requirements: 2.1–2.7_

    - [x] 12.2 `tests/Feature/Api/User/RecurringExamFlowTest.php`
        - Lifecycle through real endpoints: pending, start, fail, retry, pass, not pending; shifts worked through the real flow up to the 11th, pending again with `result = null` and `retry_count = 0`, new attempt stores occurrence 2 and carries over nothing from occurrence 1
        - In-progress attempt when a newer trigger arrives at the same workplace: the pass records the newest trigger and nothing is pending afterwards
        - Two workplaces pending: a pass records only the attempt's workplace; the exam stays pending; the next attempt binds to the other workplace
        - In-progress attempt at one workplace when the other gets an earlier trigger: start resumes the same attempt
        - Expiry-graded pass in `ExamResultCalculation` records the completion
        - Trigger shift rejected during an attempt: submit is accepted, the pass records nothing and the exam is not pending
        - Recurring exam with a past `end_at` in the database stays pending
        - Legacy passing result before enabling recurring anchors correctly and counts as occurrence 1
        - In-progress attempt started before the exam became recurring is bound to the active trigger on resume and records a completion when passed
        - Non-recurring required exam with accumulated history keeps its lifetime satisfaction
        - Rollback: force `RecordRecurringCompletionAction` to throw on final submit; the result stays in progress and no completion exists
        - Rollback in the job: force it to throw during expiry grading; the result stays in progress
        - Activation push is sent once and not again on later occurrences
        - _Requirements: 3.8, 3.9, 5.1–5.12, 6.1, 6.3_

- [x] 13. Checkpoint - Exam slice complete
    - Run the full backend suite in parallel; ask the user if any question arises.

### Phase 4: Admin Occurrence Filters

- [x] 14. Poll filters
    - [x] 14.1 `GetPollResponsesAction` and `PollController::responses`
        - Optional `occurrence`; eager-load `workplace:id,name`
        - _Requirements: 8.1, 8.4_

    - [x] 14.2 `GetPollAction` and `PollController::show`
        - `available_occurrences`; with `occurrence`, `result_counters` aggregated from responses of that occurrence in the stored shape
        - _Requirements: 8.1, 8.4_

- [x] 15. Exam filters
    - [x] 15.1 `GetExamStatisticsAction` and `StatisticsRequest`
        - Optional `occurrence` on the base query; response adds `available_occurrences`
        - For recurring exams, workplace filter on `exam_results.workplace_id` (membership fallback for rows without one) and latest attempt ranked per `(user_id, workplace_id)`
        - _Requirements: 8.2, 8.4_

    - [x] 15.2 `ExamResultFilter`, `IndexRequest`, `ExamResultExport`
        - Optional `occurrence` filter; rows include `occurrence` and `workplace {id, name}`
        - Export adds occurrence and workplace name columns with translated headers
        - _Requirements: 8.3, 8.4_

    - [x] 15.3 Tests in `RecurringPollAdminTest` and `RecurringExamAdminTest`
        - Each endpoint with and without `occurrence`, using data produced by the real respond/submit flow across two rounds
        - Exam statistics with two workplaces and the workplace filter
        - Permission checks stay as today
        - _Requirements: 8.1–8.4_

- [x] 16. Checkpoint - Backend complete
    - Run the full backend suite in parallel, then `npm run prettier` and `npm run pint` in `./backend`.

### Phase 5: Admin Web

- [x] 17. Types and API
    - [x] 17.1 Update `poll.d.ts`, `poll_response.d.ts`, `exam.d.ts`, `exam_result.d.ts`
        - _Requirements: 1.6, 2.5, 8.5_

    - [x] 17.2 Pass `occurrence` in `usePollQuery`, `usePollResponsesQuery`, `useGetExamStatisticsQuery`, `useGetAllExamResultQuery`, `exportExamResult`
        - _Requirements: 8.1–8.3_

- [x] 18. Forms
    - [x] 18.1 `poll_form.tsx`
        - Recurring checkbox with tooltip, visible only for Feedback + workplace
        - Checking sets and disables `is_required` and clears and disables the expiry date; hiding resets it to false
        - Include it in the payload and the edit initial values
        - _Requirements: 1.7, 1.8_

    - [x] 18.2 `exam_form.tsx`
        - Recurring checkbox with tooltip beside `is_required`, hidden and reset to false for the external-form type
        - Checking sets and disables `is_required` and clears and disables the end date
        - Workplace validator required while recurring; the date-order validator is skipped when the end date is empty
        - Include it in the payload and the edit/copy initial values
        - _Requirements: 2.2, 2.6, 2.8_

- [x] 19. Filters and columns
    - [x] 19.1 Poll results page
        - Occurrence Select from `available_occurrences` with "All"; occurrence and workplace columns
        - Shown when `is_recurring` or `max(available_occurrences) > 1`
        - _Requirements: 8.5, 8.6_

    - [x] 19.2 `ExamStatistics.tsx`
        - Occurrence Select next to the workplace Select, under the same visibility rule
        - _Requirements: 8.5, 8.6_

    - [x] 19.3 Exam result list
        - Occurrence NumberInput filter and occurrence and workplace columns, always shown; export passes the filter
        - _Requirements: 8.3, 8.7_

- [x] 20. Translations
    - [x] 20.1 Run `pnpm trans:prepare`, fill `vi` and `ja`, run `pnpm trans:apply`
        - _Requirements: Usability_

- [x] 21. Frontend tests
    - [x] 21.1 `poll_form.test.tsx`
        - Checkbox visibility by type and target; required checked and disabled and expiry cleared while recurring; reset when hidden
        - _Requirements: 1.7, 1.8_

    - [x] 21.2 `exam_form.test.tsx`
        - Required checked and disabled; end date cleared and disabled; workplace validation while recurring; checkbox hidden for the external-form type
        - _Requirements: 2.6, 2.8_

    - [x] 21.3 Occurrence filter tests for the poll results page and `ExamStatistics`
        - The selected occurrence is passed as a query param; the selector follows the visibility rule
        - _Requirements: 8.5, 8.6_

### Phase 6: Final Validation

- [x] 22. Code Quality
    - [x] 22.1 Backend: `npm run prettier` and `npm run pint` in `./backend`
    - [x] 22.2 Backend: `docker compose exec php php artisan test --parallel`
    - [x] 22.3 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test` in `./frontend`
    - [x] 22.4 Review every new and modified file and remove any added comments

## Notes

- **Conventions**: Actions for all business logic, `use` imports only, camelCase test methods, `t(...)` for all admin text, no code comments.
- **Ordering rationale**: the resolver is shared by both slices and is the highest-risk logic, so it is unit-tested in isolation first. Feedback precedes exams because it has one workplace and one completion point, which validates the resolver and completion table before the multi-workplace exam slice.
- **Risk: unique index swap on `poll_responses`**: add the new composite unique before dropping the old one, because MySQL requires an index on the `user_id` FK. Verify on a copy of production data that no duplicates exist.
- **Risk: completion points in exams**: two places move an assessment result to a Completion. Missing one would leave an employee stuck with a pending exam. Task 12.2 has a test per completion point.
- **Risk: `ExamResultCalculation` concurrency**: recording the completion must stay in the same transaction as the conditional `where status = in_progress` update, so a job run racing with a final submit cannot record twice. The unique on `recurring_completions` is the backstop.
- **Risk: `/auth/check` latency**: measure with an employee holding about 1,000 shifts. If the shift query scans, add an index on `shift_users (user_id, status)` in the migration from task 1.1.
- **Uncovered by design**: `GetExamCompletionStatsAction` on the exam list keeps lifetime semantics by decision. External-form exams are not recurring. The employee mobile app has no change and no tests in this repository.
