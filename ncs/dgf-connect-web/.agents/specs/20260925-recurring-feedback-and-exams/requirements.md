# Requirements Document

## Introduction

Feedback polls and exams are currently answered once per employee: a Feedback response is unique per `(user_id, poll_id)`, and a required exam stops appearing as soon as the employee has passed it once. Workplaces want to re-check employees periodically, so this feature adds a **Recurring** flag to Feedback polls and assessment exams. A recurring item is re-issued to an employee at a workplace on a cadence driven by that employee's shifts at that workplace: on the first shift, then on the 10th shift after the previous occurrence, and also whenever a shift comes more than 30 days after the previous shift. The trigger is evaluated as soon as a shift is approved, so an approved future shift can make the item pending immediately, and the item then shows in the existing mandatory Home popup flow of the employee app until it is completed.

Vote polls (`PollType::Vote`) and external-form exams (`ExamType::ExternalFormPrep`) are explicitly out of scope and keep their current behaviour. There are no breaking API changes for the employee app: the backend resolves the current cycle server-side and keeps the existing `pending_votes`, `pending_exams`, respond, start, submit and result contracts.

### Feature Summary

Admins can mark a workplace-targeted Feedback poll or a workplace-linked assessment exam as Recurring, which re-issues it to each employee per workplace on the 1st shift, on every 10th shift after the previous occurrence, or after a gap of more than 30 days, and lets admins filter statistics and results by occurrence.

### Business Value

- Workplaces can periodically collect feedback and re-verify knowledge from employees who keep working there, without admins re-creating the same poll or exam.
- Re-verification is tied to actual work at the workplace, so employees who return after a long break are re-checked before or right at the start of their next shift.
- Admins can compare answers and scores across occurrences (1st time, 2nd time, …) to see trends per workplace.

### Scope

**In scope:**

- `is_recurring` flag on polls (Feedback type, workplace-targeted only) and assessment exams (with at least one workplace).
- Recurring Feedback polls and recurring exams are always required and have no end date.
- Server-side trigger evaluation per employee, per workplace, per item, computed at read time from shift data.
- Recording which trigger shift, which Anchor position and which occurrence each Feedback response and exam result belongs to.
- Treating existing completions as the first occurrence when an item with prior completions is switched to Recurring.
- Employee API behaviour: pending lists, poll respond, exam list/detail/start/submit/result resolve the current cycle.
- Admin web: Recurring checkbox on the Feedback poll form and on the exam form; occurrence filter and occurrence column on poll responses, exam statistics, exam result list and exam result export.
- Backend and frontend test coverage.

**Out of scope:**

- Vote polls.
- External-form exams (`external_form_prep`).
- Recurring for global or specific-user Feedback polls, and for exams without workplaces.
- Push notifications for recurring occurrences after the first activation.
- Any change to the employee mobile app (`dgf-connect-app`).
- Changes to the 15-day shift activity eligibility rule for exams.
- Configurable cadence (the interval of 10 shifts and the 30-day gap are fixed).

## Glossary

| Term | Definition |
|------|------------|
| Recurring_Item | A Feedback poll or an assessment exam with `is_recurring = true` |
| Feedback_Poll | A poll with `type = feedback` |
| Vote_Poll | A poll with `type = vote`; never recurring |
| Assessment_Exam | An exam with `type = assessment` or the legacy `type = null`; the only exam type that can be recurring |
| Item_Workplace | A workplace the Recurring_Item applies to: the poll's `workplace_id`, or each workplace linked to the exam |
| Approved_Shift | A `shift_users` row with `status = approved` and `is_absent = false` for a shift at the Item_Workplace |
| Worked_Shift | An Approved_Shift that has a check-in with a non-null `check_out_time` |
| Upcoming_Shift | An Approved_Shift that is not a Worked_Shift and whose shift date is today or later (application timezone) |
| Countable_Shift | A Worked_Shift or an Upcoming_Shift. Approved shifts in the past that were never worked are not countable |
| Anchor | The position recorded when the employee completed the latest occurrence of the Recurring_Item at the Item_Workplace: the later of that occurrence's Trigger_Shift and the latest Worked_Shift at completion time. None if never completed |
| Trigger_Shift | A Countable_Shift that starts a new occurrence, as defined by the Trigger_Rule |
| Gap_Reference | For a shift S, the later of the Anchor date and the date of the latest Countable_Shift before S |
| Trigger_Rule | Walking Countable_Shifts after the Anchor in shift start order, a shift S is a Trigger_Shift if (a) there is no Anchor and no previous Trigger_Shift, or (b) exactly 9 Worked_Shifts lie strictly between the previous Trigger_Shift (or the Anchor) and S, or (c) S is more than 30 days after its Gap_Reference |
| Current_Trigger | The latest Trigger_Shift after the Anchor, if any. The item is pending only for the Current_Trigger |
| Occurrence | The 1-based ordinal of a completion of a Recurring_Item by one employee at one Item_Workplace (1st time, 2nd time, …) |
| Completion | For a Feedback_Poll, a saved response; for an Assessment_Exam, a result with `score >= min_score` |
| Legacy_Completion | A Completion recorded before the item became recurring, which has no workplace |
| Recurrence_Resolver | The backend component that evaluates the Trigger_Rule and returns the Current_Trigger |
| Pending_Payload | The `pending_votes` and `pending_exams` arrays returned by the auth check endpoint and consumed by the employee app Home popup |
| Admin_Form | The admin web create/edit forms for polls and exams |

