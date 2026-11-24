# Requirements Document

## Introduction

This feature allows employees to proactively notify managers that they will be absent for an approved shift, via the mobile app API. The notice must be submitted no later than a configurable number of hours (default: 12) before the shift starts. Absent notices are stored in a dedicated `shift_absent_notices` table (foreign key to `shift_users.id`), are final (no cancellation), and do not require admin approval. When a notice is submitted, an email is sent to a configurable list of notification recipients (`SYSTEM_NOTIFICATION_EMAILS`). On the management web app, a new page lists all absent-notice shifts with filters and pagination. The existing `is_absent` flag on `shift_users` remains independent and is not auto-synced.

## Glossary

- **Absent_Notice**: A record in the `shift_absent_notices` table indicating an employee has declared they will not attend an approved shift
- **Notice_Deadline**: The configurable cutoff time (default 12 hours) before a shift starts, after which an Absent_Notice can no longer be submitted
- **Shift_User**: A record in the `shift_users` pivot table linking a user to a specific shift, with status and approval info
- **Approved_Shift**: A Shift_User record with `status = 'approved'`
- **Notice_List_Page**: The management web page displaying a paginated, filterable list of Absent_Notices
- **Manager_Email**: The email notification sent to eligible managers when an Absent_Notice is created

## Requirements

### Requirement 1: Absent Notice Database Table

**User Story:** As a system, I need a dedicated table to store absent notices, so that employee-initiated absence declarations are tracked separately from manager-toggled absence flags.

#### Acceptance Criteria

1. THE migration SHALL create a `shift_absent_notices` table with columns: `id`, `shift_user_id` (foreign key to `shift_users.id`), `created_at`, `updated_at`
2. THE `shift_user_id` column SHALL have a unique constraint to prevent duplicate notices for the same shift assignment
3. THE foreign key SHALL cascade on delete of the parent `shift_users` record
4. IF the migration is rolled back, THEN the system SHALL drop the `shift_absent_notices` table

### Requirement 2: Configurable Notice Deadline

**User Story:** As an administrator, I want the notice deadline to be configurable via environment variables, so that the cutoff time can be adjusted without code changes.

#### Acceptance Criteria

1. THE system SHALL read the notice deadline from `config('work.absent_notice.before_hour')` with a default of 12 hours
2. THE environment variable `ABSENT_NOTICE_BEFORE_HOUR` SHALL control the deadline value
3. WHEN the config value is set to N, THEN the Notice_Deadline SHALL be N hours before the shift start time
4. THE system SHALL read notification recipients from `config('work.system_notification_emails')`, sourced from the `SYSTEM_NOTIFICATION_EMAILS` environment variable (comma-separated list of email addresses). This config key is general-purpose and will be reused by other notification features

### Requirement 3: Submit Absent Notice (Mobile API)

**User Story:** As an employee, I want to notify managers that I will be absent for an upcoming approved shift, so that they can plan accordingly.

#### Acceptance Criteria

1. THE system SHALL expose a `POST /user/my-schedules/{shiftUser}/absent-notice` endpoint for authenticated employees
2. WHEN the employee submits an Absent_Notice, THE system SHALL validate that the Shift_User belongs to the authenticated user
3. WHEN the employee submits an Absent_Notice, THE system SHALL validate that the Shift_User status is `approved`
4. WHEN the employee submits an Absent_Notice, THE system SHALL validate that the current time is at least Notice_Deadline hours before the shift start time
5. IF validation passes, THEN THE system SHALL create a record in `shift_absent_notices` and return a 201 response
6. IF the Shift_User does not belong to the authenticated user, THEN THE system SHALL return a 403 error
7. IF the Shift_User status is not `approved`, THEN THE system SHALL return a 400 error with an appropriate error code
8. IF the current time is past the Notice_Deadline, THEN THE system SHALL return a 400 error indicating the deadline has passed
9. IF an Absent_Notice already exists for the Shift_User, THEN THE system SHALL return a 409 conflict error

### Requirement 4: Email Notification to Managers

**User Story:** As a manager, I want to receive an email when an employee submits an absent notice, so that I am promptly informed and can adjust staffing.

#### Acceptance Criteria

1. WHEN an Absent_Notice is successfully created, THE system SHALL queue an email to the list of addresses defined in `config('work.system_notification_emails')`, sourced from the `SYSTEM_NOTIFICATION_EMAILS` environment variable
2. THE Manager_Email SHALL include: employee name, shift date, workplace name, shift time, and position
3. THE Manager_Email SHALL be dispatched via Laravel's queue system (`Mail::to()->queue()`)
4. IF the `SYSTEM_NOTIFICATION_EMAILS` list is empty or not configured, THEN THE system SHALL skip email sending without error

### Requirement 5: No Cancellation

**User Story:** As a system, I need to ensure that submitted absent notices cannot be withdrawn, so that managers can rely on the information.

#### Acceptance Criteria

1. THE system SHALL NOT provide any endpoint to delete or cancel an Absent_Notice
2. THE Absent_Notice record SHALL be immutable after creation

### Requirement 6: Management List Page (Backend)

**User Story:** As a manager, I want a paginated API endpoint to list absent notices with filters, so that I can view and manage reported absences.

#### Acceptance Criteria

1. THE system SHALL expose a `GET /manage/absent-notices` endpoint requiring the `shift.list` permission
2. THE endpoint SHALL accept filter parameters: `date_from` (default: today), `date_to` (default: today + 7 days), `workplace_id` (optional)
3. THE endpoint SHALL return paginated results with fields: shift date, workplace name, shift time (start-end), employee name, position name, requested at (Absent_Notice created_at)
4. THE results SHALL be sorted by shift date ascending, then by requested at ascending
5. WHEN no filters are provided, THEN the endpoint SHALL default to shifts from today through 7 days ahead

### Requirement 7: Management List Page (Frontend)

**User Story:** As a manager, I want a web page to view all absent-notice shifts with filters, so that I can quickly see upcoming absences and plan coverage.

#### Acceptance Criteria

1. THE Notice_List_Page SHALL be accessible at a route under the management section, requiring the same permissions as shift management (`shift.list`)
2. THE Notice_List_Page SHALL display filter controls: shift date range picker (default: today to today + 7 days) and workplace select dropdown
3. THE Notice_List_Page SHALL display a paginated table with columns: shift date, workplace, shift time, user (employee name), position, requested at
4. WHEN filters are changed, THEN the Notice_List_Page SHALL refetch data from the backend
5. THE Notice_List_Page SHALL follow existing management page patterns (layout, styling, pagination controls)
