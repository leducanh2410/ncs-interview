# Implementation Plan: Recruitment Notification Targeting

## Overview

The build runs bottom-up and is deliberately split so that the first half ships something useful on its own. Phase 1 lays down the schema, enum, and value object every later phase depends on. Phase 2 then delivers urgent-registration tracking and the frequent-urgent-worker group as a self-contained unit — it has no dependency on notification targeting, it starts accumulating the 90-day history the group needs, and it can be verified in isolation. Phases 3 through 6 build the targeting pipeline in dependency order: capture the criteria, resolve them into a recipient list, deliver against that list, then report on it. Delivery and visibility are implemented in the same phase because shipping one without the other would either leak targeted notifications to everyone or hide them from their recipients. Tests are written alongside each phase rather than deferred, with a dedicated phase at the end only for the cross-cutting lifecycle test that no single component owns.

### Implementation Strategy

- **Foundation-first, then feature-slice.** Schema and shared types come first; after that each phase is a working vertical slice with its own checkpoint.
- **Phase 2 is independently shippable.** Urgent tracking and the group can go to production before targeting exists, and doing so lets the group build real history in the meantime.
- **Riskiest work early within the targeting half.** Recipient resolution (Phase 4) is the most complex component and lands before the delivery and reporting work that depends on it.
- **Behavior-preserving changes are isolated.** Every task that touches an existing file states explicitly what must not change, since the topic-broadcast path and non-recruitment notifications carry a hard no-regression requirement.
- **Tests accompany the code that needs them.** Per-component tests belong to their own phase; only the end-to-end lifecycle test is deferred.

## Implementation Plan

### Phase 1: Foundation and Schema

- [ ] 1. Database schema

    - [ ] 1.1 Create the `notification_recipients` migration
        - Columns: `id`, `notification_id` (FK to `notifications`, cascade on delete), `user_id` (FK to `users`, cascade on delete)
        - Unique composite index on `(notification_id, user_id)`
        - Index on `(user_id, notification_id)` to serve the employee visibility check
        - No timestamps; send time already lives on `notifications.processed_at`
        - _Requirements: 4.11, 7.4_

    - [ ] 1.2 Add `recipients_resolved_count` to `notifications`
        - Nullable unsigned integer, default `null`
        - Document in the migration that `null` means never resolved and `0` means resolved to nobody
        - _Requirements: 4.13, 5.7, 7.5_

    - [ ] 1.3 Add `is_urgent_registration` to `shift_users`
        - Boolean, not nullable, default `false`
        - Composite index on `(is_urgent_registration, user_id)` for the grouped 90-day count
        - _Requirements: 1.1, 2.1_

- [ ] 2. Shared types and models

    - [ ] 2.1 Add `GroupKind::FrequentUrgentWorker`
        - Add the case with value `frequent_urgent_worker`
        - Include it in `systemKinds()` so it is treated as automatically maintained and rejected by the manual add/remove paths
        - Verify `isAdmin()` returns false for it, which is what blocks manual membership edits
        - _Requirements: 2.3, 2.4_

    - [ ] 2.2 Create the `NotificationRecipient` model and wire relations
        - New model with `$guarded = []`, following the existing model conventions
        - Add `recipients(): HasMany` to the `Notification` model
        - _Requirements: 4.11_

    - [ ] 2.3 Create the `RecruitmentCriteria` value object
        - Reads from a notification's `data` array and exposes typed accessors for gender target, experience requirement, nearby inclusion, radius, group inclusion, and hot bonus
        - Applies the documented defaults for every missing key so legacy notifications resolve as gender `both`, no experience requirement, no nearby workplaces, no group inclusion, no hot bonus
        - Treats a missing radius as nearby-inclusion disabled
        - Add a `GenderTarget` enum with cases `male`, `female`, `both`
        - _Requirements: 3.7, 3.8_

- [ ] 3. Checkpoint — schema and types
    - Run the migrations against a scratch database and roll them back to confirm both directions work.
    - Run `docker compose exec php php artisan test --parallel` and confirm no existing test regressed.
    - Ensure all tests pass, ask the user if questions arise.

