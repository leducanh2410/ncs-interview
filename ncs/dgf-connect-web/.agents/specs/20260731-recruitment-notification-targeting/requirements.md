# Requirements Document

## Introduction

This feature reworks the existing recruitment notification (`NotificationType::RECRUITMENT`) from an untargeted broadcast into a filtered, per-recipient notification, and adds the recruitment details admins need in order to attract workers to an understaffed shift. Today every admin-created notification is stored with `user_id = null` and delivered through Firebase Cloud Messaging **topic** subscriptions, while the in-app list query (`App\Services\User\NotificationService::getNotifications`) returns every `user_id IS NULL` row to **every** employee — so there is no record of who a recruitment notification was aimed at, no way to restrict it, and no denominator for "how many people received it". This feature introduces a persisted recipient list resolved from admin-selected criteria (gender, recent-work experience, nearby workplaces, and a new auto-computed group of workers who frequently take urgent shifts), scopes both push delivery and in-app visibility to that list, and surfaces a read/received statistic on the admin notification list. **This is a breaking behavior change for recruitment notifications only**: they stop being visible to all employees. All other notification types keep their current topic-broadcast behavior unchanged. Salary calculation and hourly-rate resolution are explicitly untouched. The existing shift-registration eligibility rules — shift overlap, minimum rest hours, maximum shifts per day, and the weekly working-hour limit that defaults to 28 hours — are likewise not modified, but they are now **reused as a recipient filter**, so a recruitment notification only reaches employees who could actually accept the shift if they responded to it.

### Feature Summary

Turns recruitment notifications into criteria-targeted messages delivered to a persisted, filtered recipient list, and automatically identifies employees who habitually accept urgent shifts so admins can reach them directly.

### Business Value

- Raises the fill rate of suddenly understaffed shifts by reaching workers who are actually eligible and actually willing to take urgent work, instead of blanket-broadcasting to an entire workplace.
- Widens the callable pool beyond a single workplace (nearby workplaces, plus habitual urgent workers company-wide) without spamming people who do not meet the shift's requirements.
- Gives admins a measurable signal — how many people the notification reached and how many opened it — so they can judge whether to widen the criteria or escalate.
- Lets admins advertise an on-the-spot cash bonus (Hot_Bonus) as an incentive without entangling it with the monthly payroll flow.

### Scope

**Included:**

- Detection and persistence of Urgent_Registration: a shift registration made after the employee has read a recruitment notification for that same shift.
- A new automatically maintained Frequent_Urgent_Worker_Group built on the existing `Group` / `user_groups` infrastructure.
- New Recruitment_Criteria fields on the admin notification form, persisted inside the existing `notifications.data` JSON column.
- A Recipient_Resolver that computes the final recipient list at send time and persists it to the new Notification_Recipient_List table.
- Targeted push delivery and in-app visibility scoping for recruitment notifications.
- A read/received statistics column on the admin notification list, shown only for recruitment notifications.

**Excluded:**

- Any change to salary calculation, hourly-rate resolution, or `salary_requests`. Hot_Bonus is display-only and is settled off-system between admin and employee.
- Any change to the shift-registration eligibility rules themselves, including the weekly working-hour limit. This feature only *applies* the existing rules as a filter; it does not alter, relax, or extend them.
- Any per-hour recruitment bonus folded into the payroll rate, same-day payout tracking, or a `shift_users.bonus_amount` column — these were considered and deliberately cut from this scope.
- Changes to the delivery mechanism or visibility of non-recruitment notification types.
- A new "headcount needed" field: the required headcount already exists on the shift and is reused for display only.
- The employee mobile application, which lives outside this repository. This feature is designed so that no mobile client change is required.

## Glossary

