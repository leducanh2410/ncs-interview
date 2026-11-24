# Implementation Plan: Exam Targeted Employees

## Overview

Both design decisions that gated this plan are settled — exam-wide targeting with an explicit Delivery_Mode control (DDR 1), and the deliberate behaviour change for workplace-less employees (Requirement 8.4). The build order is foundation-first: storage and the write path, then the audience rule with its test matrix in the same phase, then the four consumers, then the frontend, then the remaining integration and lifecycle tests. Every phase ends at a checkpoint that runs the existing suites, so a regression in exam visibility is caught next to the change that caused it rather than at the end.

### Implementation Strategy

- Foundation-first for storage and persistence; the audience rule ships with its own test matrix ahead of any consumer being rewired.
- Rewire read paths one at a time, each keeping its unrelated filters (date windows, `is_required`, result-satisfaction exclusions, ordering, pagination) untouched.
- Write the employee-facing lifecycle tests against the real admin and employee endpoints, not against hand-inserted pivot rows, so the tested state is provably reachable.
- Keep every backend change inside the existing transactions and the existing Action pattern.

## Implementation Plan

### Phase 0: Decisions (settled — kept for the record)

- [x] 0. Design decisions
    - [x] 0.1 Targeting model (DDR 1) — **decided: exam-wide**
        - `exam_user` stores `{exam_id, user_id}`; naming anyone restricts the whole exam to those people
        - Accepted without the production data check (no database access at spec time). Mitigations: an explicit Delivery_Mode control so the restrictive semantics are chosen rather than inferred, and a backfillable path to the per-workplace model should it ever be needed
        - If a future requirement does ask for a mixed audience ("all of workplace A plus two people from workplace B"), that is a re-plan, not a patch: `exam_user` gains `workplace_id`, the validator pairs ids with workplaces, the audience scope becomes a subquery correlated on both `exams.id` and `workplaces.id`, the recipients action partitions workplaces by mode, and the form renders one selector per workplace
        - _Requirements: DDR 1_

    - [x] 0.2 Confirm the workplace-less behaviour change — **done**
        - Confirmed with the product owner: every employee belongs to at least one workplace, leaders to several, and the only workplace-less accounts are global admins, who do not take exams
        - No production count needed; Requirements 5.1, 6.5, 7.4 and 8.4 stand as written
        - _Requirements: 8.4_

### Phase 1: Data Foundation

- [x] 1. Exam_User_Link storage
    - [x] 1.1 Create the `exam_user` migration
        - New migration `create_exam_user_table` with `exam_id` and `user_id` only
        - Foreign keys to `exams` and `users`, both `cascadeOnDelete`
        - `unique(exam_id, user_id)` and `index(user_id, exam_id)`
        - No timestamps, matching `exam_workplace` and `document_user`
        - No backfill and no data migration: an existing exam has no rows and is therefore All_Members_Mode automatically
        - _Requirements: 3.1, 8.1, 8.5_

    - [x] 1.2 Add the `targetedUsers` relation to `Exam`
        - `belongsToMany(User::class, 'exam_user')`
        - Name it `targetedUsers` so it cannot be confused with `results`
        - Add the property annotation to the model docblock
        - _Requirements: 3.4_

- [ ] 2. Checkpoint - Foundation
    - Run the migration and confirm the existing backend suite still passes. Ask the user if questions arise.

### Phase 2: Admin Write Path

- [x] 3. Targeting payload validation
    - [x] 3.1 Add structural rules to `CreateUpdateExamRequest`
        - `targeted_users` as `array`; `targeted_users.*` integer and `exists:users,id`
        - An omitted or empty `targeted_users` must validate successfully
        - _Requirements: 4.1, 4.6_

    - [x] 3.2 Add the cross-field checks in `withValidator`
        - Fail when `targeted_users` is non-empty and `workplaces` is empty
        - Build the exempt set from `exam_user` rows of the route's exam (empty on create)
        - Verify the remaining ids in a single query against `workplace_users` for the submitted workplace ids; reject naming each offending employee rather than silently dropping them
        - Import all classes at the top of the file; no inline fully qualified names
        - _Requirements: 4.2, 4.3, 4.4_