## Requirements

### Requirement 1: Recurring Flag on Feedback Polls

**User Story:** As an admin, I want to mark a workplace Feedback poll as recurring, so that employees at that workplace answer it again periodically.

#### Acceptance Criteria

1. WHEN an admin creates or updates a poll with `is_recurring = true` AND `type = feedback` AND `target_type = workplace` THEN Poll_API SHALL persist `is_recurring = true`, `is_required = true` and `expires_at = null`
2. WHEN a poll whose effective `is_recurring` is true is saved with `is_required = false` or with an `expires_at` THEN Poll_API SHALL store `is_required = true` and `expires_at = null`, where the effective value is the submitted one or else the stored one
3. IF `is_recurring = true` AND `type = vote` THEN Poll_API SHALL reject the request with a 422 validation error on `is_recurring`
4. IF the effective `is_recurring` is true AND the effective `target_type` is not `workplace` THEN Poll_API SHALL reject the request with a 422 validation error on `is_recurring`
5. WHEN `is_recurring` is omitted on create THEN Poll_API SHALL default it to `false`
6. WHEN a poll is returned in the admin list or detail THEN Poll_API SHALL include `is_recurring`
7. WHERE the poll type selected in the Admin_Form is Feedback AND the target is a workplace THEN Admin_Form SHALL show a "Recurring" checkbox; otherwise it SHALL hide the checkbox and submit `is_recurring = false`
8. WHILE the "Recurring" checkbox is checked in the Admin_Form THEN Admin_Form SHALL check and disable the "Required" checkbox, and SHALL clear and disable the expiry date field
9. WHEN a poll is active or has responses THEN Poll_API SHALL still allow `is_recurring` to be changed; it is not a locked field

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None

### Requirement 2: Recurring Flag on Exams

**User Story:** As an admin, I want to mark an assessment exam as recurring, so that employees at its workplaces take it again periodically.

#### Acceptance Criteria

1. WHEN an admin creates or updates an Assessment_Exam with `is_recurring = true` AND at least one workplace THEN Exam_API SHALL persist `is_recurring = true`, `is_required = true` and `end_at = null`
2. IF `is_recurring = true` AND the exam has no workplace THEN Exam_API SHALL reject the request with a 422 validation error on `is_recurring`
3. WHEN `is_recurring = true` is submitted with `is_required = false` or with an `end_at` THEN Exam_API SHALL store `is_required = true` and `end_at = null`
4. WHEN `is_recurring` is omitted THEN Exam_API SHALL default it to `false`
5. WHEN an exam is returned in the admin list or detail THEN Exam_API SHALL include `is_recurring`
6. WHILE the "Recurring" checkbox is checked in the Admin_Form THEN Admin_Form SHALL check and disable the "Required" checkbox, and SHALL clear and disable the end date field
7. IF `is_recurring = true` AND the effective exam type is `external_form_prep` THEN Exam_API SHALL reject the request with a 422 validation error on `is_recurring`
8. WHERE the exam type selected in the Admin_Form is `external_form_prep` THEN Admin_Form SHALL hide the "Recurring" checkbox and submit `is_recurring = false`

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None

### Requirement 3: Trigger Evaluation

**User Story:** As a workplace manager, I want recurring items to reappear on the 1st shift, on every 10th shift after the previous occurrence, and after a long break, so that employees are re-checked at the right time.

#### Acceptance Criteria

