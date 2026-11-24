# Requirements Document

## Introduction

Today an exam can only be targeted at whole workplaces: the admin picks one or more workplaces on the exam form, and every approved employee of those workplaces receives the exam. There is no way to send an exam to a handful of named employees. This feature adds an explicit delivery-mode choice to the exam form: **whole workplaces** (the default, behaving exactly as today) or **named employees**, which reveals an employee selector and restricts the exam to the employees named there. Workplace selection remains the entry point in both modes, so every named employee is guaranteed to be a member of a selected workplace. No existing exam changes behaviour, and no existing user loses or gains access, apart from the deliberate correction in Requirement 8.4.

### Feature Summary

Allows an admin creating or updating an exam to switch delivery from "every member of the selected workplaces" to "only these named employees".

### Business Value

- Lets admins run exams for a small group (for example shift leaders, or employees who failed a prior assessment) without spamming an entire workplace.
- Avoids the current workaround of creating throwaway workplaces just to reach a subset of employees.
- Keeps workplace-wide delivery as the zero-effort default, so existing admin habits and existing exams are unaffected.

### Scope

**Included:**
- An explicit delivery-mode control on the admin exam create/update form, with an employee selector revealed only in "named employees" mode.
- Storage of the named-employee selection (`exam_user`: exam, user).
- Restriction of the employee selector to current members of the selected workplaces, enforced both in the picker and by server-side validation.
- Extension of every exam-visibility decision point (employee exam list, pending required exams, single-exam access check, FCM activation-notification recipients) to honour the named-employee selection.
- Restoration of the saved selection when an existing exam is reopened for editing, including employees who have since left every selected workplace, which are shown as stale.
- Backward compatibility for exams that target no workplace at all (visible to everyone) and exams that target workplaces with no named employees (visible to every member).
- Alignment of all four decision points on employees who belong to no workplace: such employees are denied every exam. This corrects a pre-existing inconsistency and is a deliberate behaviour change (see Requirement 8.4).
- A read exception so an employee who already has a result for an exam keeps access to that result even after being removed from the exam's audience (Requirement 6.6).

**Excluded:**
- Per-workplace targeting modes within a single exam ("workplace A in full plus two people from workplace B"). See Design Decision Record 1; reaching that audience requires two exams.
- Re-sending or supplementing the FCM activation notification after an exam's targeting is edited (see Constraints and Assumptions).
- Targeting employees who belong to no workplace, including admin-only accounts.
- Targeting by role, group, or any criterion other than workplace membership.
- Any change to the Document feature, which has its own independent user-targeting mechanism with the opposite semantics (see Requirement 9).
- Any change to the mobile-facing API response shape.
- Any indication on the admin exam **list** that an exam is limited to named employees; the limitation is visible on the exam detail form only.
- An admin-list filter for "exams targeted at employee X".

## Glossary