- [x] 4. Targeting persistence
    - [x] 4.1 Create `SyncExamTargetedUsersAction`
        - `$exam->targetedUsers()->sync(array_unique($ids))` — covers replacement and de-duplication
        - Treat a missing key as an empty array, which clears targeting
        - _Requirements: 3.1, 3.2, 4.5, 8.7_

    - [x] 4.2 Wire the action into `CreateExamAction` and `UpdateExamAction`
        - Call it inside the existing transaction, immediately after `workplaces()->sync(...)`
        - _Requirements: 3.7_

    - [x] 4.3 Extend the admin show endpoint
        - Eager-load `targetedUsers` in `ExamService::show()` with an explicit column list: `id`, `first_name`, `last_name`, `first_name_kana`, `last_name_kana`, `staff_no`. Without it the response would carry every non-hidden `users` column per targeted employee
        - Set a dynamic `is_current_member` flag on each, resolved in one query over the exam's workplace ids
        - Serialise with the plain `name`, not `toDisplayArray()` — the search endpoint returns plain `name`, and the picker and the restored chips must agree
        - Leave `ExamService::list()` unchanged so the admin list query is not made heavier
        - _Requirements: 3.4, 3.5_

- [ ] 5. Checkpoint - Write path
    - Confirm an exam can be created and updated with and without `targeted_users`, that rows are replaced rather than accumulated, that a stale link can be re-saved untouched, and that the existing exam suite still passes.

### Phase 3: Audience Rule (highest risk)

- [x] 6. Shared visibility rule
    - [x] 6.1 Implement `ApplyExamAudienceScopeAction`
        - Short-circuit to an empty result when the employee belongs to no workplace
        - Admit Legacy_Untargeted_Exam via `doesntHave('workplaces')`
        - Otherwise require both: shares a workplace with the employee, **and** either `doesntHave('targetedUsers')` or `whereHas('targetedUsers', user)`
        - Keep the two halves as independent `whereHas` clauses; do not nest one inside the other
        - Derive workplace membership from `workplace_users` at query time, never from the request
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7_

    - [x] 6.2 Write the visibility matrix unit tests before wiring any consumer
        - Legacy exam qualifies an employee with at least one workplace
        - Employee with no workplace qualifies for nothing, including a legacy exam
        - All_Members_Mode exam qualifies every member of every targeted workplace
        - Named_Members_Mode exam qualifies only named members, and not a non-named member of the same workplace
        - Multi-workplace exam qualifies a member of any one of its workplaces
        - Employee named but no longer a member of any targeted workplace does not qualify
        - Employee named on an exam whose workplaces they never belonged to does not qualify
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6, 5.7, 6.5_

- [ ] 7. Checkpoint - Audience rule
    - The matrix must pass before any consumer is rewired. If a case cannot be expressed in one query, stop and raise it with the user rather than falling back to per-row evaluation.

### Phase 4: Consumers of the Rule

- [x] 8. Rewire the three employee-facing decision points
    - [x] 8.1 `GetExamsAction`
        - Replace the inline `whereHas('workplaces') / orDoesntHave('workplaces')` block with the scope
        - Keep the existing early return for workplace-less employees as a query-saving short-circuit
        - Leave date windows, `is_required` handling, ordering, pagination, and the per-exam post-processing untouched
        - _Requirements: 6.1, 6.5_

    - [x] 8.2 `GetPendingRequiredExamsAction`
        - Replace the inline workplace block with the scope
        - Leave the `paper_status` and `is_disabled` guard and the already-satisfied exclusion untouched
        - _Requirements: 6.2, 6.5_

    - [x] 8.3 Rewrite `ExamService::canUserViewExam`
        - Replace the PHP loop over `$exam->workplaces` with an existence query using the scope
        - This is what makes the three decision points agree structurally rather than by convention
        - `GetExamAction`, `StartOrResumeExamAction` and `SubmitExamAnswerAction` keep calling it unchanged
        - _Requirements: 6.3, 6.4, 6.5_

    - [x] 8.4 Add `ExamService::canUserViewExamResult` and use it in `GetExamResultAction`
        - Returns `canUserViewExam(...)` or "this employee owns a result for this exam"
        - Only `GetExamResultAction` switches to it, so the exception cannot leak into the start/submit paths
        - _Requirements: 6.6_