| Term | Definition |
|------|------------|
| Recruitment_Notification | A notification of type `NotificationType::RECRUITMENT`, created by an admin against a specific shift and workplace. |
| Recruitment_Criteria | The admin-supplied targeting and display settings for a Recruitment_Notification (gender target, experience requirement, nearby-workplace inclusion and radius, frequent-group inclusion, Hot_Bonus amount), stored in the existing `notifications.data` JSON column. |
| Hot_Bonus | A cash amount paid on the spot after the shift, entered by the admin purely to be displayed to employees and admins. The system does not track its payment and it never enters salary calculation. |
| Candidate_Pool | The de-duplicated union of users gathered from the selected workplace, optionally its nearby workplaces, and optionally the Frequent_Urgent_Worker_Group, before any Recruitment_Criteria filtering is applied. |
| Recipient_Resolver | The component that builds the Candidate_Pool, applies Recruitment_Criteria filtering and exclusions, and produces the final recipient list. |
| Notification_Recipient_List | The persisted set of users a Recruitment_Notification was actually sent to, holding only a notification reference and a user reference. It is the denominator for delivery statistics and the authority for in-app visibility. |
| Nearby_Workplace_Finder | The existing `App\Actions\Admin\Forecast\FindNearbyWorkplacesAction`, which returns workplaces within a given radius of a target workplace using stored latitude/longitude. |
| Nearby_Radius | The distance in kilometres used by the Nearby_Workplace_Finder, entered by the admin per notification and defaulting to 10 km. |
| Experience_Window | The trailing 3-month period used to decide whether an employee counts as experienced, evaluated across all workplaces rather than only the recruiting one. Experience requires an actual check-in; being assigned to a shift without turning up does not count. |
| Shift_Eligibility_Check | The existing `App\Helpers\WorkHelper::validateShiftRegister` gate, which rejects a shift registration when it overlaps another shift, breaches the workplace's minimum rest hours, exceeds the maximum shifts per day, or pushes the employee past their weekly working-hour limit. |
| Weekly_Hour_Limit | The maximum working hours per week applicable to a given employee, resolved by `WorkHelper::getMaxHourPerWeek` in the order `UserConfig` → `SystemConfig` → `config('work.max_hour_per_week')`. It defaults to 28 hours but is configurable per employee, so it must never be treated as a fixed constant. |
| Urgent_Registration | A `shift_users` row for a shift where the employee had already read a Recruitment_Notification for that same shift before the registration was created. |
| Frequent_Urgent_Worker_Group | An automatically maintained group of employees who actually checked in to at least 2 Urgent_Registration shifts in the trailing 90 days, expressed as a new `GroupKind` within the existing group system. |
| Read_State | An existing `notification_read_states` row recording that a user marked a notification as read, including rows created by the bulk "mark all as read" action. |
| Notification_Delivery | The existing `App\Jobs\PushNotificationToUser` job that pushes a notification through Firebase Cloud Messaging. |
| Notification_Form | The admin screen at `frontend/src/pages/manage/notification` used to create and update notifications. |
| Notification_List_Screen | The admin list of created notifications, where delivery statistics are displayed. |

## Requirements

### Requirement 1: Urgent Registration Tracking

**User Story:** As the system, I want to record which shift registrations were prompted by a recruitment notification, so that employees who habitually respond to urgent recruitment can be identified automatically.

#### Acceptance Criteria

1. WHEN a `shift_users` row is created for a shift THEN the system SHALL mark it as an Urgent_Registration if a Read_State exists for that user against any Recruitment_Notification whose target shift is the same shift, and that Read_State's `read_at` is earlier than or equal to the registration time.
2. IF no such Read_State exists at registration time THEN the system SHALL NOT mark the row as an Urgent_Registration, and the registration SHALL behave exactly as it does today.
3. WHERE multiple Recruitment_Notifications have been sent for the same shift, the system SHALL treat a Read_State against any one of them as sufficient.
4. WHEN a Read_State was created by the bulk "mark all as read" action rather than by opening the notification individually, the system SHALL still treat it as a valid Read_State for the purposes of criterion 1.
5. IF a Read_State for the shift's Recruitment_Notification is created *after* the registration already exists THEN the system SHALL NOT retroactively mark that registration as an Urgent_Registration.
6. WHEN an existing registration is later approved, rejected, or cancelled THEN the system SHALL leave its Urgent_Registration marking unchanged.
7. WHERE a registration is created by an admin assigning a user to a shift rather than by the user registering themselves, the system SHALL apply the same rule, since the marking depends only on whether the user had read the notification beforehand.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None. Requires no mobile client change, because the marking is derived server-side from existing Read_State data rather than from a parameter sent by the client.