| Term | Definition |
|------|------------|
| Exam_Form | The admin web form at `/manage/exam/create` and `/manage/exam/{id}/edit` used to create and update an exam |
| Workplace_Target | A workplace attached to an exam through the existing `exam_workplace` pivot |
| Delivery_Mode | The two-option control on Exam_Form choosing between "whole workplaces" and "named employees". A form-only concept: it is never sent to or stored by the backend, which derives the same distinction from whether any employee is named |
| Employee_Selector | The multi-select control on Exam_Form, shown only in "named employees" Delivery_Mode, used to name specific employees |
| Targeted_Employee | An employee explicitly named in the Employee_Selector of an exam |
| Exam_User_Link | A stored association of one exam and one Targeted_Employee (`exam_user` table) |
| All_Members_Mode | The state of an exam that has no Exam_User_Link, meaning it applies to every member of every Workplace_Target |
| Named_Members_Mode | The state of an exam that has at least one Exam_User_Link, meaning it applies only to those employees |
| Legacy_Untargeted_Exam | An exam with no Workplace_Target at all, which applies to every employee who belongs to at least one workplace |
| Stale_Link | An Exam_User_Link whose employee is no longer a Workplace_Member of any Workplace_Target of that exam |
| Pre_Existing_Stale_Link | A Stale_Link measured against the exam's **saved** Workplace_Target set, i.e. one that was already unreachable before the current request. Distinct from a link that only becomes stale *because of* the workplaces the request submits |
| Exam_Visibility_Rule | The single shared rule that decides whether a given employee is an audience member of a given exam |
| Exam_List_Action | The backend action serving the employee-facing exam list (`App\Actions\User\Exam\GetExamsAction`) |
| Pending_Exam_Action | The backend action listing outstanding mandatory exams (`App\Actions\Common\Exam\GetPendingRequiredExamsAction`) |
| Exam_Access_Service | The backend service method authorising access to a single exam (`App\Services\User\ExamService::canUserViewExam`) |
| Exam_Notification_Service | The backend service resolving FCM recipients for an activated mandatory exam (`App\Services\ExamNotificationService`) |
| Exam_Target_Validator | The request-level validation of the exam targeting payload (`App\Http\Requests\Admin\Exam\CreateUpdateExamRequest`) |
| Workplace_Member | A user with a row in `workplace_users` for the workplace in question |

## Requirements

### Requirement 1: Delivery Mode and Named-Employee Selection

**User Story:** As an admin creating an exam, I want to state explicitly whether the exam goes to whole workplaces or to named employees, so that I am never guessing what an empty field means.

#### Acceptance Criteria

1. WHEN the admin opens Exam_Form THEN Exam_Form SHALL render a Delivery_Mode control beneath the workplace field offering "whole workplaces" and "named employees", defaulting to "whole workplaces"
2. WHERE Delivery_Mode is "whole workplaces" THEN Exam_Form SHALL NOT render the Employee_Selector and SHALL send an empty `targeted_users` payload, producing All_Members_Mode
3. WHERE Delivery_Mode is "named employees" THEN Exam_Form SHALL render the Employee_Selector beneath the Delivery_Mode control, producing Named_Members_Mode
4. IF Delivery_Mode is "named employees" AND the Employee_Selector is empty THEN Exam_Form SHALL block submission with a validation message asking for at least one employee, rather than submitting an empty list
5. IF no workplace is selected THEN Exam_Form SHALL disable the "named employees" option and SHALL explain that a workplace must be chosen first
6. WHEN the admin switches Delivery_Mode from "named employees" to "whole workplaces" THEN Exam_Form SHALL discard the named employees from the payload
7. WHEN the admin submits Exam_Form THEN Exam_Form SHALL send `targeted_users` as a flat list of user ids, empty in "whole workplaces" mode
8. WHERE Delivery_Mode is "named employees" THEN Exam_Form SHALL display an audience summary line stating that the exam goes only to the named employees

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None
- **Note on 1.4**: The backend cannot tell "whole workplaces" from "named employees with nobody named" — both arrive as an empty `targeted_users`. That ambiguity is resolved in the form, which is where the admin's intent exists. An empty list reaching the API is always interpreted as All_Members_Mode (Requirement 4.6), so the API contract needs no delivery-mode field.

### Requirement 2: Employee Selector Scoped to Workplace Membership

**User Story:** As an admin, I want the employee selector to offer only employees of the workplaces I selected, so that I cannot accidentally target someone outside them.

#### Acceptance Criteria

1. WHEN the admin types a search term into the Employee_Selector THEN Employee_Selector SHALL return only users who are a Workplace_Member of at least one currently selected Workplace_Target
2. WHEN the admin types a search term into the Employee_Selector THEN Employee_Selector SHALL match the term against employee code, first name, last name, and email, and SHALL require at least three characters before querying
3. IF a user belongs to no workplace THEN Employee_Selector SHALL NOT offer that user
4. IF a user has been removed from every selected workplace THEN Employee_Selector SHALL NOT offer that user
5. WHEN the admin changes the workplace selection THEN Employee_Selector SHALL re-scope its search to the new selection and SHALL keep already-named employees in place, leaving them to be accepted or rejected by Exam_Target_Validator on submit

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1
- **Note**: 2.2 describes the behaviour of the existing admin user-search endpoint, which matches `first_name` and `last_name` independently rather than the concatenated display name. Searching a full name such as "Nguyen Van A" therefore returns nothing. This is accepted for this feature; changing it would alter search behaviour for every other consumer of that endpoint.