### Phase 2: Urgent Registration and Frequent Worker Group

- [ ] 4. Urgent registration detection

    - [ ] 4.1 Implement `ShiftUserUrgentRegistrationObserver`
        - Handle `created` only, so approval, rejection, and cancellation cannot alter the marking and no retroactive marking is possible
        - Drive the lookup from `notification_read_states` using the `user_id` index, joining to `notifications` and filtering on type plus the JSON `shift_id` — never drive from `notifications`, which would scan the whole table on every registration
        - Write with `saveQuietly()` to avoid re-entering observers
        - Register the observer alongside the existing ones
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7_

- [ ] 5. Frequent urgent worker group

    - [ ] 5.1 Implement `RecomputeFrequentUrgentWorkerGroupAction`
        - Resolve the singleton group via `Group::firstOrCreateByIdentity(new GroupIdentity(kind: GroupKind::FrequentUrgentWorker))`
        - Compute members with one aggregate query joining `shift_users`, `shifts`, and `check_ins`, filtered on `is_urgent_registration` and a 90-day shift-date window, grouped by user with `HAVING COUNT(*) >= 2`
        - Require an actual check-in, so assignments never attended do not qualify
        - Exclude disabled users
        - Diff current against desired membership and apply the difference with chunked inserts and deletes, following the 1,000-row convention in `RecomputeGroupMembershipAction`
        - _Requirements: 2.1, 2.2, 2.3, 2.5, 2.6_

    - [ ] 5.2 Wire the group into scheduled maintenance and the group screens
        - Invoke the recompute from the existing scheduled group maintenance so the group is not stale for admins browsing the group list
        - Confirm the group appears in the admin group screens alongside the other automatically maintained kinds, and that manual add/remove is rejected
        - _Requirements: 2.3, 2.4, 2.5_

- [ ] 6. Checkpoint — urgent tracking is independently shippable
    - Verify end to end by hand: read a recruitment notification, register for its shift, confirm the row is marked, then check in and run the recompute twice to confirm group entry.
    - Ensure all tests pass, ask the user if questions arise.

### Phase 3: Criteria Capture

- [ ] 7. Backend criteria validation and persistence

    - [ ] 7.1 Extend `StoreRequest` with criteria rules
        - Add the rules inside the existing `type === recruitment` branch so criteria cannot be attached to another notification type
        - `gender_target` required and one of the enum values; the three booleans required; `nearby_radius_km` required and greater than zero only when nearby inclusion is true, with a sane upper bound; `hot_bonus_amount` nullable integer with minimum zero
        - _Requirements: 3.1, 3.3, 3.4, 3.5, 3.6_

    - [ ] 7.2 Persist criteria in `NotificationService::createOrUpdateNotification`
        - Extend the existing recruitment branch that already builds `data` so the criteria join `shift_id` and `workplace_id` in the same JSON payload
        - Discard any criteria submitted with a non-recruitment type, mirroring how `icon` and `url` are already dropped
        - Leave the existing scheduling, translation, and already-sent guard logic untouched
        - _Requirements: 3.2, 3.7_

- [ ] 8. Frontend criteria form

    - [ ] 8.1 Add criteria fields to the notification form
        - Extend `formInitialValue` in `notification_form.ts` with the six fields and their defaults, including radius pre-filled at 10
        - Add validation via the existing `requiredIf` idiom used for `workplace_id` and `shift_id`
        - Render the inputs in `notification_create_update.tsx` under the same condition that already reveals the workplace and shift selects, so switching type shows and hides them without a reload
        - _Requirements: 3.1, 3.2, 3.3, 3.4_

    - [ ] 8.2 Add translations for the new labels
        - Convert all new copy to `t(...)` first, then run `pnpm trans:prepare`, fill only `vi` and `ja` in the generated batch, and run `pnpm trans:apply`
        - _Requirements: 3.1_

- [ ] 9. Checkpoint — criteria round-trip
    - Create a recruitment notification through the UI and confirm the criteria land in `notifications.data`; create a normal notification and confirm no criteria are stored.
    - Ensure all tests pass, ask the user if questions arise.