### Requirement 2: Frequent Urgent Worker Group

**User Story:** As an admin, I want the system to maintain a group of employees who frequently accept urgent shifts, so that I can target them directly when I need to fill a shift quickly.

#### Acceptance Criteria

1. WHEN group memberships are recomputed THEN the system SHALL include in the Frequent_Urgent_Worker_Group every user who actually checked in to at least 2 Urgent_Registration shifts whose shift date falls within the trailing 90 days.
2. IF an Urgent_Registration exists but the employee never checked in to that shift THEN the system SHALL NOT count it toward the threshold, so that no-shows do not qualify a user as a frequent urgent worker.
3. IF a user's qualifying count within the trailing 90 days drops below 2 THEN the system SHALL remove that user from the Frequent_Urgent_Worker_Group on the next recomputation.
4. WHERE the admin group management screens list groups, the system SHALL display the Frequent_Urgent_Worker_Group alongside the other automatically maintained groups.
5. IF an admin attempts to add or remove members of the Frequent_Urgent_Worker_Group manually THEN the system SHALL reject the change, consistent with the handling of the other automatically maintained group kinds.
6. WHILE no user meets the threshold, the system SHALL present the Frequent_Urgent_Worker_Group as an existing but empty group rather than omitting it.
7. WHEN a recruitment notification that includes the Frequent_Urgent_Worker_Group is about to be sent THEN the system SHALL recompute the group's membership immediately beforehand, so that recipient resolution uses freshly computed membership rather than the result of the last scheduled recomputation.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1 (Urgent_Registration is the input signal). Builds on the existing `GroupKind`, `user_groups`, and group-recomputation infrastructure.

### Requirement 3: Recruitment Criteria Capture on Notification Creation

**User Story:** As an admin, I want to specify who a recruitment notification should reach and what incentive it advertises, so that the right people are asked and are given a reason to accept.

#### Acceptance Criteria

1. WHERE the Notification_Form has its type set to recruitment, the system SHALL display inputs for gender target, experience requirement, nearby-workplace inclusion, Nearby_Radius, Frequent_Urgent_Worker_Group inclusion, and Hot_Bonus amount.
2. WHERE the Notification_Form has any type other than recruitment, the system SHALL hide all Recruitment_Criteria inputs and SHALL NOT persist any Recruitment_Criteria.
3. WHEN the admin selects a gender target THEN the system SHALL accept exactly one of male, female, or both, defaulting to both.
4. WHEN the admin enables nearby-workplace inclusion THEN the system SHALL require a Nearby_Radius greater than 0, pre-filled with 10 km.
5. IF nearby-workplace inclusion is disabled THEN the system SHALL ignore any submitted Nearby_Radius value.
6. WHEN the admin submits a Hot_Bonus amount THEN the system SHALL accept either a non-negative integer amount or an empty value meaning no Hot_Bonus is advertised.
7. WHEN a recruitment notification is saved THEN the system SHALL persist all Recruitment_Criteria inside the existing `notifications.data` JSON column together with the existing `shift_id` and `workplace_id` keys.
8. IF a recruitment notification created before this feature is loaded for display or delivery THEN the system SHALL treat its missing Recruitment_Criteria as the defaults: gender target both, no experience requirement, no nearby workplaces, no group inclusion, no Hot_Bonus.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None. Reuses the existing `notifications.data` JSON column, so no schema change is needed for the criteria themselves.

### Requirement 4: Recipient Resolution and Filtering

**User Story:** As an admin, I want the notification to go only to employees who belong to a selected audience, satisfy the shift's requirements, have not already taken the shift, and are actually allowed to work it, so that I do not spam people who cannot or need not take it.

