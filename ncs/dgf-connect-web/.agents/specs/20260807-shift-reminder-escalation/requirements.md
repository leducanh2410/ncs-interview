# Requirements Document

## Introduction

Shift attendance currently relies on a single confirmation reminder sent two days before a shift and a check-in reminder sent to the employee fifteen minutes before start. Nothing escalates when an employee ignores those reminders, and no one on the management side is told that a shift is about to run short-staffed. This feature adds a second (final) confirmation reminder to the employee three hours before the shift, two aggregated alerts to the workplace's shift administrators (two hours before start when anyone is still unconfirmed, and ten minutes after start when anyone has still not checked in), and a shortcut on the web admin shift detail screen that lets an administrator filter down to the problem employees and open a one-to-one chat with any of them. There are no breaking changes: existing reminders, notification types, and chat conversation types are preserved as-is.

### Feature Summary

Escalate unconfirmed and missing check-in shift attendance to both the employee and the workplace's shift administrators, and give administrators a one-click path from the shift detail screen to a direct chat with the affected employee.

### Business Value

- Reduces no-shows and late check-ins by giving employees a final, time-proximate prompt instead of a single reminder two days out.
- Gives shift administrators actionable lead time (two hours) to find replacements rather than discovering an unfilled slot at shift start.
- Shortens the time from "administrator notices a problem" to "administrator contacts the employee" from a multi-step manual chat-creation flow to a single button.

### Scope

**Included:**

- A second `CONFIRM_REMINDER` notification to employees whose approved shift starts within a configurable window (default 180 minutes) and who have not confirmed.
- A new aggregated admin notification when a shift starting within a configurable window (default 120 minutes) still has at least one unconfirmed approved employee.
- A new aggregated admin notification when a shift that started a configurable number of minutes ago (default 10) still has at least one approved employee without a check-in.
- Attendance status filtering (unconfirmed / not checked in) on the web admin shift detail employee list.
- A per-row message button on that list that opens an existing one-to-one chat or pre-fills the manual-group creation screen.

**Excluded:**

- Any change to the mobile applications (employee or admin). This specification covers backend and web admin only. The two new administrator notification types render through the clients' existing generic notification handling; building the admin application's filtered shift screen and the navigation into it is mobile work tracked separately.
- Any change to the existing two-day confirmation reminder, the six-hour shift reminder, or the fifteen-minute check-in/check-out reminders.
- A dedicated one-to-one conversation type. One-to-one chats continue to be represented as `admin_group` conversations with exactly two participants.
- Automatic replacement sourcing, shift reassignment, or absence recording triggered by these alerts.

## Glossary