### Phase 4: Recipient Resolution

- [ ] 10. Eligibility filtering

    - [ ] 10.1 Implement `FilterShiftEligibleUsersAction`
        - Accept a shift and an array of user IDs; return the IDs that pass
        - Fetch work-hour data for all candidates through one `WorkHelper::getWorkHours($userIds, $rangeStart, $rangeEnd)` call, mirroring the six-day window already used in `RegisterShiftAction`
        - Load `SystemConfig` once and all candidates' `UserConfig` rows in one query keyed by user, so each candidate is judged against their own weekly limit through the existing `getMaxHourPerWeek` precedence — never a hardcoded 28
        - Call `WorkHelper::validateShiftRegister` per candidate and exclude anyone it rejects, reusing all four of its rules without reimplementing any
        - Process in chunks so a large pool does not build one oversized result set
        - _Requirements: 4.8, 4.9, 4.10_

- [ ] 11. Recipient resolution

    - [ ] 11.1 Implement `ResolveRecruitmentRecipientsAction` pool assembly
        - Refresh group membership first when group inclusion is enabled
        - Seed with the workplace's `workplace_users`; union nearby workplaces' users via `FindNearbyWorkplacesAction` when enabled; union group members with no distance restriction when enabled
        - De-duplicate and drop disabled users
        - _Requirements: 4.1, 4.2, 2.7_

    - [ ] 11.2 Implement criteria filtering and exclusions
        - Gender filter retaining matching users and users with null gender; no filtering when the target is `both`
        - Experience filter requiring an actual check-in inside the trailing three months at any workplace
        - Exclude users who already hold a `shift_users` row for the target shift
        - Apply the filters uniformly to group-contributed and nearby-contributed users so no audience selection bypasses them
        - _Requirements: 4.3, 4.4, 4.5, 4.6, 4.7_

    - [ ] 11.3 Apply eligibility and persist
        - Apply `FilterShiftEligibleUsersAction` last, against the smallest possible set
        - Bulk-insert survivors into `notification_recipients` in chunks and write the count to `notifications.recipients_resolved_count` in the same transaction, so a failure leaves no rows and a null count
        - _Requirements: 4.8, 4.11, 4.13_

- [ ] 12. Checkpoint — resolution correctness
    - Seed a dataset covering nearby and distant workplaces, group members, null gender, no-show-only users, already-assigned users, and each eligibility rejection reason; assert the resolved set matches expectations.
    - Ensure all tests pass, ask the user if questions arise.

### Phase 5: Delivery and Visibility

- [ ] 13. Targeted delivery

    - [ ] 13.1 Add the targeted branch to `PushNotificationToUser`
        - Branch order: `user_id !== null` keeps the single-user path; otherwise recruitment type takes the new targeted path; otherwise the existing topic path runs **completely unmodified**
        - Resolve recipients, then load their devices with a non-null `fcm_token` and group by the user's `settings.lang` so the right translation is used per language
        - Chunk tokens to at most 500 per `sendMulticast` call
        - Compute iOS badges for the recipient subset through `CalculateUserBadgeCountAction::executeForUsers`
        - Mark `SENT` once recipients are committed; log per-chunk push failures without aborting remaining chunks or flipping to `FAILED`; mark `FAILED` only when resolution itself throws
        - An empty recipient list marks `SENT` with a zero count and pushes nothing, with no fallback to broadcast
        - _Requirements: 4.12, 5.1, 5.6, 6.1, 6.2, 6.3, 6.4, 6.5_