#### Acceptance Criteria

1. WHEN a recruitment notification is sent THEN the Recipient_Resolver SHALL build the Candidate_Pool as the de-duplicated union of: the users of the selected workplace; plus the users of every workplace returned by the Nearby_Workplace_Finder within the Nearby_Radius, if nearby inclusion is enabled; plus every member of the Frequent_Urgent_Worker_Group as recomputed immediately beforehand, if group inclusion is enabled.
2. WHERE Frequent_Urgent_Worker_Group inclusion is enabled, the Recipient_Resolver SHALL include the group's members regardless of which workplace they belong to and regardless of the Nearby_Radius.
3. WHEN the gender target is male or female THEN the Recipient_Resolver SHALL retain only Candidate_Pool users whose recorded gender matches the target **or** whose gender is not recorded.
4. WHEN the gender target is both THEN the Recipient_Resolver SHALL apply no gender-based exclusion.
5. IF the experience requirement is enabled THEN the Recipient_Resolver SHALL retain only Candidate_Pool users who actually checked in to at least one shift within the Experience_Window at any workplace; being assigned to a shift without checking in SHALL NOT satisfy the requirement.
6. WHEN filtering is applied THEN the Recipient_Resolver SHALL apply the gender and experience filters uniformly to every member of the Candidate_Pool, including users contributed by the Frequent_Urgent_Worker_Group and by nearby workplaces, so that no audience selection can bypass the criteria.
7. WHEN the recipient list is finalised THEN the Recipient_Resolver SHALL exclude every user who already has a `shift_users` row for the target shift.
8. WHEN the recipient list is finalised THEN the Recipient_Resolver SHALL apply the Shift_Eligibility_Check for the target shift to every remaining user and SHALL exclude every user for whom it reports any error, so that the notification only reaches employees who could actually accept the shift.
9. WHERE the Shift_Eligibility_Check evaluates the weekly working-hour limit, the system SHALL use each employee's own Weekly_Hour_Limit as resolved by the existing configuration precedence, rather than assuming a fixed 28 hours for everyone.
10. WHEN the Shift_Eligibility_Check is applied to the Candidate_Pool THEN the system SHALL reuse the existing eligibility logic unchanged rather than reimplementing any of its rules.
11. WHEN the recipient list is finalised THEN the system SHALL persist it to the Notification_Recipient_List before dispatching any push message.
12. WHERE a notification is scheduled for a future time, the system SHALL resolve the recipient list at the moment the notification is actually sent rather than at creation time, so that experience, group membership, and eligibility reflect the state at delivery.
13. IF the resolved recipient list is empty THEN the system SHALL still record the notification as sent with zero recipients and SHALL NOT fall back to broadcasting.

#### Additional Details

- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 2 (group membership) and Requirement 3 (criteria input). Reuses the existing `FindNearbyWorkplacesAction`, the `workplace_users` pivot, and `WorkHelper::validateShiftRegister` / `WorkHelper::getWorkHours`.

### Requirement 5: Targeted Delivery and Visibility Scoping

**User Story:** As an employee, I want to see only the recruitment notifications that were actually meant for me, so that my notification list stays relevant.

#### Acceptance Criteria

1. WHEN a recruitment notification is delivered THEN Notification_Delivery SHALL push it to the devices of the users in its Notification_Recipient_List individually, rather than to a workplace-wide topic.
2. WHERE an employee requests their in-app notification list, the system SHALL include a recruitment notification only if that employee appears in its Notification_Recipient_List.
3. WHERE an employee requests their unread count, the system SHALL apply the same recruitment visibility rule used for the list, so that the count never includes a recruitment notification the employee cannot open.
4. IF an employee who is not in the Notification_Recipient_List attempts to mark a recruitment notification as read THEN the system SHALL reject the request as forbidden.
5. WHEN the bulk "mark all as read" action runs THEN the system SHALL create Read_States only for the recruitment notifications the employee is entitled to see.
6. WHERE a notification is of any type other than recruitment, the system SHALL preserve its current topic-based delivery and its current visibility to all employees, with no behavioral change.
7. IF a recruitment notification created before this feature has no Notification_Recipient_List THEN the system SHALL keep it visible to all employees, so that historical notifications do not disappear from employees' lists.