1. WHEN an employee has no Anchor for a Recurring_Item at an Item_Workplace AND has at least one Countable_Shift there THEN Recurrence_Resolver SHALL return a Current_Trigger
2. WHEN a Countable_Shift S has exactly 9 Worked_Shifts strictly between the previous Trigger_Shift (or Anchor) and S THEN Recurrence_Resolver SHALL treat S as a Trigger_Shift
3. WHEN a Countable_Shift S starts more than 30 days after its Gap_Reference THEN Recurrence_Resolver SHALL treat S as a Trigger_Shift and restart the 10-shift count from S
4. WHEN an Upcoming_Shift is approved and satisfies criterion 2 or 3 THEN Recurrence_Resolver SHALL return it as the Current_Trigger from the moment of approval, before the shift date
5. IF an Approved_Shift in the past has no completed check-in THEN Recurrence_Resolver SHALL exclude it from counting and from the Gap_Reference
6. IF a shift is absent, rejected or deleted THEN Recurrence_Resolver SHALL exclude it
7. WHEN several Trigger_Shifts exist after the Anchor THEN Recurrence_Resolver SHALL return only the latest one, so the employee completes the item once and the occurrence number advances by one
8. WHEN an Upcoming_Shift that was the Current_Trigger stops being countable before any Completion THEN Recurrence_Resolver SHALL recompute the Current_Trigger without it
9. WHERE an employee works at several Item_Workplaces of the same Recurring_Item THEN Recurrence_Resolver SHALL evaluate each workplace independently
10. WHEN the Trigger_Shift is shift 1, shifts 2–9 are Worked_Shifts and two Upcoming_Shifts A and B (A before B) are approved THEN Recurrence_Resolver SHALL treat neither A nor B as a Trigger_Shift, and SHALL treat B as the Trigger_Shift once A becomes a Worked_Shift
11. WHEN Upcoming_Shifts are approved in advance with no more than 30 days between consecutive Countable_Shifts THEN Recurrence_Resolver SHALL NOT treat any of them as a Trigger_Shift through criterion 3, even when they are more than 30 days after the latest Worked_Shift
12. WHEN a Completion is recorded after the employee has worked shifts past its Trigger_Shift THEN the Anchor SHALL be the latest Worked_Shift at completion time, so the next 10-shift count starts from where the employee is and not from the Trigger_Shift
13. WHEN an item becomes recurring for an employee who already has Countable_Shifts at the Item_Workplace and has no Legacy_Completion THEN Recurrence_Resolver SHALL return a Current_Trigger immediately, and after Completion the Anchor SHALL follow criterion 12

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirements 1, 2, 7

### Requirement 4: Recurring Feedback in the Employee Flow

**User Story:** As an employee, I want recurring feedback to appear in my Home popup when it is due, so that I know I must answer it again.

#### Acceptance Criteria

1. WHEN the auth check endpoint is called AND a recurring Feedback_Poll has a Current_Trigger for the employee THEN Auth_API SHALL include the poll in `pending_votes` and set `has_pending_votes = true`
2. WHEN a recurring Feedback_Poll has no Current_Trigger for the employee THEN Auth_API SHALL NOT include it in `pending_votes`
3. WHEN the employee responds to a recurring Feedback_Poll with a Current_Trigger THEN Poll_Response_API SHALL save the response with the poll's workplace, the Current_Trigger shift, the Anchor position and the next Occurrence
4. IF the employee responds to a recurring Feedback_Poll without a Current_Trigger THEN Poll_Response_API SHALL reject it with the existing `poll.already_responded` error
5. WHEN a response to a recurring Feedback_Poll is saved THEN Poll_Response_API SHALL recompute `has_pending_votes` for the employee
6. WHILE a poll is inactive THEN Auth_API SHALL NOT include it regardless of Current_Trigger
7. WHEN a recurring Feedback_Poll is pending THEN Auth_API SHALL serialize it with `is_required = true`, so the employee app shows no skip action and the poll reappears on every app launch until answered
8. WHERE the poll is a Vote_Poll or a non-recurring Feedback_Poll THEN Poll_Response_API and Auth_API SHALL keep the current one-response-per-employee behaviour

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 1, 3, 7

### Requirement 5: Recurring Exams in the Employee Flow

**User Story:** As an employee, I want recurring exams to appear as required exams when they are due, so that I complete the periodic check.

#### Acceptance Criteria