| Term | Definition |
|------|------------|
| Shift_Reminder_Job | The existing `App\Jobs\ShiftReminder` queued job, scheduled every five minutes, that emits the employee-facing shift, check-in, and check-out reminders. |
| Shift_Admin_Alert_Job | A new `App\Jobs\ShiftAdminAlert` queued job, scheduled every five minutes alongside Shift_Reminder_Job, that emits the two aggregated administrator alerts. Kept separate from Shift_Reminder_Job because the alerts are per-shift rather than per-assignment and must not be entangled with that job's early-exit branches. |
| Shift_Confirmation_Reminder_Job | The existing `App\Jobs\ShiftConfirmationReminder` queued job, scheduled daily at 08:57, that emits the two-day-ahead confirmation reminder. |
| Final_Confirmation_Reminder | The second confirmation reminder sent to an employee shortly before the shift, reusing notification type `confirm_reminder`. |
| Unconfirmed_Admin_Alert | An aggregated notification of type `shift_unconfirmed_alert` sent to Workplace_Shift_Admins when a shift still has unconfirmed employees. |
| Missing_Check_In_Admin_Alert | An aggregated notification of type `shift_missing_check_in_alert` sent to Workplace_Shift_Admins when a started shift still has employees without a check-in. |
| Workplace_Shift_Admin | A user holding a role that (a) grants any permission prefixed `shift.` and (b) is attached to the user through `role_user.team_id` equal to the shift's `workplace_id`. Users whose shift access is unscoped (`team_id` NULL) and users holding the `global_admin` role are deliberately excluded. |
| Push_Notification_Job | The existing `App\Jobs\PushNotificationToUser` job, scheduled every minute, that delivers pending `notifications` rows over FCM, routing to the admin Firebase project for users holding `manager.auth`. |
| Assignment_Table | The web admin employee list on the shift detail screen, `frontend/src/pages/manage/shift/partials/assignment_table.tsx`. |
| Attendance_Status_Filter | The new filter control on Assignment_Table restricting rows to unconfirmed or not-checked-in employees. |
| Manual_Group | A chat conversation of type `admin_group`, whose participants are chosen explicitly rather than derived from a shift or user group. |
| Direct_Conversation | A Manual_Group whose active participant set is exactly two users: the acting administrator and one employee. |
| Chat_Intent | The transient client-side instruction that tells the admin chat portal which conversation to open, or which participants to pre-fill on the creation screen. |
| Shift_Window_Start | The absolute start timestamp of a shift, derived by `WorkHelper::getShiftTimes` from the shift date and workplace shift times, correctly handling shifts that cross midnight. |

## Requirements

### Requirement 1: Final Confirmation Reminder to Employee

**User Story:** As an employee who has been approved for a shift, I want a second reminder shortly before the shift when I have not confirmed my attendance, so that I do not miss the shift because the original reminder arrived two days earlier.

#### Acceptance Criteria

1. WHEN Shift_Reminder_Job runs AND an approved shift assignment has no `confirmed_at` AND no Final_Confirmation_Reminder has been recorded AND the minutes remaining until Shift_Window_Start are less than or equal to `work.reminder.confirmation.final_before_minute` THEN Shift_Reminder_Job SHALL create one notification of type `confirm_reminder` addressed to that employee.
2. WHEN Shift_Reminder_Job creates a Final_Confirmation_Reminder THEN Shift_Reminder_Job SHALL persist the send timestamp on the shift assignment so that no further Final_Confirmation_Reminder is created for the same assignment.
3. IF an employee has already recorded `confirmed_at` THEN Shift_Reminder_Job SHALL NOT create a Final_Confirmation_Reminder for that assignment.
4. IF a shift assignment has a status other than approved THEN Shift_Reminder_Job SHALL NOT create a Final_Confirmation_Reminder for that assignment.
5. WHERE a shift crosses midnight Shift_Reminder_Job SHALL evaluate the remaining minutes against Shift_Window_Start rather than against the shift's calendar date.
6. WHEN Shift_Window_Start has already passed THEN Shift_Reminder_Job SHALL NOT create a Final_Confirmation_Reminder for that shift.
7. WHEN a Final_Confirmation_Reminder is created THEN Shift_Reminder_Job SHALL attach a translation row for every locale returned by `Locale::getTranslationLocales()`.
8. IF the two-day Shift_Confirmation_Reminder_Job has never run for an assignment because the shift was approved less than two days before its start THEN Shift_Reminder_Job SHALL still create the Final_Confirmation_Reminder once the final window is entered.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requires a new nullable timestamp column on shift assignments and a new configuration key.

### Requirement 2: Unconfirmed Employee Alert to Shift Administrators

**User Story:** As a shift administrator for a workplace, I want to be alerted while there is still time to act when employees on an upcoming shift have not confirmed attendance, so that I can contact them or arrange a replacement before the shift starts.

#### Acceptance Criteria

