# Requirements Document

## Introduction

The Vote/Feedback feature enables administrators to create structured polls and feedback forms that are distributed to employees through backend APIs and push notifications. Both vote and feedback use a unified Poll model with a title, optional description, and multiple options. In a vote, options are candidate users; in feedback, options are predefined text choices. This repository covers the Laravel APIs used by employee mobile clients and the Next.js admin management UI. The employee voting UI itself is implemented in the mobile app and is out of scope for this repository.

### Feature Summary

A unified poll system that allows admins to create, activate, and review vote or feedback polls while employee mobile clients receive pending poll payloads and submit responses through backend APIs.

### Business Value

- Enables structured employee voting for role-based or workplace decisions
- Provides a standardized channel for collecting employee feedback on workplace topics
- Reduces manual coordination overhead for polls and surveys
- Creates an auditable record of participation and responses

### Scope

**Included:**
- Admin CRUD for poll configurations, with type discriminator (`vote` or `feedback`)
- Two poll scopes: global and workplace-scoped
- Vote type: options are candidate users, with per-candidate yes (`1`) / no (`-1`) / blank (`0`) evaluation and configurable `max_choice` for yes votes
- Feedback type: options are predefined choices with backend-generated stable IDs and multilingual labels
- Shared config: `is_multiple` flag and `max_choice`
- Two creation modes: "Create draft" (inactive) and "Create and send" (active immediately)
- Push notification delivery via FCM when a poll becomes active or when a user becomes newly eligible
- `has_pending_votes` flag on users as the fast-path check for employee auth responses
- Eligibility recalculation when a poll becomes active and when a user is assigned a `staff_no` for the first time
- Admin per-poll results view with aggregated counts and individual responses
- Denormalized result counters initialized at config creation for efficient aggregation at scale
- Deactivation support (sets poll inactive without recalculating flags; cleanup on next auth check)
- Expiration date support

**Excluded:**
- Employee voting UI implementation in this repository
- Anonymous voting
- Free-text or comment responses
- User-visible results or analytics
- Ability to change response after submission
- Participation rate reporting
- Cross-poll or workplace-filtered result dashboards
- Recurring or scheduled polls

## Glossary

| Term | Definition |
|------|------------|
| Poll | A unified poll created by an admin defining either a vote or feedback session |
| Poll_Type | Discriminator: `vote` (options are candidate users) or `feedback` (options are text choices) |
| Poll_Option | An item in a poll that users can select or evaluate; called "candidate" for vote type, "choice" for feedback type |
| Eligible_Employee | An employee user with a non-null `staff_no`; for workplace-scoped polls, the employee must also belong to the target workplace |
| Poll_Response | A user's recorded answer for a poll config: per-candidate scores for vote, selected choice IDs for feedback |
| Max_Choice | The maximum number of yes votes for vote type, or the maximum number of selected choices for feedback type |
| Result_Counters | Denormalized per-option aggregated counts stored on Poll and updated on each submission |
| User_Client | The employee-facing mobile application that consumes pending poll payloads and submits responses |
| Admin_Panel | The admin web UI in this repository used to manage poll configs and view results |

## Requirements

### Requirement 1: Admin Poll Configuration Management

**User Story:** As an admin, I want to create and manage poll configurations with options and rules, so that I can organize structured voting sessions or feedback collection for employees.

#### Acceptance Criteria

