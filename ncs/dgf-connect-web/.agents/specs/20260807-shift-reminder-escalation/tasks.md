# Implementation Plan: Shift Reminder Escalation and Admin Messaging Shortcut

## Overview

The build runs bottom-up on the backend and then vertically on the frontend. Configuration, enums, migrations, and translations land first because every later component reads them. The two recipient-resolution and notification-creation actions come next, since both jobs depend on them and they are the pieces most likely to reveal a wrong assumption about the Laratrust pivot. The jobs follow, then the single new endpoint. The frontend then proceeds as one vertical slice: shared state, API client, table changes, portal wiring. Tests are written alongside each backend unit and gathered into a dedicated phase for the lifecycle and integration scenarios that span several components. The riskiest assumption — that workplace-scoped shift administrators can be resolved from `role_user` in one query — is deliberately proved in Phase 2, before either job is written.

### Implementation Strategy

- **Foundation-First** for the backend: configuration and schema before behaviour, actions before jobs.
- **Risk-First** within the foundation: `ResolveWorkplaceShiftAdminsAction` is implemented and tested before anything depends on it, because a wrong pivot assumption invalidates both alerts.
- **Feature-Slice** for the frontend: state, client, table, and portal ship together so the message shortcut is verifiable end to end.
- Each backend task lands with its unit tests; Phase 7 covers only the cross-component lifecycle tests.
- No existing reminder timing, payload, or guard is modified except the "too early" comparison in `ShiftReminder`, which is widened deliberately and covered by a regression test.

## Implementation Plan

### Phase 1: Backend Foundation

- [x] 1. Configuration, enums, and schema
    - [x] 1.1 Add the three reminder configuration keys
        - Add `work.reminder.confirmation.final_before_minute` reading `CONFIRM_SHIFT_FINAL_REMINDER_BEFORE_MINUTE`, default 180
        - Add `work.reminder.unconfirmed_alert.before_minute` reading `SHIFT_UNCONFIRMED_ALERT_BEFORE_MINUTE`, default 120
        - Add `work.reminder.missing_check_in_alert.after_minute` reading `SHIFT_MISSING_CHECK_IN_ALERT_AFTER_MINUTE`, default 10
        - Document all three in `.env.example` with their defaults
        - _Requirements: 1.1, 2.1, 3.1_

    - [x] 1.2 Add the two administrator notification types
        - Add `SHIFT_UNCONFIRMED_ALERT` and `SHIFT_MISSING_CHECK_IN_ALERT` cases to `App\Enums\NotificationType`
        - _Requirements: 2.1, 3.1_

    - [x] 1.3 Add the assignment-level reminder guard column
        - Migration adding nullable `final_confirmation_reminded_at` to `shift_users`, positioned after `confirmation_reminded_at`
        - Update the `ShiftUser` model docblock property list
        - _Requirements: 1.2_

    - [x] 1.4 Add the shift-level alert guard columns
        - Migration adding nullable `unconfirmed_alerted_at` and `missing_check_in_alerted_at` to `shifts`
        - Update the `Shift` model docblock property list
        - _Requirements: 2.5, 3.5_

    - [x] 1.5 Add administrator alert translation keys
        - Add `lang.alert.unconfirmed.title` / `.content` and `lang.alert.missing_check_in.title` / `.content` to all five locale files (`vi`, `ja`, `en`, `ne`, `si`)
        - Use `:workplace`, `:time`, and `:count` placeholders so translators control word order
        - _Requirements: 2.3, 3.3_

- [x] 2. Checkpoint - Foundation
    - Run `docker compose exec php php artisan migrate` against a scratch database and confirm both migrations apply and roll back cleanly.
    - Confirm `config('work.reminder')` returns all five sub-keys.

### Phase 2: Recipient Resolution and Notification Creation