1. WHEN Shift_Admin_Alert_Job runs AND the minutes remaining until Shift_Window_Start are less than or equal to `work.reminder.unconfirmed_alert.before_minute` AND at least one approved assignment on that shift has no `confirmed_at` AND no Unconfirmed_Admin_Alert has been recorded for the shift THEN Shift_Admin_Alert_Job SHALL create one notification of type `shift_unconfirmed_alert` per Workplace_Shift_Admin.
2. WHEN Shift_Admin_Alert_Job creates an Unconfirmed_Admin_Alert THEN Shift_Admin_Alert_Job SHALL emit exactly one notification per shift per administrator regardless of how many employees are unconfirmed.
3. WHEN Shift_Admin_Alert_Job creates an Unconfirmed_Admin_Alert THEN the notification content SHALL state the workplace name, the shift start time, and the count of unconfirmed employees.
4. WHEN Shift_Admin_Alert_Job creates an Unconfirmed_Admin_Alert THEN the notification `data` payload SHALL include `shift_id`, `workplace_id`, and the list of unconfirmed `user_id` values, so that the admin mobile application can open its own shift screen filtered to unconfirmed employees.
5. WHEN Shift_Admin_Alert_Job creates an Unconfirmed_Admin_Alert THEN Shift_Admin_Alert_Job SHALL persist the send timestamp on the shift so that no further Unconfirmed_Admin_Alert is created for the same shift.
6. IF every approved assignment on the shift has recorded `confirmed_at` THEN Shift_Admin_Alert_Job SHALL NOT create an Unconfirmed_Admin_Alert for that shift.
7. IF the shift has no Workplace_Shift_Admin THEN Shift_Admin_Alert_Job SHALL record the send timestamp without creating any notification, and SHALL NOT fail.
8. WHEN Push_Notification_Job delivers an Unconfirmed_Admin_Alert to a recipient holding `manager.auth` THEN Push_Notification_Job SHALL route the message through the admin Firebase project.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requires a new queued job, a new notification type, a new shift-level timestamp column, and a new configuration key. Independent of Requirement 1 at runtime: the two jobs share no state and either may fail without suppressing the other.

### Requirement 3: Missing Check-In Alert to Shift Administrators

**User Story:** As a shift administrator for a workplace, I want to be alerted shortly after a shift has started when employees have not checked in, so that I can follow up immediately rather than discovering the gap at the end of the shift.

#### Acceptance Criteria

1. WHEN Shift_Admin_Alert_Job runs AND Shift_Window_Start is in the past by at least `work.reminder.missing_check_in_alert.after_minute` minutes AND at least one approved assignment on that shift has no check-in time AND no Missing_Check_In_Admin_Alert has been recorded for the shift THEN Shift_Admin_Alert_Job SHALL create one notification of type `shift_missing_check_in_alert` per Workplace_Shift_Admin.
2. WHEN Shift_Admin_Alert_Job creates a Missing_Check_In_Admin_Alert THEN Shift_Admin_Alert_Job SHALL emit exactly one notification per shift per administrator regardless of how many employees are missing a check-in.
3. WHEN Shift_Admin_Alert_Job creates a Missing_Check_In_Admin_Alert THEN the notification content SHALL state the workplace name, the shift start time, and the count of employees without a check-in.
4. WHEN Shift_Admin_Alert_Job creates a Missing_Check_In_Admin_Alert THEN the notification `data` payload SHALL include `shift_id`, `workplace_id`, and the list of affected `user_id` values, so that the admin mobile application can open its own shift screen filtered to employees without a check-in.
5. WHEN Shift_Admin_Alert_Job creates a Missing_Check_In_Admin_Alert THEN Shift_Admin_Alert_Job SHALL persist the send timestamp on the shift so that no further Missing_Check_In_Admin_Alert is created for the same shift.
6. IF the shift's end time has already passed THEN Shift_Admin_Alert_Job SHALL NOT create a Missing_Check_In_Admin_Alert for that shift.
7. IF every approved assignment on the shift has a recorded check-in time THEN Shift_Admin_Alert_Job SHALL NOT create a Missing_Check_In_Admin_Alert for that shift.
8. WHILE a shift is underway and past the configured delay Shift_Admin_Alert_Job SHALL keep re-evaluating the Missing_Check_In_Admin_Alert condition on every run until the alert is emitted or the shift ends, so that an outage spanning the moment the threshold was crossed does not permanently skip the alert.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2 (shares the administrator resolution logic and the aggregated notification shape).

