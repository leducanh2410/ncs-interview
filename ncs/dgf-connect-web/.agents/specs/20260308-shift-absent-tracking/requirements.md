# Requirements Document

## Introduction

This feature adds an `is_absent` field to the `shift_users` table to track when an approved employee did not attend their scheduled shift. The system currently counts all approved shifts toward work hours, even if the employee didn't check in. With this change, shifts marked as absent will be excluded from work hour calculations. Managers can toggle the absent status via a button in the shift info modal on the schedule page. There are no breaking changes.

### Feature Summary

Absent tracking for approved shifts with manual toggle and automatic work hour recalculation.

### Business Value

- Accurately track employee attendance by distinguishing between approved shifts and actual attendance
- Prevent work hour inflation from approved-but-missed shifts
- Give managers flexibility to mark absences after the fact

### Scope

**Included:**
- New `is_absent` boolean field on `shift_users` table (default: false)
- Update work hour calculation logic to exclude shifts where `is_absent = true`
- Frontend toggle button in shift info modal (only for approved shifts)
- Backend API endpoint to toggle absent status
- Confirmation modal before toggling
- Automatic schedule refresh after toggle

**Excluded:**
- Automatic absence detection (still requires manual toggle)
- Absence reason/notes tracking
- Bulk absence marking
- Retroactive work hour recalculation for already processed salaries
- Notifications for absence marking

## Glossary

| Term | Definition |
|------|------------|
| Is_Absent | Boolean flag indicating an employee did not attend an approved shift |
| Approved_Shift | A shift assignment with status = 'Approved' in the shift_users table |
| Work_Hour_Calculation | The process of computing total work hours from check-in/check-out data or shift duration |
| Shift_Info_Modal | The modal dialog displayed when clicking on a calendar card in the schedule view |
| Schedule_Page | The manager view at `/manage/user/:id/schedule` showing employee's weekly/monthly calendar |
| Toggle_Absent | The action of setting or unsetting the is_absent flag via the frontend UI |

## Requirements

### Requirement 1: Database Schema Change

**User Story:** As a system, I need a new `is_absent` column in the `shift_users` table, so that I can track which approved shifts were missed.

#### Acceptance Criteria

1. WHEN the migration is run THEN the system SHALL add a boolean column `is_absent` to the `shift_users` table after the `is_reported` column
2. WHEN the migration is run THEN the system SHALL set the default value of `is_absent` to `false` for all existing records
3. WHEN a new shift_user record is created THEN the system SHALL default `is_absent` to `false` if not specified
4. IF the migration fails THEN the system SHALL rollback all changes

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None

### Requirement 2: Work Hour Calculation Update

**User Story:** As a payroll system, I need to exclude absent shifts from work hour calculations, so that employees are not credited for shifts they missed.

#### Acceptance Criteria

1. WHEN calculating work hours via `WorkHelper::getWorkHours()` THEN the system SHALL exclude shift_user records where `is_absent = true`
2. WHEN a shift has `status = 'Approved'` AND `is_absent = true` THEN the system SHALL NOT include that shift in the user's work hour total
3. WHEN a shift has `status = 'Approved'` AND `is_absent = false` THEN the system SHALL include that shift in the work hour calculation (existing behavior)
4. WHEN the shift has check-in data THEN the system SHALL use the actual worked minutes regardless of `is_absent` value (check-in takes precedence)
5. IF `is_absent = true` AND no check-in exists THEN the system SHALL count 0 hours for that shift

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1 (database column must exist)

### Requirement 3: Shift Info Modal Enhancement

**User Story:** As a manager, I want to toggle the absent status for approved shifts from the shift info modal, so that I can quickly mark absences without navigating to another page.

#### Acceptance Criteria

1. WHEN the shift info modal is opened for an approved shift THEN the modal SHALL display a "Mark as Absent" button (or "Mark as Present" if already absent)
2. WHEN the shift info modal is opened for a non-approved shift THEN the modal SHALL NOT display the absent toggle button
3. WHEN the manager clicks the absent toggle button THEN the system SHALL display a confirmation modal asking for confirmation
4. IF the manager confirms the action THEN the system SHALL call the toggle API and refresh the schedule data
5. IF the manager cancels the action THEN the system SHALL close the confirmation modal without making changes
6. WHEN the toggle is successful THEN the system SHALL show a success toast notification
7. IF the toggle fails THEN the system SHALL show an error toast notification

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4 (API endpoint)

### Requirement 4: Toggle Absent API Endpoint

**User Story:** As a frontend application, I need an API endpoint to toggle the absent status, so that I can update the shift_user record when the manager clicks the toggle button.

#### Acceptance Criteria

1. WHEN the endpoint receives a valid request THEN the system SHALL toggle the `is_absent` field (true ↔ false) for the specified shift_user
2. WHEN the toggle is successful THEN the system SHALL return the updated shift_user record
3. IF the shift_user does not exist THEN the system SHALL return a 404 error
4. IF the shift status is not 'Approved' THEN the system SHALL return a 400 error
5. IF the caller lacks the required permission THEN the system SHALL return a 403 error
6. WHEN the shift_user is updated THEN the system SHALL update the `updated_at` timestamp

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1 (database column must exist)

### Requirement 5: Schedule Data Refresh

**User Story:** As a manager, I want the schedule view to automatically refresh after toggling absent status, so that I can see the updated work hour calculation immediately.

#### Acceptance Criteria

1. WHEN the toggle API returns success THEN the frontend SHALL invalidate the schedule query cache
2. WHEN the query cache is invalidated THEN the frontend SHALL refetch the schedule data for the current date range
3. WHEN the schedule data is refetched THEN the calendar cards SHALL update to reflect new work hour calculations
4. IF the refetch fails THEN the frontend SHALL show an error notification but keep the modal closed

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 4 (API endpoint)

## Non-Functional Requirements

### Performance Requirements

- WHEN toggling absent status THEN the API SHALL respond within 500ms
- WHEN refreshing schedule data THEN the frontend SHALL display loading state during refetch

### Security Requirements

- WHEN toggling absent status THEN the system SHALL verify the user has manage shift permissions
- WHEN the API is called THEN the system SHALL validate the shift_user belongs to the requested user

### Usability Requirements

- WHEN the confirmation modal is displayed THEN it SHALL clearly indicate whether the shift will be marked as absent or present
- WHEN the button is displayed THEN it SHALL use appropriate color (red for marking absent, green for marking present)

### Reliability Requirements

- WHEN the toggle operation fails THEN the system SHALL NOT update the UI state
- WHEN the database transaction fails THEN the system SHALL rollback all changes

## Constraints and Assumptions

### Technical Constraints

- Must use existing Action pattern and ResponseHelper for API responses
- Must use React Query for cache invalidation and refetching
- Must use Mantine modal and button components consistent with existing UI
- Database migration must be reversible (up/down methods)

### Assumptions

- Managers understand that marking a shift as absent will affect work hour calculations
- Absence marking does not affect salary calculations that have already been processed
- Shifts with check-in data should not be marked as absent (but the system won't prevent this)

## Success Criteria

### Definition of Done

- [ ] Database migration created and tested
- [ ] WorkHelper::getWorkHours() excludes absent shifts
- [ ] Toggle API endpoint created with proper validation
- [ ] Frontend toggle button shows only for approved shifts
- [ ] Confirmation modal displays before toggling
- [ ] Schedule refreshes after successful toggle
- [ ] All acceptance criteria met
- [ ] Code passes `pnpm typecheck`, `pnpm prettier`, `npm run prettier`, `npm run pint`