- [x] 9. Notification recipients
    - [x] 9.1 Create `ResolveExamRecipientsAction`
        - Shares a workplace with the exam, or — for a legacy exam — belongs to any workplace at all
        - Plus, when the exam names employees, `whereIn('users.id', targetedIds)`
        - One query; no branch per workplace
        - _Requirements: 7.1, 7.3, 7.4, 8.2_

    - [x] 9.2 Reduce `ExamNotificationService::getRecipientsForExam` to a thin caller
        - Keep the approved-paper-status, not-disabled, already-satisfied filters and the `with('devices')` eager-load in the service
        - Leave `sendActivationNotification`, locale grouping, and the FCM payload untouched
        - Leave `NotifyRequiredExamActivationJob` untouched
        - _Requirements: 7.2, 7.5_

- [ ] 10. Checkpoint - Backend complete
    - Run the full backend suite. Existing `ExamNotificationServiceTest` and `NotifyRequiredExamActivationJobTest` expectations may need updating for the workplace-less change; update them deliberately and confirm each change is the intended behaviour, not a test bent to fit a bug.

### Phase 5: User Search Filter

- [x] 11. Multi-workplace search
    - [x] 11.1 Add `UserFilter::workplaceIds(array $ids)`
        - `whereHas('workplaces', fn($q) => $q->whereIn('workplace_id', $ids))`, next to the existing `workplace()` and `workplaceNone()`
        - Leave `workplace()` untouched so the workplace-user, shift, and document screens keep their behaviour
        - _Requirements: 2.1_

    - [x] 11.2 Accept `workplace_ids` in `SearchUserRequest`
        - `workplace_ids` → `nullable`, `array`; `workplace_ids.*` → `integer`
        - _Requirements: 2.1_

    - [x] 11.3 Test the filter
        - Returns members of any listed workplace and nobody else
        - `workplace_id` alone still behaves exactly as before
        - _Requirements: 2.1, 2.3, 2.4_

### Phase 6: Admin Frontend

- [x] 12. Types and API surface
    - [x] 12.1 Extend the exam types
        - Add `targeted_users: ExamTargetedUser[]` (with `is_current_member`) to the exam detail type
        - Add `targeted_users: string[]` and `delivery_mode: 'workplaces' | 'users'` to the form type, the latter marked as form-only state
        - _Requirements: 1.7, 3.4_