1. WHEN an admin creates a poll THEN Poll SHALL store: type (`vote` or `feedback`), multilingual titles (`vi`, `en`, `ja`), optional multilingual description, scope (`global` or `workplace`, derived from `workplace_id`), required flag, expiration date, `is_multiple`, `max_choice`, and list of options
2. WHEN Poll_Type is `vote` THEN Poll SHALL store options as candidate user IDs, and Poll_Response SHALL store per-candidate yes (`1`), no (`-1`), or blank (`0`) evaluations
3. WHEN Poll_Type is `feedback` THEN Poll SHALL store options as predefined choices with stable backend-generated IDs and multilingual labels, and Poll_Response SHALL store selected choice IDs
4. WHEN an admin sets `workplace_id` THEN Poll SHALL be workplace-scoped; when `workplace_id` is null THEN Poll SHALL be global-scoped
5. WHEN `is_multiple` is `false` THEN Poll_Config SHALL enforce `max_choice = 1`
6. WHEN an admin omits `max_choice` for multiple selection THEN Poll_Config SHALL default `max_choice` to the total number of options
7. WHEN an admin selects "Create draft" THEN Poll SHALL be created with `is_active = false`
8. WHEN an admin selects "Create and send" THEN Poll SHALL be created with `is_active = true` and Poll_Service SHALL evaluate currently eligible employees, set `has_pending_votes = true` for users with unresponded eligible polls, and dispatch notifications
9. `is_active` SHALL NOT be updatable via the general update endpoint; admins SHALL use dedicated activate and deactivate endpoints
10. WHEN an admin deactivates an active poll THEN the system SHALL set `is_active = false` without recalculating `has_pending_votes` flags; stale flags are cleaned up on the next employee auth check
11. IF a Poll has existing responses THEN Admin_Panel SHALL prevent modification of options, `is_multiple`, and `max_choice`
12. IF a Poll has existing responses THEN Admin_Panel SHALL prevent deletion
13. WHEN an admin deletes a Poll with no responses THEN the system SHALL remove the poll and related response data

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Existing user, workplace, and notification infrastructure

### Requirement 2: User Eligibility and Distribution

**User Story:** As a system, I want to determine eligible employees and notify them when polls are available, so that the right users receive poll requests at the correct time.

#### Acceptance Criteria

1. WHEN a poll becomes active, whether by "Create and send" or draft activation, THEN Poll_Service SHALL evaluate the current Eligible_Employee set for that poll and mark matching unresponded users as pending
2. WHEN a user is assigned a `staff_no` for the first time THEN Poll_Service SHALL evaluate active unresponded polls for that user and set `has_pending_votes` accordingly
3. WHERE a poll is global THEN Eligible_Employee SHALL mean a user with a non-null `staff_no` (poll has null `workplace_id`)
4. WHERE a poll is workplace-scoped THEN Eligible_Employee SHALL mean a user with a non-null `staff_no` and a record in `workplace_users` for the poll's `workplace_id`
5. WHEN a user becomes newly eligible for at least one active unresponded poll THEN the system SHALL send an FCM push notification with poll details
6. IF a poll's expiration date has passed THEN Poll_Service SHALL exclude that poll from pending calculations and notifications
7. WHILE a user has at least one active, unresponded, eligible poll THEN `users.has_pending_votes` SHALL remain `true`
8. WHILE a user has no active, unresponded, eligible polls THEN `users.has_pending_votes` SHALL be `false`

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Existing FCM infrastructure and existing user update flows that assign `staff_no`

### Requirement 3: Poll Submission

**User Story:** As an employee using the mobile app, I want to submit my poll selections, so that I can participate in workplace decisions or provide feedback.

#### Acceptance Criteria

1. WHEN a user submits a poll response THEN Poll_Response SHALL record the answer together with IP address and device info
2. WHEN Poll_Type is `vote` THEN Poll_Response SHALL require an answer entry for every candidate, each value SHALL be one of `1`, `-1`, or `0`, and the number of `1` values SHALL NOT exceed `max_choice`
3. WHEN Poll_Type is `feedback` THEN Poll_Response SHALL require selected choice IDs from the poll options; if `is_multiple` is `false`, exactly one choice SHALL be selected, otherwise between one and `max_choice` choices SHALL be selected
4. IF the user has already submitted a response for the poll THEN the system SHALL reject the submission
5. IF the poll is inactive or expired THEN the system SHALL reject the submission
6. IF the user is not currently eligible for the poll THEN the system SHALL reject the submission
7. WHEN a response is successfully submitted THEN Poll_Service SHALL update the poll's Result_Counters within the same transaction and re-evaluate `has_pending_votes`
8. WHILE processing a submission THEN the system SHALL enforce one response per user per poll

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 1 and 2

### Requirement 4: Pending Poll Payload for User Clients

**User Story:** As a mobile user client, I want the auth response to include pending polls, so that the mobile app can display and submit them.

#### Acceptance Criteria