### Requirement 3: Persistence and Restoration of Targeting

**User Story:** As an admin, I want my employee selection to be saved and shown again when I reopen the exam, so that editing an exam does not silently wipe out its targeting.

#### Acceptance Criteria

1. WHEN an exam is created with a targeting payload THEN the system SHALL persist one Exam_User_Link per `{exam, employee}` pair
2. WHEN an exam is updated with a targeting payload THEN the system SHALL replace that exam's Exam_User_Link set so it matches the submitted payload exactly
3. WHEN every Workplace_Target is removed from an exam THEN the system SHALL reject the save if the payload still names employees, and SHALL persist no Exam_User_Link if it does not
4. WHEN the admin reopens an existing exam for editing THEN Exam_Form SHALL set Delivery_Mode to "named employees" if the exam has at least one Exam_User_Link and to "whole workplaces" otherwise, and SHALL repopulate the Employee_Selector with the persisted Targeted_Employee list, showing each employee's name and employee code
5. WHEN the admin reopens an existing exam that holds a Stale_Link THEN Exam_Form SHALL still show that employee and SHALL mark them visually as no longer a member of any selected workplace
6. WHEN the admin switches Delivery_Mode to "whole workplaces" on an exam that had named employees and saves THEN the system SHALL delete every Exam_User_Link of that exam, returning it to All_Members_Mode
7. IF exam creation or update fails at any step THEN the system SHALL persist neither the exam changes nor any Exam_User_Link change

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1

### Requirement 4: Server-Side Validation of Targeting

**User Story:** As a system owner, I want the server to reject targeting payloads that name employees outside the selected workplaces, so that a tampered or stale request cannot grant access to unintended employees.

#### Acceptance Criteria

1. WHEN a targeting payload is submitted THEN Exam_Target_Validator SHALL require every entry of `targeted_users` to be an integer referencing an existing user
2. IF `targeted_users` is non-empty AND `workplaces` is empty THEN Exam_Target_Validator SHALL reject the request
3. IF a submitted user id is not a Workplace_Member of any submitted workplace THEN Exam_Target_Validator SHALL reject the request with a validation error naming that employee, rather than silently dropping the entry
4. WHERE a submitted user id is a Pre_Existing_Stale_Link of the exam being updated THEN Exam_Target_Validator SHALL accept it without re-checking workplace membership
4a. IF a submitted user id holds an Exam_User_Link but is still a Workplace_Member of a saved Workplace_Target THEN Exam_Target_Validator SHALL check it against the submitted workplaces like any other entry, and SHALL reject the request when the submitted workplaces exclude that employee
4b. WHERE the exam being updated has no saved Workplace_Target at all THEN Exam_Target_Validator SHALL grant no exemption and SHALL check every submitted user id against the submitted workplaces
5. IF the same user id appears twice THEN Exam_Target_Validator SHALL accept the request and the system SHALL persist a single Exam_User_Link for that employee
6. WHEN the targeting payload is absent or empty THEN Exam_Target_Validator SHALL accept the request and the system SHALL persist no Exam_User_Link
7. WHEN more than one submitted user id fails the membership check THEN Exam_Target_Validator SHALL name every one of them in a single validation message for `targeted_users`

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 3
- **Note on 4.4**: The exemption is what keeps Requirement 8.3 satisfiable. A Pre_Existing_Stale_Link grants no access — Exam_Visibility_Rule re-derives membership at query time (5.6) — so preserving it is harmless, while rejecting it would block the admin from saving any unrelated edit.

  Staleness is measured against the exam's **saved** Workplace_Target set, not against the submitted one, which is what separates 4.4 from 4a. An employee who is reachable today but whom this very request pushes out of scope (saved W1/U1, submitted W2 while the U1 chip is kept) is not a Pre_Existing_Stale_Link: exempting them would save an exam that reaches nobody — the named employee fails membership and every member of W2 is excluded for not being named — and the admin sees no warning, because Exam_Form hides its stale marker as soon as the workplace selection differs from the saved one.