- [x] 3. Administrator resolution
    - [x] 3.1 Implement `ResolveWorkplaceShiftAdminsAction`
        - Accept `int[] $workplaceIds`, return `array<int, int[]>` keyed by workplace identifier
        - Join `role_user` → `permission_role` → `permissions`, filter `permissions.name LIKE 'shift.%'`, `role_user.team_id IN (...)`, and `role_user.user_type` equal to the `User` morph value
        - Return distinct user identifiers; omit workplaces with no match rather than returning an empty array entry
        - Import all classes with `use` statements per project PHP rules
        - _Requirements: 2.1, 2.7, 3.1_

    - [x] 3.2 Unit-test `ResolveWorkplaceShiftAdminsAction`
        - Returns administrators keyed by workplace across a multi-workplace batch in a single query
        - Excludes a user whose role grants no `shift.` permission
        - Excludes a user whose shift role has `team_id` null
        - Excludes a user holding only `global_admin`
        - Excludes an administrator scoped to a different workplace
        - _Requirements: 2.1, 3.1_

- [x] 4. Aggregated alert creation
    - [x] 4.1 Implement `CreateShiftAdminAlertAction`
        - Accept the shift, notification type, recipient identifiers, affected employee identifiers, and the resolved start timestamp
        - Create one `notifications` row per recipient with `NotificationIcon::WARNING` and `scheduled_at` of now
        - Build `data` as `['shift_id' => …, 'workplace_id' => …, 'user_ids' => …]`
        - Attach translation rows for every locale from `Locale::getTranslationLocales()`
        - _Requirements: 2.2, 2.3, 2.4, 3.2, 3.3, 3.4_

    - [x] 4.2 Unit-test `CreateShiftAdminAlertAction`
        - Creates exactly one notification per recipient regardless of affected employee count
        - Interpolates workplace name, start time, and count into the content
        - Includes every affected identifier in `data.user_ids`
        - Creates a translation row per non-default locale
        - _Requirements: 2.2, 2.3, 2.4, 3.2, 3.3, 3.4_

- [x] 5. Checkpoint - Resolution proved
    - Ensure all tests pass. The pivot-join assumption is now verified; ask the user if the resolved recipient set differs from expectation on real data.

### Phase 3: Employee Final Confirmation Reminder

- [x] 6. Extend `ShiftReminder`
    - [x] 6.1 Widen the query projection and the too-early guard
        - Add `shift_users.confirmed_at` and `shift_users.final_confirmation_reminded_at` to the select list
        - Change the too-early guard to compare against the maximum of `shift.before_minute`, `check_in.before_minute`, and `confirmation.final_before_minute`, so raising the final window above 360 minutes cannot silently drop the reminder
        - _Requirements: 1.1, 1.8_

    - [x] 6.2 Add the final confirmation reminder branch
        - Inside the existing per-assignment loop, emit a `confirm_reminder` notification when `confirmed_at` is null, `final_confirmation_reminded_at` is null, `minutesUntilStart` is at least zero, and `minutesUntilStart` is within `final_before_minute`
        - Stamp `final_confirmation_reminded_at` before creating the notification, matching the existing branches' ordering
        - Attach translations for every locale
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.6, 1.7_

    - [x] 6.3 Unit-test the new branch and guard existing behaviour
        - Emits for an approved, unconfirmed assignment inside the final window
        - Does not emit when `confirmed_at` is set, when status is pending or rejected, or when already stamped
        - Does not emit once the shift has started
        - Resolves the window from `WorkHelper::getShiftTimes` for an overnight shift rather than from the calendar date
        - Emits when the two-day reminder never ran because the shift was approved late
        - Regression: existing shift, check-in, and check-out reminders are unchanged in timing and payload
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8_

- [x] 7. Checkpoint - Employee escalation
    - Ensure all tests pass, including the pre-existing `ShiftReminder` suite.

### Phase 4: Administrator Alert Job