### Requirement 4: Attendance Status Filter on Shift Detail

**User Story:** As a shift administrator viewing a shift on the web admin, I want to narrow the employee list to those who have not confirmed or have not checked in, so that I can act on the alert I received without scanning the whole roster.

#### Acceptance Criteria

1. WHEN an administrator opens the shift detail screen THEN Assignment_Table SHALL display an Attendance_Status_Filter offering at minimum "all", "not confirmed", and "not checked in".
2. WHEN an administrator selects "not confirmed" THEN Assignment_Table SHALL display only rows whose confirmation timestamp is absent.
3. WHEN an administrator selects "not checked in" THEN Assignment_Table SHALL display only rows whose check-in start time is absent.
4. WHEN an Attendance_Status_Filter selection is active AND a free-text search term is also entered THEN Assignment_Table SHALL display only rows satisfying both conditions.
5. WHEN an Attendance_Status_Filter selection yields no rows THEN Assignment_Table SHALL display an empty state rather than an error.
6. WHEN an Attendance_Status_Filter selection changes THEN Assignment_Table SHALL clear any row selection that is no longer visible.
7. WHERE the shift detail URL carries an attendance query parameter Assignment_Table SHALL apply the corresponding Attendance_Status_Filter on load, so that a filtered roster can be shared as a link.
8. WHEN Attendance_Status_Filter labels are rendered THEN the web admin SHALL resolve every label through the translation function.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None on the backend; consumes fields already returned by the shift detail endpoint.

### Requirement 5: Direct Message Shortcut from Shift Detail

**User Story:** As a shift administrator, I want to message an employee directly from the shift detail row, so that I can chase an unconfirmed or missing employee without manually building a chat group.

#### Acceptance Criteria

1. IF the acting administrator holds the `chat.create` permission THEN Assignment_Table SHALL render a message action on each employee row.
2. IF the acting administrator does not hold the `chat.create` permission THEN Assignment_Table SHALL NOT render the message action on any row.
3. WHEN an administrator activates the message action AND a Direct_Conversation already exists between that administrator and that employee THEN the web admin SHALL open the admin chat portal directly on that conversation.
4. WHEN an administrator activates the message action AND no Direct_Conversation exists THEN the web admin SHALL open the admin chat portal on the Manual_Group creation screen.
5. WHERE the Manual_Group creation screen is opened from the message action the web admin SHALL preselect the employee and the acting administrator as participants.
6. WHERE the Manual_Group creation screen is opened from the message action the web admin SHALL prefill an editable default conversation title.
7. IF more than one Direct_Conversation exists between the same two users THEN the backend SHALL return the most recently active one.
8. IF a candidate conversation contains any participant beyond the acting administrator and the employee THEN the backend SHALL NOT treat it as a Direct_Conversation.
9. IF a candidate conversation's second participant has a removed participant status THEN the backend SHALL NOT treat it as a Direct_Conversation.
10. WHEN the direct conversation lookup is requested by a user lacking the `chat.view` permission THEN the backend SHALL reject the request with `403 Forbidden`.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4 (shares the Assignment_Table row rendering); reuses the existing `POST /manage/chat/conversations` creation endpoint.

## Non-Functional Requirements

### Performance Requirements

- WHEN Shift_Admin_Alert_Job evaluates its five-minute window THEN Shift_Admin_Alert_Job SHALL resolve Workplace_Shift_Admins in one query for the whole batch, not one query per shift.
- WHEN either Shift_Reminder_Job or Shift_Admin_Alert_Job processes a batch THEN that job SHALL complete within its `withoutOverlapping(60)` guard under a load of 200 shifts and 2,000 assignments in the scanned window.
- WHEN Assignment_Table applies an Attendance_Status_Filter THEN the web admin SHALL filter client-side without issuing an additional network request.