- **Note on 4b**: An exam can hold Exam_User_Link rows with no saved Workplace_Target, because deleting a workplace cascades `exam_workplace` but not `exam_user`. There is then no saved scope for a link to have gone stale against, and 4.2 already rejects the only request that would leave the scope empty — so such a request is always the admin establishing a new scope, exactly when the inherited entries need checking against it.

- **Note on 4.7**: The admin-facing form surfaces only the first error of a field, so one message per offender would reveal them one failed save at a time.

### Requirement 5: Exam Visibility Rule

**User Story:** As an employee, I want to see exactly the exams that were sent to me, so that I neither miss an assigned exam nor see exams meant for other people.

#### Acceptance Criteria

1. IF the employee is a Workplace_Member of no workplace THEN Exam_Visibility_Rule SHALL NOT treat the employee as an audience member of any exam, including a Legacy_Untargeted_Exam
2. IF an exam is a Legacy_Untargeted_Exam AND the employee is a Workplace_Member of at least one workplace THEN Exam_Visibility_Rule SHALL treat the employee as an audience member
3. IF an exam is in All_Members_Mode AND the employee is a Workplace_Member of at least one of its Workplace_Target entries THEN Exam_Visibility_Rule SHALL treat the employee as an audience member
4. IF an exam is in Named_Members_Mode AND the employee holds an Exam_User_Link for it AND the employee is a Workplace_Member of at least one of its Workplace_Target entries THEN Exam_Visibility_Rule SHALL treat the employee as an audience member
5. IF an exam is in Named_Members_Mode AND the employee is a Workplace_Member of one of its Workplace_Target entries but holds no Exam_User_Link THEN Exam_Visibility_Rule SHALL NOT treat the employee as an audience member
6. IF the employee holds an Exam_User_Link but is no longer a Workplace_Member of any Workplace_Target of that exam THEN Exam_Visibility_Rule SHALL NOT treat the employee as an audience member
7. WHERE an exam has several Workplace_Target entries THEN membership in any one of them satisfies the workplace half of the rule

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 3

### Requirement 6: Application of the Visibility Rule at Every Decision Point

**User Story:** As an employee, I want the exam list, the mandatory-exam reminder, and the exam itself to agree on whether an exam is mine, so that I never see an exam I cannot open or miss one I must take.

#### Acceptance Criteria

1. WHEN an employee requests the exam list THEN Exam_List_Action SHALL return exactly the exams for which Exam_Visibility_Rule qualifies that employee
2. WHEN an employee's outstanding mandatory exams are resolved THEN Pending_Exam_Action SHALL return exactly the mandatory exams for which Exam_Visibility_Rule qualifies that employee
3. WHEN an employee opens, starts, or submits a single exam THEN Exam_Access_Service SHALL grant access if and only if Exam_Visibility_Rule qualifies that employee
4. IF an employee is qualified by Exam_Visibility_Rule for an exam THEN Exam_List_Action, Pending_Exam_Action, and Exam_Access_Service SHALL reach the same conclusion for that employee and exam
5. WHERE an employee is a Workplace_Member of no workplace THEN Exam_List_Action SHALL return an empty list, Pending_Exam_Action SHALL return an empty set, and Exam_Access_Service SHALL deny access to every exam, including a Legacy_Untargeted_Exam
6. IF an employee already holds a result for an exam THEN the system SHALL keep that employee's access to their own result for that exam even where Exam_Visibility_Rule no longer qualifies them

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 5
- **Note on 6.6**: `canUserViewExam` currently gates four call sites — opening the exam, starting an attempt, submitting an answer, and reading a result. Without this criterion, narrowing an exam's targeting would take away an employee's access to a result they already earned, which is punitive and has no security rationale. Starting and submitting stay strictly gated, so an attempt that is in progress when the employee is removed still becomes unfinishable, matching current behaviour for workplace removal.