- [x] 8. Implement `ShiftAdminAlert`
    - [x] 8.1 Build the candidate shift query
        - Select shifts in the same calendar window `ShiftReminder` scans, joined to `workplace_shifts`, restricted to shifts holding at least one approved assignment
        - Exclude shifts where both `unconfirmed_alerted_at` and `missing_check_in_alerted_at` are already set
        - Eager-load the workplace name and the approved assignments with their check-in times
        - Skip and warn when the workplace shift row lacks a start or end time
        - _Requirements: 2.1, 3.1_

    - [x] 8.2 Resolve administrators once per batch
        - Collect the distinct workplace identifiers from the candidate shifts and call `ResolveWorkplaceShiftAdminsAction` a single time
        - _Requirements: 2.1, 3.1, Non-Functional: Performance_

    - [x] 8.3 Evaluate and emit the unconfirmed alert
        - Condition: `unconfirmed_alerted_at` null, `minutesUntilStart` at least zero and within `unconfirmed_alert.before_minute`, and at least one approved assignment with null `confirmed_at`
        - Emit through `CreateShiftAdminAlertAction`, then stamp `unconfirmed_alerted_at`, both inside one per-shift transaction
        - Stamp without emitting when the workplace resolves to no administrator, and log at debug level
        - _Requirements: 2.1, 2.2, 2.5, 2.6, 2.7_

    - [x] 8.4 Evaluate and emit the missing check-in alert
        - Condition: `missing_check_in_alerted_at` null, `minutesUntilStart` at or beyond the negative of `missing_check_in_alert.after_minute`, shift not yet ended, and at least one approved assignment with no check-in time
        - Emit and stamp inside the same per-shift transaction discipline
        - _Requirements: 3.1, 3.2, 3.5, 3.6, 3.7, 3.8_

    - [x] 8.5 Use one transaction per shift, not per batch
        - Wrap each shift's evaluation and emission in its own `DB::transaction` so a failure leaves only that shift unstamped and does not roll back committed alerts for other shifts
        - _Requirements: Non-Functional: Reliability_

    - [x] 8.6 Register the job on the scheduler
        - Add `Schedule::job(new ShiftAdminAlert())->everyFiveMinutes()->withoutOverlapping(60);` to `routes/console.php`, beside the existing `ShiftReminder` registration
        - _Requirements: 2.1, 3.1_

    - [x] 8.7 Unit-test `ShiftAdminAlert`
        - Emits one unconfirmed alert per scoped administrator when at least one assignment is unconfirmed
        - Emits exactly one notification per administrator for three unconfirmed employees, with a count of three and three identifiers in `data.user_ids`
        - Emits nothing when every assignment is confirmed, and nothing a second time once stamped
        - Emits the missing check-in alert only after the configured delay, never before it, and never after the shift has ended
        - Excludes global admins, `team_id` null holders, and administrators of other workplaces from both alerts
        - Stamps and emits nothing when the workplace has no scoped administrator
        - Leaves the timestamp null when creation fails mid-transaction, and emits on the following run
        - Resolves both windows correctly for an overnight shift
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7_

- [x] 9. Checkpoint - Administrator escalation
    - Ensure all tests pass. Confirm with the user that the two alert copy strings read correctly in Vietnamese and Japanese before the frontend consumes them.

### Phase 5: Direct Conversation Endpoint

- [x] 10. Direct conversation lookup
    - [x] 10.1 Implement `FindDirectConversationAction`
        - Search `admin_group` conversations where the actor and the target are both active participants and the active participant count is exactly two, asserted with a correlated subquery
        - Order by `last_activity_at` descending with `id` descending as tie-break; return the first match or null
        - Return closed conversations as well as open ones, so the caller reopens rather than duplicating
        - _Requirements: 5.3, 5.7, 5.8, 5.9_

    - [x] 10.2 Add the controller method, form request, and route
        - `GET /api/manage/chat/direct-conversation` on the admin chat controller, returning `{ "data": { "conversation_id": number|null } }`
        - Validate `user_id` as `required|integer|exists:users,id`
        - Register the route inside the existing `chat` prefix group with `permission:chat.view` middleware
        - _Requirements: 5.3, 5.4, 5.10_

    - [x] 10.3 Test the action and the endpoint
        - Action: returns the conversation for exactly two active participants; null for a third active participant, for a removed participant, for a shift group or user group with the same members, and when the actor is not a participant; returns the most recently active of two candidates
        - HTTP: `200` with a conversation identifier, `200` with null, `403` without `chat.view`, `422` for a missing or unknown `user_id`
        - _Requirements: 5.3, 5.7, 5.8, 5.9, 5.10_