- [ ] 14. Visibility scoping

    - [ ] 14.1 Add the shared recruitment visibility scope
        - One reusable scope: not a recruitment notification, **or** `recipients_resolved_count IS NULL`, **or** the caller has a recipient row
        - Express the legacy fallback through the null column, never through the absence of recipient rows, so a notification resolved to zero recipients stays hidden rather than becoming world-visible
        - _Requirements: 5.2, 5.7_

    - [ ] 14.2 Apply the scope across the employee-facing service
        - Apply to `getNotifications`, `countUnread`, `readNotification` (returning the existing forbidden error with 403), and `readAllNotifications`
        - _Requirements: 5.2, 5.3, 5.4, 5.5_

    - [ ] 14.3 Exclude recruitment from the cached system-notification endpoint
        - Add a type exclusion **inside** the query wrapped by `Cache::rememberForever`, since that cache uses a single key shared by every user and anything cached there is served to everyone regardless of scoping
        - Confirm the targeted branch does not need to invalidate the system-notification cache as a result
        - _Requirements: 5.2, 5.6_

- [ ] 15. Checkpoint — no regression for other types
    - Verify a normal broadcast still reaches topics, a per-user notification still uses the single-user path, and both remain visible exactly as before.
    - Verify a recipient sees the recruitment notification and a non-recipient sees it nowhere, including the system endpoint.
    - Ensure all tests pass, ask the user if questions arise.

### Phase 6: Delivery Statistics

- [ ] 16. Backend statistics

    - [ ] 16.1 Expose counts on the admin notification list
        - Read the denominator straight from `recipients_resolved_count`; add one `withCount` aggregate for the read count, constrained to users present in the recipient list so the fraction can never exceed 100%
        - Confirm no per-row queries are introduced on the paginated list
        - _Requirements: 7.1, 7.3, 7.4, 7.6_

- [ ] 17. Frontend statistics column

    - [ ] 17.1 Add the read/received column
        - Render the fraction only for recruitment rows; render nothing for other types; render a "not yet sent" indicator when the count is null rather than `0 / 0`
        - Extend `NotificationWithRelation` in `notification.d.ts` with the optional count fields
        - Add the new copy through the translation flow
        - _Requirements: 7.1, 7.2, 7.5_

### Phase 7: Tests

- [ ] 18. Component tests

    - [ ] 18.1 `ShiftUserUrgentRegistrationObserverTest`
        - Marked when read before registering; not marked without a prior read; not marked retroactively; not marked by a read of another shift's notification; marked when the read came from "mark all as read"
        - Marking survives approval, rejection, and cancellation
        - Admin assignment is marked on the same terms as self-registration
        - Bulk assignment does not issue a scan-the-notifications-table query per user
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7_

    - [ ] 18.2 `RecomputeFrequentUrgentWorkerGroupActionTest`
        - Two checked-in urgent shifts inside 90 days joins; two urgent registrations without check-ins does not; one qualifying shift does not; a disabled user never joins
        - Ageing out: seed history from a previous window and assert removal on the next recompute
        - Running twice is idempotent
        - _Requirements: 2.1, 2.2, 2.5, 2.6_

    - [ ] 18.3 `FilterShiftEligibleUsersActionTest`
        - Each of overlap, minimum rest hours, maximum shifts per day, and the weekly hour limit excludes a candidate
        - A candidate with a per-user weekly limit is judged against their own limit, not the default
        - Batch evaluation matches per-candidate evaluation
        - _Requirements: 4.8, 4.9, 4.10_

    - [ ] 18.4 `ResolveRecruitmentRecipientsActionTest`
        - Pool composition across primary, nearby within radius, excluded beyond radius, and group members regardless of distance
        - Gender filtering including the null-gender pass-through; experience filtering rejecting assigned-but-never-checked-in users; already-assigned exclusion
        - Group-contributed users still subject to every filter
        - Empty result writes a zero count and no rows; mid-resolution failure leaves no rows and a null count
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.11, 4.13_

    - [ ] 18.5 `PushNotificationToUserRecruitmentTest`
        - Recruitment resolves and is marked sent; a broadcast still goes to topics untouched; a per-user notification still uses the single-user path
        - A chunk push failure leaves the notification sent with recipients intact
        - A scheduled notification resolves at send time, proven by changing a candidate's eligibility between creation and the scheduled moment
        - _Requirements: 4.12, 5.1, 5.6_

    - [ ] 18.6 `UserNotificationVisibilityTest`
        - Recipient sees it, non-recipient does not; unread counts match; non-recipient gets 403 on read; "mark all as read" creates no read state for a non-recipient
        - **A legacy notification and a resolved-to-zero notification are asserted in the same class to behave in opposite ways**, since recipient rows alone cannot tell them apart
        - A recruitment notification never appears in the system-notification endpoint for anyone, including after a non-recipient has warmed the cache, and is still returned by the regular list for its recipients
        - Non-recruitment notifications stay visible to all and still appear in the system endpoint
        - _Requirements: 5.2, 5.3, 5.4, 5.5, 5.6, 5.7_

    - [ ] 18.7 `ManageNotificationStatisticsTest`
        - Counts present for recruitment rows and absent for others; read count excludes non-recipient read states so the fraction never exceeds 100%
        - Counts do not drift after further employees register for the shift post-delivery
        - A pending notification reports as unresolved rather than zero out of zero
        - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6_