### Requirement 7: Activation Notification Recipients

**User Story:** As a named employee, I want to receive the push notification when a mandatory exam assigned to me activates, so that I learn about it without opening the app.

#### Acceptance Criteria

1. WHEN a mandatory exam activates THEN Exam_Notification_Service SHALL resolve as recipients exactly the employees qualified by Exam_Visibility_Rule for that exam
2. WHILE resolving recipients Exam_Notification_Service SHALL continue to exclude employees whose paper status is not approved, employees who are disabled, and employees who have already satisfied the exam
3. IF an exam is in Named_Members_Mode THEN Exam_Notification_Service SHALL NOT resolve non-named members of its workplaces as recipients
4. IF an employee is a Workplace_Member of no workplace THEN Exam_Notification_Service SHALL NOT resolve that employee as a recipient of any exam, including a Legacy_Untargeted_Exam
5. IF an exam has no qualifying recipient THEN Exam_Notification_Service SHALL report an empty recipient set and the exam SHALL still be marked as notified

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 5

### Requirement 8: Backward Compatibility

**User Story:** As an admin with exams already in production, I want existing exams to keep behaving exactly as before, so that this change introduces no surprise for current recipients.

#### Acceptance Criteria

1. WHERE an exam existed before this feature THEN the system SHALL treat it as All_Members_Mode
2. WHERE an exam existed before this feature with no Workplace_Target THEN the system SHALL continue to treat it as a Legacy_Untargeted_Exam visible to every employee who is a Workplace_Member of at least one workplace
3. WHEN an existing exam is reopened and saved **without changing its Workplace_Target set** and without touching the Employee_Selector THEN the system SHALL leave both its stored Exam_User_Link set and its audience unchanged, including where the exam holds a Pre_Existing_Stale_Link. Where the save does change the Workplace_Target set, 4.4a applies and the inherited entries are re-checked
4. WHERE an employee is a Workplace_Member of no workplace THEN the system SHALL revoke the exam access that employee holds today under Pending_Exam_Action, Exam_Access_Service, and Exam_Notification_Service, as a deliberate correction covered by Requirement 6.5
5. WHEN this feature is deployed THEN the system SHALL require no data migration of existing exam or workplace records
6. WHEN this feature is deployed THEN the mobile-facing exam endpoints SHALL keep their current request and response shapes
7. WHEN an admin API client omits `targeted_users` on update THEN the system SHALL clear all targeting for that exam; partial updates that intend to preserve targeting must resend the field

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirements 5, 6, 7
- **Note on 8.4**: Confirmed with the product owner. Every employee is expected to belong to at least one workplace; leaders belong to several. The only accounts with no workplace are global admins, who do not take exams. The revoked access therefore belongs to nobody who uses it, and this criterion is settled — no further business sign-off is needed.
- **Note on 8.7**: This mirrors how `workplaces` and `documents` already behave on this endpoint, and differs from `external_url`, which is preserved when omitted. The admin web form always sends the field, so the difference only affects other API clients.

### Requirement 9: Distinguishability from Document Targeting

**User Story:** As an admin who also assigns documents, I want the exam employee selector to be unmistakable, so that I do not assume it works the way the document one does.

#### Acceptance Criteria

1. WHERE Exam_Form renders the Delivery_Mode control THEN its two option labels SHALL each name the resulting audience outright — "every employee of the selected workplaces" versus "only the employees named below" — rather than describing the control
2. WHEN Exam_Form renders any label, placeholder, summary, or validation message introduced by this feature THEN it SHALL source the text from the translation layer in every supported language

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 1
- **Rationale**: The Document feature's employee selector is **additive** — a document reaches its named users *in addition to* the members of its workplaces (`GetUserDocumentsAction`). The exam selector is **restrictive**. Two visually identical controls in the same admin area with opposite semantics is the largest usability risk in this feature. The Delivery_Mode control of Requirement 1 is the primary mitigation: an admin must state which audience they want before the selector even appears, so the restrictive meaning is chosen deliberately rather than inferred from an empty field.