1. WHEN the auth check endpoint is called AND a recurring exam has a Current_Trigger at any Item_Workplace for the employee AND the existing audience and 15-day eligibility rules pass THEN Auth_API SHALL include the exam in `pending_exams`
2. WHEN the employee has a Completion bound to the Current_Trigger of every Item_Workplace THEN Auth_API SHALL NOT include the exam in `pending_exams`
3. WHEN the employee starts a recurring exam THEN Exam_API SHALL resume the in-progress attempt of a workplace that still has a Current_Trigger if one exists; otherwise it SHALL create an attempt bound to the workplace and next Occurrence of the earliest Current_Trigger
4. WHEN a recurring exam result reaches a Completion THEN Exam_API SHALL record the Completion only for the result's workplace, against that workplace's Current_Trigger resolved at that moment, so an attempt started before a newer trigger satisfies the newest one
5. WHILE the employee still has a Current_Trigger at another Item_Workplace after a Completion THEN Auth_API SHALL keep the exam in `pending_exams` and the next attempt SHALL belong to that workplace
6. WHEN the exam list, detail or result endpoint returns a recurring exam THEN Exam_API SHALL return `result` and `retry_count` computed from results of the active round only, or `result = null` and `retry_count = 0` when the active round has no result
7. IF a recurring exam has no Current_Trigger AND the employee has completed it before THEN Exam_API SHALL return the latest Completion as `result` and a `retry_count` computed from the attempts of that Completion's round only
8. WHEN an employee fails a recurring exam attempt THEN Exam_API SHALL allow unlimited retries within the Current_Trigger, as for other required exams, and SHALL carry over correct answers only from attempts of the same round
9. WHEN the employee submits a recurring exam attempt that is still in progress THEN Exam_API SHALL accept it even if its Current_Trigger has disappeared, and SHALL record no Completion when its workplace has no Current_Trigger at that moment
10. WHERE the exam is recurring THEN Auth_API and Exam_API SHALL ignore `end_at`, as for other required exams
11. IF a recurring exam has no Current_Trigger at any Item_Workplace AND the employee has no Completion AND no in-progress attempt THEN Exam_API SHALL leave it out of the exam list, so no Start action is offered that the backend would reject
12. WHEN a recurring exam is first activated THEN Exam_Notification_Service SHALL send the existing activation push, and SHALL NOT send pushes for later occurrences

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirements 2, 3, 7

### Requirement 6: Enabling Recurring on Items with History

**User Story:** As an admin, I want switching an existing item to recurring to respect answers already given, so that employees are not forced to redo it immediately.

#### Acceptance Criteria

1. WHEN a Recurring_Item has a Legacy_Completion for an employee THEN Recurrence_Resolver SHALL use as Anchor the latest Worked_Shift at the Item_Workplace whose shift date is on or before the Legacy_Completion date (`poll_responses.created_at`, or `exam_results.completed_at`)
2. IF no Worked_Shift exists on or before the Legacy_Completion date at that workplace THEN Recurrence_Resolver SHALL treat the employee as having no Anchor there, and the next Occurrence SHALL be 2
3. WHEN occurrences are counted THEN Legacy_Completion SHALL count as Occurrence 1
4. WHEN an admin turns `is_recurring` off THEN the item SHALL revert to one-time behaviour, where any existing Completion satisfies it
5. WHEN an admin turns `is_recurring` on again THEN existing recurring Completions SHALL keep their Anchor and Occurrence

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirements 3, 7

### Requirement 7: Occurrence Tracking

**User Story:** As an admin, I want each response and result to record which occurrence it belongs to, so that answers can be analysed per round.

#### Acceptance Criteria

1. WHEN a response or result is saved for a Recurring_Item THEN the System SHALL store its `workplace_id` and `occurrence`
2. WHEN a Completion of a Recurring_Item is saved THEN the System SHALL store a completion record with the employee, item, workplace, Occurrence, the Trigger_Shift id, and the Anchor position (shift id, date, start time, tie-break id)
3. WHEN a response or result is saved for a non-recurring item THEN the System SHALL store `workplace_id = null` and `occurrence = 1`
4. IF two concurrent requests try to save a response for the same employee, poll and Occurrence THEN the Database SHALL accept only one
5. WHEN the Trigger_Shift or Anchor shift row is deleted or stops being countable THEN the System SHALL keep the completion record with its stored position and Occurrence, so the Anchor does not change

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None

### Requirement 8: Admin Filtering by Occurrence

**User Story:** As an admin, I want to filter feedback responses and exam statistics by occurrence, so that I can compare the 1st round with later rounds.