- [x] 13. Delivery mode and employee selector on the exam form
    - [x] 13.1 Render the Delivery_Mode control
        - Two options beneath the workplace field — "whole workplaces" (default) and "named employees" — as a `Radio.Group` or `SegmentedControl`
        - Label each option by the audience it produces, not by what the control does
        - Disable "named employees" while no workplace is selected, with a hint saying why
        - Keep it out of the request payload entirely: it is form state, and the API distinguishes the modes by whether `targeted_users` is empty
        - **The form is `mode: 'uncontrolled'`** (`exam_form.tsx:179`), so `getInputProps` will not re-render siblings on change. Subscribe explicitly with `form.watch('workplaces', ...)` and `form.watch('delivery_mode', ...)` into component state, or lift both values into state. Expect this to be the fiddliest part of the frontend work
        - _Requirements: 1.1, 1.2, 1.3, 1.5, 9.1_

    - [x] 13.2 Render the selector in "named employees" mode
        - One `DUserSelectRemoteMultiple`, rendered only in that mode
        - Pass `extraParams={{ workplace_ids: selectedWorkplaceIds }}`; `CommonHelper.urlQueryToString` already emits `workplace_ids[]=…`
        - Leave `minSearchLength` at the default 3, matching the endpoint's `q` → `min:3` rule
        - _Requirements: 1.3, 2.1, 2.2, 2.3, 2.4_

    - [x] 13.3 Payload mapping, mode switching, and workplace changes
        - Submit `targeted_users` as a flat id array; `[]` whenever the mode is "whole workplaces", even if employees were named before the switch
        - Block submission client-side when the mode is "named employees" and nobody is named — the API reads `[]` as All_Members_Mode and cannot tell the two apart
        - Do **not** prune named employees when the workplace selection changes — the client cannot know memberships; the server rejects by name on submit
        - Surface that server validation error against the selector field
        - _Requirements: 1.4, 1.6, 1.7, 2.5, 4.3_

    - [x] 13.4 Repopulate on edit
        - Set the mode from the data: `targeted_users.length > 0 ? 'users' : 'workplaces'`
        - Map `targeted_users` to `initialOptions` (`{ value, label: "name (staff_no)" }`) as well as to the value array — setting only the value renders raw ids
        - Mark entries with `is_current_member: false` in their label so the admin can see a stale selection
        - Saving an untouched exam must leave both the stored rows and the audience unchanged
        - _Requirements: 3.4, 3.5, 8.3_

    - [x] 13.5 Audience summary line
        - Below the selector, state that the exam goes only to the named employees
        - _Requirements: 1.8, 9.1_

- [x] 14. Translations
    - [x] 14.1 Route all new copy through the translation layer
        - Both mode labels, the disabled-mode hint, selector label, placeholder, audience summary, stale marker, and the "name at least one employee" validation message
        - Run `pnpm trans:prepare`, fill only `vi` and `ja` in the generated batch, then `pnpm trans:apply`
        - Do not edit locale JSON files by hand and do not change `key`, `en`, or `sources`
        - _Requirements: 1.8, 9.1, 9.2, NFR Usability_

- [ ] 15. Checkpoint - Frontend
    - Confirm both modes render, that the selector searches across all selected workplaces, that switching back to "whole workplaces" really submits `[]`, and that editing repopulates the right mode (including a stale entry) against a running backend before writing the frontend tests.

### Phase 7: Testing

- [x] 16. Backend integration and lifecycle tests
    - [x] 16.1 Admin write path (`ExamTargetedEmployeesTest`)
        - Create an exam naming a subset of one workplace and assert the persisted rows
        - Update to a different subset and assert replacement rather than accumulation
        - Reject a payload naming a non-member of any selected workplace
        - Reject a non-empty `targeted_users` with an empty `workplaces`
        - Accept an omitted `targeted_users` and assert All_Members_Mode
        - Remove a named employee from the workplace, then re-save the exam untouched: save succeeds, rows unchanged, audience unchanged
        - Assert the show endpoint returns `targeted_users` with `is_current_member` and without unexpected user columns
        - _Requirements: 3.1, 3.2, 3.4, 3.5, 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 8.3_

    - [x] 16.2 Employee read path lifecycle
        - Build state through the real admin endpoint, then assert visibility through the real employee endpoints; do not hand-insert pivot rows for the primary flows
        - Assert list, pending-mandatory, and single-exam access agree for the same employee and exam
        - Remove the employee from the workplace through the real workplace endpoint and assert access is then revoked while their existing result stays readable
        - Seed results left over from a previous lifecycle and assert the new targeting selects and ignores the right rows
        - Cover the workplace-less employee across all three decision points
        - _Requirements: 5.6, 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 8.1, 8.2_

    - [x] 16.3 Notification recipients
        - Named employees are recipients; non-named members of the same workplace are not
        - A named employee who left every targeted workplace is not a recipient
        - Workplace-less employees are not recipients, including for a legacy exam
        - Existing paper-status, disabled, and already-satisfied filters still apply
        - Add the two-direction consistency test: over a shared fixture set, the recipient set equals the set the audience scope qualifies, minus the notification-only filters
        - _Requirements: 7.1, 7.2, 7.3, 7.4_

    - [x] 16.4 Transaction failure
        - Force a failure after `exam_user` rows are written during update and assert the previous targeting, workplaces, and questions are all intact
        - _Requirements: 3.7_