- [ ] 19. Lifecycle test

    - [ ] 19.1 End-to-end urgent recruitment lifecycle
        - Drive the whole feature through real endpoints and the real job rather than fixtures: admin creates a notification with every criterion enabled, the job runs, an eligible recipient reads it and then registers through the registration endpoint, the registration is confirmed urgent, and after a second checked-in cycle the employee joins the group
        - Finish by sending a notification for a different workplace and asserting that employee is reached through group inclusion alone
        - _Requirements: 1.1, 2.1, 4.1, 4.2, 5.1_

- [ ] 20. Checkpoint — full suite
    - Run `docker compose exec php php artisan test --parallel` and confirm the whole suite is green.
    - Ensure all tests pass, ask the user if questions arise.

### Phase 8: Final Validation

- [ ] 21. Code quality

    - [ ] 21.1 Backend checks
        - Run `npm run prettier` and `npm run pint` in `./backend`
        - Confirm every new class imports its dependencies with `use` statements and follows the Action pattern
        - Confirm every test method uses camelCase

    - [ ] 21.2 Frontend checks
        - Run `pnpm lint:fix`, `pnpm prettier`, and `pnpm typecheck` in `./frontend`
        - Confirm no user-facing string is hardcoded and that `vi` and `ja` translations exist for all new copy

    - [ ] 21.3 Regression confirmation
        - Confirm salary calculation output is unchanged for a dataset containing urgent registrations
        - Confirm no change to the topic-broadcast path or to the visibility of any non-recruitment notification

## Notes

- **Ordering rationale.** Phase 2 deliberately precedes the targeting work: it is independently shippable, and because the group depends on a trailing 90-day window, shipping it early lets real history accumulate before anyone relies on the group for targeting. Within the targeting half, resolution (Phase 4) precedes delivery (Phase 5) because delivery cannot be tested without a recipient list, and statistics (Phase 6) come last because they merely report on what the earlier phases produce.
- **Delivery and visibility must ship together.** Task 13 without task 14 would deliver targeted notifications that everyone can still see; task 14 without task 13 would hide notifications that were never resolved. Neither is a valid intermediate state to deploy.
- **The two states that look identical.** A notification resolved to zero recipients and a legacy notification both have no recipient rows. Only `recipients_resolved_count` separates them, and they must behave in opposite ways — hidden from everyone versus visible to everyone. This is the single most likely place for a regression, which is why 18.6 asserts both in one class.
- **Two performance traps are load-bearing, not optional.** The observer must drive from `notification_read_states`, and the visibility scope must short-circuit on notification type before consulting the recipient table. Reversing either turns a hot path into a table scan, so both are called out in their tasks rather than left to the implementer's judgement.
- **Never hardcode 28.** The weekly limit is resolved per employee through `UserConfig` → `SystemConfig` → config default. A literal 28 anywhere in the eligibility filter is a bug.
- **The mobile app is out of scope and must stay that way.** No task may introduce a new request parameter or endpoint the employee app is required to call. Criteria reach the device through the existing FCM data payload, which already merges `notifications.data`.
- **Migrations are intentionally untested.** Per the project's coverage guidance they contain no logic; the behavior they enable is covered by the component tests instead.