## Non-Functional Requirements

### Performance Requirements

- WHEN an employee requests a paginated exam list THEN Exam_List_Action SHALL resolve visibility without issuing a query per exam row
- WHEN Exam_Notification_Service resolves recipients for one exam THEN it SHALL do so in a bounded number of queries independent of the number of targeted employees
- WHEN an employee's visibility is evaluated THEN the system SHALL use an index covering lookups of Exam_User_Link by employee

### Security Requirements

- WHEN a targeting payload names an employee outside the selected workplaces THEN Exam_Target_Validator SHALL reject the request rather than silently dropping the entry, except for the Pre_Existing_Stale_Links of Requirement 4.4
- WHEN visibility is evaluated THEN the system SHALL derive workplace membership from current `workplace_users` data rather than from any value supplied by the client
- WHERE an employee is not qualified by Exam_Visibility_Rule THEN Exam_Access_Service SHALL deny access to the exam and its questions, subject only to the own-result exception of Requirement 6.6

### Usability Requirements

- WHEN Exam_Form loads THEN Delivery_Mode SHALL default to "whole workplaces", so an admin who ignores the control entirely gets today's behaviour
- WHEN the admin searches in the Employee_Selector THEN Exam_Form SHALL display each candidate with both employee name and employee code
- WHERE a named employee is a Stale_Link THEN Exam_Form SHALL make that visible so the admin can decide whether to remove them

### Reliability Requirements

- IF the exam update transaction fails after Exam_User_Link rows are written THEN the system SHALL roll back to the pre-update targeting state
- IF a Targeted_Employee is deleted THEN the system SHALL remove the corresponding Exam_User_Link rows automatically
- IF an exam is deleted THEN the system SHALL remove the corresponding Exam_User_Link rows automatically

## Constraints and Assumptions

### Technical Constraints

- Targeting is expressible only in terms of workplace membership; an employee who belongs to no workplace cannot be targeted.
- Targeting is exam-wide, not per workplace. An audience of "all of workplace A plus two people from workplace B" requires two exams. See Design Decision Record 1 for the condition under which this decision should be revisited.
- The FCM activation notification for a mandatory exam is sent once and the exam is then flagged as notified. Employees added to an exam's targeting **after** that notification has been sent will not receive a push notification; they will still see the exam in the app. This limitation is accepted for this iteration and is unchanged from current behaviour for workplace targeting.
- The employee search reuses the existing admin user-search endpoint, extended with a `workplace_ids` array filter alongside the existing single-`workplace_id` one. No other consumer of that endpoint changes.
- The admin user-search endpoint is gated by the `ManagerAuth` permission, which admins configuring exams already hold — the same picker is already used by the document create/update modals in this admin area.
- Before this feature, an employee belonging to no workplace was denied by Exam_List_Action but still qualified by Pending_Exam_Action, Exam_Access_Service, and Exam_Notification_Service for a Legacy_Untargeted_Exam, so such an employee was reminded about and notified of exams that never appeared in their list. Requirement 6.5 closes this pre-existing inconsistency in the restrictive direction: no workplace, no exam.

### Assumptions

- An employee joining a targeted workplace after an All_Members_Mode exam was created is intended to receive that exam.
- An employee joining a targeted workplace after a Named_Members_Mode exam was created is not intended to receive that exam.
- An employee who leaves every targeted workplace is intended to lose access to that exam, including where they were named; an in-progress attempt may therefore become unfinishable, which matches current behaviour for workplace removal. Their already-earned result stays readable (Requirement 6.6).
- Every employee is expected to belong to at least one workplace, and leaders to several. Accounts with no workplace at all are global admins, who are not exam participants. An employee not yet assigned to a workplace is therefore not intended to take exams, including mandatory company-wide ones; workplace assignment precedes exam participation.
- The number of employees named on a single exam is small (tens, not thousands).