#### Additional Details

- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 4 (the recipient list must exist before delivery). This is the breaking change identified in the Introduction.

### Requirement 6: Recruitment Details Visible to Employees

**User Story:** As an employee, I want a recruitment notification to tell me where the shift is, what it requires, and what incentive is offered, so that I can decide whether to accept it without opening several screens.

#### Acceptance Criteria

1. WHEN an employee views a recruitment notification THEN the system SHALL make available the workplace address, the shift's time information, and the shift's required headcount taken from the shift itself.
2. IF a Hot_Bonus amount was entered THEN the system SHALL make that amount available for display to both employees and admins.
3. IF no Hot_Bonus amount was entered THEN the system SHALL omit the Hot_Bonus from display rather than showing a zero value.
4. WHEN a recruitment notification specifies a gender target or an experience requirement THEN the system SHALL make those requirements available for display, so that recipients understand why they were contacted.
5. WHERE a Hot_Bonus is displayed, the system SHALL NOT create any payment record, payment status, or salary entry for it.

#### Additional Details

- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 3 (criteria must be persisted to be displayed). Rendering inside the employee mobile app is outside this repository; this requirement covers exposing the data through the API.

### Requirement 7: Delivery Statistics on the Admin Notification List

**User Story:** As an admin, I want to see how many people received a recruitment notification and how many opened it, so that I can judge whether to widen the criteria or take further action.

#### Acceptance Criteria

1. WHERE the Notification_List_Screen displays a recruitment notification, the system SHALL show a statistic expressing the number of recipients who have read it out of the total number of recipients.
2. WHERE the Notification_List_Screen displays a notification of any other type, the system SHALL leave the statistic empty.
3. WHEN computing the read count THEN the system SHALL count only Read_States belonging to users present in that notification's Notification_Recipient_List, so that the read count can never exceed the recipient count.
4. WHEN computing the recipient count THEN the system SHALL count the persisted Notification_Recipient_List rather than re-evaluating the criteria, so that the figure reflects who was actually messaged and does not drift as employees register for the shift afterwards.
5. IF a recruitment notification has not yet been sent THEN the system SHALL indicate that no recipients have been resolved yet, instead of showing zero out of zero.
6. WHEN the Notification_List_Screen loads a page of notifications THEN the system SHALL produce the statistics without issuing per-row queries.

#### Additional Details

- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 4 (the Notification_Recipient_List is the denominator).

## Non-Functional Requirements

### Performance Requirements

- WHEN the Recipient_Resolver runs for a workplace whose Candidate_Pool spans nearby workplaces and the Frequent_Urgent_Worker_Group, the system SHALL resolve and persist the recipient list using set-based queries and bulk inserts rather than per-user queries.
- WHEN the Shift_Eligibility_Check is applied to the Candidate_Pool, the system SHALL fetch the underlying work-hour data for all candidates in a single batched call rather than once per candidate.
- WHEN the Notification_List_Screen loads a paginated page, the system SHALL compute all delivery statistics using aggregate queries, avoiding N+1 behavior.
- WHEN an employee loads their notification list, the added recruitment visibility condition SHALL be satisfiable by an index on the Notification_Recipient_List, so that list latency does not regress noticeably.

### Security Requirements

- WHEN an employee requests a recruitment notification they are not a recipient of, the system SHALL deny access rather than returning its content.
- WHEN Recruitment_Criteria are submitted, the system SHALL validate every field server-side, rejecting unknown gender targets, non-positive radii when nearby inclusion is enabled, and negative Hot_Bonus amounts.
- WHERE recruitment notification management endpoints are exposed, the system SHALL enforce the same administrative permission checks that already guard notification creation.

### Usability Requirements