- [x] 11. Checkpoint - Backend complete
    - Run the full backend suite with `docker compose exec php php artisan test --parallel` and ensure it passes.

### Phase 6: Web Admin

- [x] 12. Shared chat intent state
    - [x] 12.1 Add `ChatIntent` and `chatIntentAtom`
        - Define the discriminated union and the atom in `frontend/src/pages/global.state.ts`, beside `authAtom` and `masterAtom`
        - _Requirements: 5.3, 5.4, 5.5_

    - [x] 12.2 Open the portal from an intent in `Layout.tsx`
        - Open the chat portal whenever a non-null intent is written
        - _Requirements: 5.3, 5.4_

    - [x] 12.3 Honour and clear the intent in `AdminChatPortal`
        - On an `open` intent, select the conversation; on a `create-direct` intent, enter the creation panel with type `admin_group`, the employee preselected, and the default title prefilled and editable
        - Clear the atom after applying so reopening the portal does not replay a stale intent
        - _Requirements: 5.3, 5.4, 5.5, 5.6_

- [x] 13. Direct conversation API client
    - [x] 13.1 Add the typed request and query hook
        - Add the endpoint to the manage chat API module and expose `useFindDirectConversationQuery`, disabled until explicitly triggered so the table costs no lookups on render
        - _Requirements: 5.3, 5.4, Non-Functional: Performance_

- [x] 14. Attendance filter on the shift detail table
    - [x] 14.1 Add the `AttendanceFilter` control and predicate
        - Add the `all` / `unconfirmed` / `not_checked_in` union and a segmented control rendered above the table
        - Extend the existing `filteredRecords` memo so the filter composes with the debounced text search
        - _Requirements: 4.1, 4.2, 4.3, 4.4_

    - [x] 14.2 Handle empty results and stale selection
        - Render the table's existing empty state when the filter matches nothing
        - Extend the selection-pruning effect's dependencies so hidden rows drop out of the selection
        - _Requirements: 4.5, 4.6_

    - [x] 14.3 Seed the filter from the URL
        - Read the `attendance` query-string parameter on the shift detail page and seed the filter, falling back to `all` for an unrecognised value, so a filtered roster is shareable as a link
        - _Requirements: 4.7_

- [x] 15. Message action on each row
    - [x] 15.1 Render the action gated on `chat.create`
        - Add a per-row message action, hidden entirely when the acting administrator lacks `chat.create`
        - _Requirements: 5.1, 5.2_

    - [x] 15.2 Wire the action to lookup and intent
        - On activation, call the lookup; write an `open` intent when a conversation identifier is returned, otherwise a `create-direct` intent carrying the employee and a default title
        - Show an error notification and leave the portal closed when the lookup fails
        - _Requirements: 5.3, 5.4, 5.5, 5.6_

- [x] 16. Frontend tests
    - [x] 16.1 Extend `assignment_table.test.tsx`
        - Renders the filter and narrows to unconfirmed rows, and to rows without a check-in start
        - Composes filter with text search; renders the empty state when nothing matches
        - Drops hidden rows from the selection on filter change
        - Renders the message action with `chat.create` and hides it without
        - Writes an `open` intent when a conversation is found and a `create-direct` intent when it is not
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 5.1, 5.2, 5.3, 5.4_

    - [x] 16.2 Test the portal intent handling
        - Applies an `open` intent by selecting the conversation, applies a `create-direct` intent by prefilling participants and an editable title, and clears the atom afterwards
        - _Requirements: 5.3, 5.4, 5.5, 5.6_

- [x] 17. Translations
    - [x] 17.1 Run the project translation flow
        - Confirm every new string passes through `t(...)` with no hardcoded user-facing text
        - Run `pnpm trans:prepare`, fill `vi` and `ja` in the generated batch without touching `key`, `en`, or `sources`, then run `pnpm trans:apply`
        - _Requirements: 4.8, Non-Functional: Usability_