## Design Decision Record

### DDR 1: Exam-wide targeting rather than per-workplace targeting

**Decision**: `exam_user` stores `{exam_id, user_id}` only. Naming any employee restricts the whole exam to the named employees.

**Alternative considered**: `exam_user` storing `{exam_id, workplace_id, user_id}`, giving each workplace its own independent mode, with one selector rendered per selected workplace.

**Why the simpler model won**: the per-workplace model exists only to support a mixed audience within a single exam, which no stated business requirement asks for. Its cost is concentrated in one correlated subquery that must correlate on both `exams.id` and `workplaces.id`; getting that wrong yields a **silently wrong audience** that passes every single-workplace test. The exam-wide model expresses the same rule as flat `EXISTS` clauses. Its cost is a small array filter on the user-search endpoint and the loss of the mixed case.

**What the exam-wide model gives up, concretely**: an exam targeting workplaces A and B, with two employees of A named, reaches **only those two employees** — nobody in B receives it. Under the per-workplace model, all of B would still receive it. The Delivery_Mode control and the audience summary line of Requirement 1 exist to make this unmistakable at the moment the admin chooses.

**Condition to revisit**: switch to the per-workplace model if either check says so.
- Data check: `SELECT COUNT(*) FROM (SELECT exam_id FROM exam_workplace GROUP BY exam_id HAVING COUNT(*) > 1) t` returns a materially non-zero count on production.
- Business check: admins confirm they do create single exams spanning several workplaces **and** would want to narrow only some of them.

Note that "a leader belongs to several workplaces" does not trigger either check — that is about user membership, which both models handle identically through Requirement 5.7. What matters is whether one *exam* spans several workplaces.

That change would affect the migration, the validator, the audience scope, the recipients action, and the form layout — but not the requirement set above other than Scope, Requirement 1, and Requirement 2.

**Status**: **decided — exam-wide model**. The data check could not be run (no database access at spec time). Accepted on the reasoning above, with two mitigations: the Delivery_Mode control of Requirement 1 makes the restrictive semantics explicit at the moment of choosing, and the model is not a one-way door — adding `workplace_id` later is backfillable by expanding each existing row into one row per workplace of that exam that the employee currently belongs to.

## Success Criteria

### Definition of Done

- [x] DDR 1 decided: exam-wide targeting, with an explicit Delivery_Mode control as the mitigation
- [x] The Requirement 8.4 behaviour change is confirmed: only global admins belong to no workplace, and they are not exam participants
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] An exam can be created naming a subset of a workplace and is visible to exactly those employees in the exam list, the pending-mandatory list, and on direct access
- [ ] An exam left in "whole workplaces" Delivery_Mode reaches every member of its workplaces, unchanged from current behaviour
- [ ] Choosing "named employees" and naming nobody is blocked in the form rather than silently sending to everyone
- [ ] An exam with no workplace remains visible to every employee who belongs to at least one workplace
- [ ] An employee belonging to no workplace is denied every exam across the exam list, the pending-mandatory list, direct access, and notification recipients
- [ ] Named employees are included in the activation-notification recipient set, and non-named members of the same workplace are excluded
- [ ] Reopening a saved exam restores its employee selection, marks stale entries, and can be saved untouched without a validation error and without changing the audience
- [ ] An employee removed from an exam's audience keeps access to a result they already earned
- [ ] A payload naming an employee outside the selected workplaces is rejected
- [ ] Backend and frontend test suites pass locally, including a visibility matrix covering All_Members_Mode, Named_Members_Mode, multi-workplace exams, Legacy_Untargeted_Exam, and an employee removed from a workplace after being named
- [ ] Project quality checks pass: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` for the frontend, and `npm run prettier`, `npm run pint` for the backend