#### Acceptance Criteria

1. WHEN an admin opens responses of a Feedback_Poll THEN Poll_API SHALL accept an optional `occurrence` filter and return `occurrence` and workplace for each response
2. WHEN an admin opens statistics of an exam THEN Exam_API SHALL accept an optional `occurrence` filter applied to every statistic; for a recurring exam the workplace filter SHALL match the result's `workplace_id` (or the employee's membership for results without a workplace), and the latest attempt SHALL be taken per employee per workplace
3. WHEN an admin lists or exports exam results THEN Exam_Result_API SHALL accept an optional `occurrence` filter and include occurrence and workplace name columns
4. WHEN no `occurrence` filter is given THEN the APIs SHALL return all occurrences
5. WHERE the poll or exam is recurring OR its `available_occurrences` contains a value greater than 1 THEN Admin_Web SHALL show an occurrence selector listing `available_occurrences`, and an occurrence column, on the poll results page and on the exam statistics
6. WHERE the condition in criterion 5 does not hold THEN Admin_Web SHALL hide the occurrence selector and column on those pages
7. WHEN the admin opens the exam result list, which spans all exams THEN Admin_Web SHALL always show the occurrence filter and the occurrence and workplace columns

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 7

## Non-Functional Requirements

### Performance Requirements
- WHEN the auth check endpoint evaluates recurring items THEN Recurrence_Resolver SHALL use a bounded number of queries per request, independent of the employee's shift count and of the number of recurring items
- WHEN an employee has 1,000 shifts at a workplace THEN evaluation SHALL add less than 100 ms to the auth check endpoint

### Security Requirements
- WHEN admin endpoints accept `is_recurring` or `occurrence` THEN they SHALL require the existing poll and exam permissions
- WHEN an employee responds or submits THEN the backend SHALL derive the workplace, Trigger_Shift, Anchor and Occurrence server-side and SHALL ignore any client-provided value

### Usability Requirements
- WHEN an admin hovers the Recurring checkbox THEN Admin_Form SHALL show a translated explanation of the cadence (1st shift, every 10 shifts after the previous time, gaps over 30 days)
- WHEN admin UI text is added THEN it SHALL be translated through `t(...)` in `en`, `vi` and `ja`

### Reliability Requirements
- WHEN shift data changes (approval, rejection, absence, check-out, deletion) THEN the next auth check SHALL reflect the change without any job or hook
- WHEN a save fails mid-transaction THEN the System SHALL leave no response or result bound to a Trigger_Shift and no completion record

## Constraints and Assumptions

### Technical Constraints
- Laravel 13, PHP 8.5, MySQL; business logic in Action classes; tests in camelCase.
- The employee app reads pending items only at cold start (`/auth/check`); resume does not refresh them.
- `poll_responses` currently has a unique index on `(user_id, poll_id)` that must be replaced without losing one-time guarantees for Vote and non-recurring Feedback polls.
- Shift start order is determined by `shifts.date` plus the workplace shift start time; ties are broken by `shift_users.id`.
- A poll's `type` cannot be changed on update; `target_type` and `workplace_id` are locked once the poll is active or has responses.

### Assumptions
- "More than 30 days" means the calendar-day difference between shift dates is greater than 30.
- The application timezone is used for "today" when classifying Upcoming_Shifts.
- A poll targets exactly one workplace when `target_type = workplace`.
- Admins accept that a Current_Trigger may appear and disappear while its Upcoming_Shift is approved and later rejected or removed.
- The 15-day eligibility rule is evaluated per exam across all of its workplaces, not per triggering workplace.
- The activation push of a recurring exam and the poll activation flag reach the whole audience, including employees who have no Current_Trigger yet.
- A shift deleted and re-created with the same date and start time after the Anchor is counted as a new shift, because ties are broken by `shift_users.id`.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] Backend lifecycle tests reach every state through the real flow: 1st shift, 11th shift, gap over 30 days with count restart, approved future trigger, future trigger later not worked, a month of shifts approved in advance without a false gap trigger, late completion moving the Anchor, item enabled for an employee with long history, out-of-order approvals, missed occurrences, two workplaces including an in-progress attempt when the other workplace gets an earlier trigger, in-progress attempt when a new trigger arrives, trigger removed during an attempt, Legacy_Completion, and Vote polls unchanged
- [ ] Frontend tests cover the Recurring checkbox rules and the occurrence filters
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `npm run prettier` and `npm run pint` pass