- [x] 18. Checkpoint - Feature complete
    - Ensure the frontend suite passes and the message shortcut works end to end against a local backend.

### Phase 7: Lifecycle and Integration Tests

- [x] 19. Cross-component lifecycle tests
    - [x] 19.1 Unconfirmed escalation lifecycle
        - Register a shift through the real registration action, approve it through the real approval path, advance into the final window and run `ShiftReminder` asserting the employee reminder, advance into the two-hour window and run `ShiftAdminAlert` asserting the administrator alert, confirm through the real confirmation endpoint, then advance past start and run both jobs asserting no further notification
        - _Requirements: 1.1, 1.3, 2.1, 2.6_

    - [x] 19.2 Missing check-in escalation lifecycle
        - Same setup through approval and confirmation, advance past start without checking in and run `ShiftAdminAlert` asserting the alert, then check in through the real endpoint and run again asserting nothing new
        - _Requirements: 3.1, 3.7_

    - [x] 19.3 Accumulated history
        - Seed a prior shift for the same employee and workplace already carrying both alert timestamps and a completed check-in, then run a new shift's escalation and assert the old rows are neither reprocessed nor counted
        - _Requirements: 2.5, 3.5_

    - [x] 19.4 Chat shortcut round trip
        - Activate the message action for an employee with no prior conversation, create the manual group through the real endpoint, then activate the action again and assert the existing conversation is opened rather than a second one created
        - _Requirements: 5.3, 5.4, 5.7_

- [x] 20. Checkpoint - Integration
    - Ensure all lifecycle tests pass and report to the user anything intentionally left uncovered.

### Phase 8: Final Validation

- [x] 21. Code Quality
    - [x] 21.1 Backend: run `npm run prettier` and `npm run pint` in `./backend`
    - [x] 21.2 Backend: run `docker compose exec php php artisan test --parallel`
    - [x] 21.3 Frontend: run `pnpm lint:fix`, then `pnpm prettier`, then `pnpm typecheck` in `./frontend`
    - [x] 21.4 Frontend: run `pnpm test`
    - [x] 21.5 Run `pnpm trans:cleanup` to remove temporary translation workflow files

## Notes

- **Ordering rationale.** `ResolveWorkplaceShiftAdminsAction` is built and tested in Phase 2, before either job, because the whole administrator-alert feature rests on the assumption that workplace-scoped shift administrators are discoverable through `role_user.team_id`. If that assumption is wrong on real data, it is cheaper to discover it against a small action than after two jobs are written against it.
- **The widened too-early guard in task 6.1 is a behaviour change to existing code.** It is safe at the default configuration (180 is below 360, so the guard's result is unchanged today) but prevents a silent failure if the final window is later raised. Task 6.3 includes a regression test asserting the existing three reminders are untouched.
- **Test conventions.** All PHPUnit methods use camelCase per project rules. No multi-threaded concurrency tests are written; duplicate suppression is verified through the persisted timestamp guards, evaluated inside the same transaction as the write.
- **Deliberately uncovered.** Tapping an alert in the admin mobile application opens that application's own shift screen with the matching filter applied. That screen and its filter are mobile work outside this repository; this plan only guarantees the payload carries `shift_id`, `workplace_id`, `user_ids`, and the notification type. The web admin filter (Phase 6) is a separate, self-contained feature reachable from the on-screen control and from the URL.
- **Risk: notification volume.** Each alert fans out to every scoped administrator of a workplace. If a workplace has an unexpectedly large administrator roster, alert volume grows linearly. The per-shift stamp caps this at one notification per administrator per shift per alert kind, but the roster size should be sanity-checked on production data during Phase 5's checkpoint.
- **Risk: clock skew across the two jobs.** `ShiftReminder` and `ShiftAdminAlert` run on the same five-minute tick but are independent queue jobs. An employee confirming between the two executions can produce an employee reminder without a corresponding administrator alert, which is the desired outcome and not a defect.