- WHEN an admin switches the notification type on the Notification_Form, the system SHALL show or hide the Recruitment_Criteria inputs immediately without requiring a page reload.
- WHEN an admin enables nearby-workplace inclusion, the system SHALL pre-fill the Nearby_Radius with 10 km so that the common case requires no typing.
- WHERE any user-facing text is added by this feature, the system SHALL provide it through the translation function rather than as hardcoded strings, in English, Vietnamese, and Japanese.

### Reliability Requirements

- IF recipient resolution or persistence fails partway through THEN the system SHALL NOT leave the notification marked as sent with a partial recipient list.
- IF pushing to an individual recipient's device fails THEN the system SHALL still record that user as a recipient and SHALL NOT abort delivery to the remaining recipients.
- WHEN group recomputation runs while a recruitment notification is being resolved, the system SHALL produce a recipient list consistent with a single point-in-time read of group membership.

## Constraints and Assumptions

### Technical Constraints

- Gender is stored on `user_infos.gender` as a nullable string backed by the `Gender` enum (male, female, other); a substantial number of employees may have no recorded gender, which is why unrecorded gender is never a reason for exclusion.
- Workplace proximity is derived at query time from the latitude/longitude stored on `workplaces`; there is no persisted adjacency table and none is introduced. The forecast screen's 10 km value is a hardcoded default rather than a saved setting, so the radius is entered per notification.
- Firebase Cloud Messaging topics cannot address a filtered subset of subscribers, which forces recruitment notifications onto per-user delivery.
- `notification_read_states` records only users who marked a notification as read, and rows are also produced by the bulk "mark all as read" action. It therefore cannot serve as a record of who a notification was sent to, which is why the Notification_Recipient_List is introduced.
- The employee mobile application is not in this repository, so the design must not require any mobile client change.
- The weekly working-hour limit is configurable per employee and per system, so eligibility filtering cannot be expressed as a single shared threshold and must be evaluated per candidate through the existing resolution order.
- The Shift_Eligibility_Check depends on each candidate's surrounding schedule, so eligibility can change between notification creation and delivery; this is why it is evaluated at send time together with the rest of the filtering.

### Assumptions

- Admins prefer reaching a slightly wider audience over missing eligible workers, which justifies including employees with unrecorded gender.
- Members of the Frequent_Urgent_Worker_Group have demonstrated willingness to travel for urgent work, which justifies contacting them irrespective of distance.
- The required headcount already recorded on the shift is sufficient for display; admins control over-filling by simply not approving surplus registrations.
- Hot_Bonus settlement happens face-to-face after the shift and deliberately stays outside the system of record.
- Recruitment notifications created before this feature are rare enough that keeping them visible to everyone is acceptable.

## Success Criteria

### Definition of Done

- [ ] All acceptance criteria in Requirements 1 through 7 are met.
- [ ] Non-functional requirements for performance, security, usability, and reliability are satisfied.
- [ ] A recruitment notification created with every criterion enabled reaches exactly the expected set of employees, verified against a seeded dataset covering nearby workplaces, group members, unrecorded gender, employees already assigned to the shift, and employees excluded by each of the four Shift_Eligibility_Check rules.
- [ ] An employee whose own Weekly_Hour_Limit differs from the system default is filtered according to their own limit, not the default.
- [ ] An employee outside the recipient list can neither see nor read the notification, and their unread count is unaffected.
- [ ] Non-recruitment notifications are confirmed to be delivered and displayed exactly as before.
- [ ] The read/received statistic never exceeds 100%, including after employees use "mark all as read", and does not change as employees register for the shift after delivery.
- [ ] Salary calculation output is confirmed unchanged for a dataset containing Urgent_Registrations.
- [ ] Backend tests cover Urgent_Registration detection, group recomputation, recipient resolution, eligibility filtering, and visibility scoping; frontend checks (`pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`) and backend checks (`npm run prettier`, `npm run pint`) pass.
- [ ] All new user-facing text exists in the English, Vietnamese, and Japanese locale files.
- [ ] An employee assigned to shifts but with no check-ins is excluded by the experience filter and does not qualify for the Frequent_Urgent_Worker_Group.
