# Requirements Document

## Introduction

This feature allows managers with proper permissions to create employee accounts directly from the admin panel. The creation flow mirrors the existing user self-registration process using the same cache-key-based session pattern and multi-step flow (init → set password → submit profile), but skips the OTP email verification step. The init step validates the email, queries CrossStaff for auto-fill data, and returns a cache key to the frontend — keeping all CrossStaff data mapping on the backend. The admin sets (or generates) a password, which is sent to the employee via email upon completion. There are no breaking changes.

### Feature Summary

Admin employee creation with full profile data, CrossStaff auto-fill, and password delivery via email.

### Business Value

- Reduces onboarding friction by allowing managers to register employees on their behalf
- Eliminates the need for employees to self-register when their information is already available
- Maintains data consistency by reusing the same profile fields and validation as self-registration

### Scope

**Included:**
- New backend API endpoints mirroring the registration 3-step flow (init, set-password, register)
- New frontend multi-step page with full profile form
- CrossStaff lookup during init step (handled on backend, data returned to frontend)
- Password generation (manual or random) with email delivery
- New permission for this action
- User role auto-assignment

**Excluded:**
- OTP/email verification flow
- Paper auto-approval (employee goes through normal paper workflow)
- Modifying the existing self-registration flow
- Bulk employee creation

## Glossary

| Term | Definition |
|------|------------|
| Employee | A user with the "User" role created by a manager through the admin panel |
| CrossStaff | External workforce management system that may contain pre-existing employee data |
| Paper_Status | Document verification status that controls feature access (Registered, Pending, WaitForApproval, Approved, Rejected) |
| Admin_Panel | The manager/admin section of the application at `/admin/user` |
| Create_Employee_Page | The new multi-step page for creating an employee account (init → password → profile) |
| Cache_Session | A temporary server-side cache entry (keyed by a random string) that stores the email, CrossStaff data, and verification state across steps |
| CrossStaff_Lookup | The process of searching the CrossStaff API by email to retrieve pre-existing employee data, performed during the init step |

## Requirements

### Requirement 1: Employee Init with CrossStaff Lookup

**User Story:** As a manager, I want to enter an employee's email and have the system look up their data in CrossStaff, so that existing data is auto-filled and I don't have to enter it manually.

#### Acceptance Criteria

1. WHEN the manager submits an email in the init step THEN the system SHALL validate email uniqueness, query CrossStaff, create a Cache_Session, and return the cache key along with any CrossStaff data
2. IF the email is found in CrossStaff THEN the system SHALL return the mapped user data (name, kana, birthday, gender, nationality, address, phone, residence card, passport) and Create_Employee_Page SHALL pre-fill the profile form fields
3. IF the email is not found in CrossStaff THEN the system SHALL return user: null and Create_Employee_Page SHALL leave all profile fields empty for manual entry
4. IF the CrossStaff API call fails THEN the system SHALL still create the Cache_Session and return user: null, allowing manual entry
5. WHEN fields are pre-filled from CrossStaff THEN Create_Employee_Page SHALL allow the manager to override any pre-filled value

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: CrossStaff API integration (existing `CrossStaffApiService`), Cache

### Requirement 2: Password Step

**User Story:** As a manager, I want to set a password for the employee (or auto-generate one), so that the employee has login credentials.

#### Acceptance Criteria

1. WHEN the password step is displayed THEN Create_Employee_Page SHALL include a password field and a password confirmation field
2. WHEN the password step is displayed THEN Create_Employee_Page SHALL provide an option to auto-generate a random password
3. WHEN the manager submits the password THEN the system SHALL validate the cache key and create a TempUser record with the hashed password
4. IF the Cache_Session is expired or invalid THEN the system SHALL return an error and Create_Employee_Page SHALL redirect to the init step

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1 (cache key from init step)

### Requirement 3: Employee Profile Form

**User Story:** As a manager, I want to fill in all employee profile fields, so that the employee account is complete upon creation.

#### Acceptance Criteria