1. WHEN an employee user authenticates or performs an auth check THEN Auth_API SHALL return pending, unresponded, non-expired, active polls in the response payload when `has_pending_votes = true`
2. IF `has_pending_votes = false` THEN Auth_API SHALL skip detailed pending poll lookup and SHALL return an empty pending poll payload
3. IF `has_pending_votes = true` but no pending polls are found (all expired or deactivated) THEN Auth_API SHALL reset `has_pending_votes` to `false` and return an empty payload
4. WHERE the authenticated principal is an employee user THEN Auth_API SHALL include a `pending_votes` field in the auth payload
5. WHERE a pending item is `vote` type THEN the payload SHALL include candidate option IDs and display names
6. WHERE a pending item is `feedback` type THEN the payload SHALL include stable choice IDs and multilingual labels
7. WHILE a poll remains pending THEN the payload SHALL include `is_required`, `expires_at`, `is_multiple`, and `max_choice`

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 2 and 3; existing auth flow

### Requirement 5: Admin Per-Poll Results View

**User Story:** As an admin, I want to view the results for a specific poll, so that I can analyze outcomes and inspect individual responses.

#### Acceptance Criteria

1. WHEN an admin views a vote-type poll's results THEN Admin_Panel SHALL display per-candidate aggregated counts for yes, no, and blank from Result_Counters, together with a paginated list of individual responses
2. WHEN an admin views a feedback-type poll's results THEN Admin_Panel SHALL display per-choice aggregated counts from Result_Counters, together with a paginated list of individual responses
3. WHERE results are accessed in Admin_Panel THEN the UI SHALL scope the view to a single Poll at a time
4. WHEN Admin_Panel requests individual responses THEN the API SHALL support pagination
5. IF a non-admin user accesses admin result endpoints THEN the system SHALL deny access

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirements 1 and 3

## Non-Functional Requirements

### Performance Requirements
- WHEN Auth_API returns pending polls THEN the response SHALL complete within 500ms for users with up to 10 pending items
- WHEN an admin views poll results THEN aggregated counts SHALL be read from Poll.Result_Counters instead of recomputing from Poll_Response rows
- WHEN a response is submitted THEN the system SHALL update Poll_Response and Result_Counters atomically within one database transaction
- WHEN a poll becomes active THEN pending-flag updates and notification dispatch SHALL process eligible users in chunks suitable for large user sets

### Security Requirements
- WHEN a user submits a poll response THEN the system SHALL verify current eligibility based on `staff_no`, poll scope, and workplace membership where applicable
- WHEN a poll API is called THEN the system SHALL authenticate the caller and verify the caller has not already responded
- WHEN admin endpoints are accessed THEN the system SHALL verify admin role authorization and permission-based access

### Usability Requirements
- WHEN an admin creates or edits a poll config THEN Admin_Panel SHALL present multilingual fields and type-specific validation messages
- WHEN an admin views a poll result page THEN Admin_Panel SHALL present aggregated data and individual responses with clear labels and timestamps

### Reliability Requirements
- WHEN recording a poll response THEN the system SHALL use database transactions to guarantee atomic writes
- WHEN duplicate submissions occur concurrently THEN the system SHALL preserve a single successful response per user and reject the duplicate
- WHEN pending eligibility is recalculated THEN the system SHALL derive `has_pending_votes` from current eligible unresponded polls instead of relying on stale cached state

## Constraints and Assumptions

### Technical Constraints
- Must follow the architectural patterns used by the existing backend and admin web codebase
- Backend runs inside Docker for local development
- Admin web UI uses Mantine UI components
- Push notifications use the existing FCM infrastructure
- Employee voting UI is implemented in the mobile app, not in this repository

### Assumptions
- First `staff_no` assignment is the business event that makes an employee eligible for poll distribution
- Workplace membership is available from existing user-workplace associations
- Admin creates poll configs infrequently
- Users typically have only a small number of pending poll items at a time
- Optional poll display and skip behavior are handled in the mobile app and are out of scope for this repository

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] Admin can create, update, activate, deactivate, and delete polls for both vote and feedback types
- [ ] Eligible employees are flagged and notified when polls become active
- [ ] First `staff_no` assignment triggers pending poll evaluation for that user
- [ ] Employee auth responses expose `pending_votes` for mobile clients
- [ ] Poll responses are recorded with IP and device info and remain immutable after submission
- [ ] Admin can view per-poll aggregated results and paginated individual responses
- [ ] Backend passes PHPUnit tests
- [ ] Frontend admin pages pass `pnpm typecheck` and `pnpm prettier`
- [ ] All user-facing admin web text uses translation keys