- [x] 17. Frontend tests
    - [x] 17.1 `exam_form.test.tsx`
        - The form opens in "whole workplaces" mode with no selector rendered
        - Choosing "named employees" reveals the selector; the option is disabled while no workplace is selected
        - Choosing "named employees" and naming nobody blocks submission
        - Switching back to "whole workplaces" submits `targeted_users: []` even after employees were named
        - The selector queries with `workplace_ids[]` covering every selected workplace
        - The payload carries `targeted_users` as a flat id array and does not carry `delivery_mode`
        - Editing an exam with named employees opens in "named employees" mode with visible labels; one without opens in "whole workplaces" mode
        - An entry with `is_current_member: false` renders with the stale marker
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 3.4, 3.5_

- [ ] 18. Checkpoint - Full suites
    - Backend `docker compose exec php php artisan test --parallel` and frontend `pnpm test` both green. Report anything left uncovered and why.

### Phase 8: Final Validation

- [ ] 19. Code Quality
    - [ ] 19.1 Backend: run `npm run prettier` and `npm run pint` in `./backend`
    - [x] 19.2 Frontend: run `pnpm lint:fix`, then `pnpm prettier`, then `pnpm typecheck` in `./frontend`
    - [x] 19.3 Confirm no user-facing string was hardcoded and no locale JSON was hand-edited

## Notes

- **Phase 0 is settled; it stays in the file as the record of why.** Both decisions changed the table shape or removed access from real accounts, and both are the kind of thing a reader will otherwise re-litigate mid-implementation.
- **Delivery_Mode lives in the form, not the API.** The admin says which audience they want; the server still infers it from whether `targeted_users` is empty. That is why "named employees with nobody named" must be blocked client-side (task 13.3) — it is indistinguishable from "whole workplaces" once it reaches the API, and the API's reading of an empty list as All_Members_Mode is what keeps existing clients working.
- **Ordering rationale.** Storage precedes the write path because the sync action has nothing to write into otherwise; the audience rule precedes its consumers because rewiring four call sites onto an unproven rule would make failures ambiguous. The rule's tests sit in the same phase as the rule, ahead of the general testing phase, because that is where the feature's real risk lives.
- **Why validation exempts saved pairs.** A named employee who later leaves the workplace would otherwise make the exam unsaveable: the form restores the chip (3.4) and the membership check rejects it (4.3), so an admin editing only the title would be blocked with a confusing error. The exemption is safe because a stale link grants nothing — membership is re-derived at query time — and it never applies on create. Task 16.1 covers this end to end.
- **Why stale entries are shown rather than dropped.** Silently dropping them would leave the selector empty, and an empty selector means "everyone" — so an exam that currently reaches nobody would silently start reaching an entire workplace on the next save.
- **Deliberate behaviour change.** Employees belonging to no workplace lose access they hold today under the pending-mandatory list, direct exam access, and notifications. Existing tests asserting the old behaviour will fail in task 10 and must be updated as intended changes, with the reasoning recorded, not quietly relaxed.
- **Accepted limitation.** Employees added to an exam's targeting after its activation notification has already been sent receive no push notification, because the exam is flagged as notified once. They still see the exam in the app. This matches current behaviour for workplace targeting and is out of scope here.
- **Untested by choice.** The migration itself carries no data transformation, so it gets no dedicated migration test; the table is exercised through the write-path and lifecycle tests instead.
- **Conventions.** PHP classes are imported at the top of the file, business logic goes through the Action pattern, and every test method in a PHPUnit class uses camelCase, including helpers and `setUp`.