1. WHEN the profile step is displayed THEN Create_Employee_Page SHALL include all fields from the self-registration flow: first_name, last_name, first_name_kana, last_name_kana, birthday, gender, nationality, postal_code, prefecture, address, japanese_level, registered_location, residence_number_1, residence_expiry_date_1, residence_status_1, passport_number, passport_expiry_date, is_household_head, facebook_link
2. WHEN CrossStaff data was returned in the init step THEN Create_Employee_Page SHALL pre-fill the corresponding fields with the CrossStaff data
3. WHEN the manager submits the form THEN the system SHALL validate all fields using the same rules as the self-registration endpoint (StoreRegisterRequest)
4. IF validation fails THEN Create_Employee_Page SHALL display field-level error messages

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1 (CrossStaff data for pre-fill), Requirement 2 (password must be set first)

### Requirement 4: Employee Account Creation

**User Story:** As a manager, I want to create an employee account so that the employee can log in and use the system.

#### Acceptance Criteria

1. WHEN the manager submits a valid form THEN the system SHALL create a User record with the provided profile data and a hashed password
2. WHEN the user is created THEN the system SHALL create a corresponding UserInfo record with all profile detail fields
3. WHEN the user is created THEN the system SHALL assign the "User" role to the new employee
4. WHEN the user is created THEN the system SHALL set paper_status to "Registered" (not auto-approved)
5. WHEN the user is created THEN the system SHALL create a UserPaper record for the document verification workflow
6. IF the email already exists in the users table THEN the system SHALL reject the request with a validation error
7. IF the residence_number or passport_number already exists THEN the system SHALL reject the request with a validation error
8. IF CrossStaff data was found THEN the system SHALL store the staff_no and cross_staff_id on the User record

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None

### Requirement 5: Password Email Notification

**User Story:** As a manager, I want the employee to receive their login credentials via email, so that they can access the system without needing to contact me.

#### Acceptance Criteria

1. WHEN an employee account is successfully created THEN the system SHALL send an email to the employee containing their email and password
2. WHEN the password is auto-generated THEN the email SHALL include the generated password in plaintext
3. WHEN the password is manually set THEN the email SHALL include the manually set password in plaintext

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 4 (account must be created first)

### Requirement 6: Permission Control

**User Story:** As a system administrator, I want to control which managers can create employees, so that account creation is restricted to authorized personnel.

#### Acceptance Criteria

1. WHEN a new permission "admin.user.create-employee" is defined THEN it SHALL be added to the PermissionName enum and seeded
2. IF a manager does not have the "admin.user.create-employee" permission THEN the API SHALL return 403 Forbidden
3. IF a manager does not have the permission THEN the frontend SHALL hide the "Create Employee" button
4. WHEN the permission exists THEN it SHALL be assignable to any role through the existing role management system

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Laratrust permission system

## Non-Functional Requirements

### Performance Requirements
- WHEN the CrossStaff lookup is triggered THEN the system SHALL return results within 5 seconds or display a timeout error

### Security Requirements
- WHEN the password is sent via email THEN the system SHALL use the existing secure mailable infrastructure
- WHEN the API endpoint is called THEN the system SHALL verify the caller has the required permission before processing

### Usability Requirements
- WHEN the page is displayed THEN Create_Employee_Page SHALL present a clear multi-step flow (email → password → profile)
- WHEN the profile step is displayed THEN Create_Employee_Page SHALL organize fields into logical sections (basic info, contact, documents)
- WHEN CrossStaff data is pre-filled THEN Create_Employee_Page SHALL visually indicate which fields were auto-filled

### Reliability Requirements
- WHEN user creation fails mid-transaction THEN the system SHALL roll back all database changes (User, UserInfo, UserPaper)

## Constraints and Assumptions

### Technical Constraints
- Must use the existing Action pattern (`ActionResult`) for backend logic
- Must use the existing `ResponseHelper` for API responses
- Must use Laratrust for permission enforcement
- Frontend must use Mantine UI components consistent with the existing admin panel

### Assumptions
- The CrossStaff API is available and follows the same contract as used in registration
- Managers understand that created employees still need to go through paper approval
- The email service is configured and operational for sending credential emails

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] New permission is defined and seeded
- [ ] Backend endpoint validates all fields consistently with self-registration
- [ ] CrossStaff lookup auto-fills form fields when a match is found
- [ ] Employee receives an email with login credentials after account creation
- [ ] Employee account has "User" role and "Registered" paper status
- [ ] Transaction rollback works on failure
- [ ] Frontend form is accessible only to managers with the correct permission
- [ ] Code passes `pnpm typecheck`, `pnpm prettier`, `npm run prettier`, `npm run pint`