### Security Requirements

- WHEN a direct conversation lookup is performed THEN the backend SHALL scope the search to conversations in which the requesting administrator is an active participant.
- WHEN the message action is rendered THEN the web admin SHALL gate visibility on the `chat.create` permission, and the backend SHALL independently re-check permissions on the creation request rather than trusting the client.
- WHEN an Unconfirmed_Admin_Alert or Missing_Check_In_Admin_Alert is created THEN the backend SHALL address it only to Workplace_Shift_Admins of the shift's workplace, so that employee attendance data is not disclosed to administrators of other workplaces.

### Usability Requirements

- WHEN an administrator receives an aggregated admin alert THEN the notification SHALL identify the shift unambiguously by workplace name and start time.
- WHEN an administrator opens the Manual_Group creation screen from the message action THEN the prefilled title SHALL be editable before submission.
- WHEN any new user-facing string is introduced THEN the web admin SHALL resolve it through the translation function and SHALL provide Vietnamese and Japanese translations.

### Reliability Requirements

- WHEN notification creation fails part-way through a shift's administrator list THEN Shift_Admin_Alert_Job SHALL leave that shift's alert timestamp unset so that the next run retries the shift.
- WHEN notification creation fails for one shift THEN Shift_Admin_Alert_Job SHALL NOT roll back alerts already committed for other shifts in the same batch.
- WHEN either job runs concurrently with a prior invocation THEN its `withoutOverlapping` guard SHALL prevent duplicate emission.
- WHEN an employee confirms attendance between the alert threshold being crossed and the job executing THEN Shift_Admin_Alert_Job SHALL re-evaluate the condition at execution time and SHALL NOT emit an alert for an already-resolved shift.
- WHEN Shift_Reminder_Job fails THEN Shift_Admin_Alert_Job SHALL still run, and the reverse SHALL also hold.

## Constraints and Assumptions

### Technical Constraints

- Notification delivery remains a two-stage pipeline: rows are written to `notifications`, and `Push_Notification_Job` delivers them on its own one-minute schedule. End-to-end latency of up to six minutes from threshold crossing is therefore expected and acceptable.
- Alert idempotency must be enforced by persisted timestamps, not in-memory state, because the job is queued and may be retried.
- The web admin is a static Next.js export; the Attendance_Status_Filter and message action must work without server-side rendering.
- One-to-one chats must be expressed as `admin_group` conversations with two participants; introducing a dedicated conversation type is out of scope.

### Assumptions

- The admin mobile application already renders notifications of unknown types generically, so the two new types will display without a coordinated mobile release.
- Navigating from an alert to a filtered shift roster happens inside the admin mobile application, against its own shift screen, using `data.shift_id` and the notification type. Building that screen and its filter is mobile work outside this repository; this specification only guarantees the payload carries what that navigation needs.
- `role_user.team_id` is populated with workplace identifiers for workplace-scoped administrator roles, consistent with `User::getAccessibleWorkplacesByPermissionGroup`.
- Shift administrators hold the `manager.auth` permission and are therefore registered against the admin Firebase project.
- The default thresholds (180, 120, and 10 minutes) will be tuned in production through environment variables without code changes.

## Success Criteria

### Definition of Done

- [x] All acceptance criteria are met
- [x] Non-functional requirements are satisfied
- [x] Testing criteria are passed
- [x] Three new configuration keys are documented with their environment variable names and defaults
- [x] New notification types carry Vietnamese, Japanese, and English translations
- [x] New web admin strings pass through the project translation flow (`pnpm trans:prepare` / `pnpm trans:apply`)
- [x] Backend checks pass: `npm run prettier`, `npm run pint`, `php artisan test --parallel`
- [x] Frontend checks pass: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`
