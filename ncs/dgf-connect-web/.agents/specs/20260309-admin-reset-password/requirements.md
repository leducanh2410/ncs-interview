# Requirements Document

## Introduction

This feature adds a "Reset Password" action to the admin user detail page, allowing authorized administrators to reset a user's password. The new password is sent to the user via email. A new permission (`user.general.reset-password`) governs access, with role-based restrictions: non-global-admin users can only reset passwords for users with the `user` role, while global admins can reset passwords for anyone except themselves.

### Feature Summary

Admin reset password action with a modal form, permission-based access control, and email notification of the new credentials.

### Business Value

Reduces support burden by enabling managers and admins to directly reset passwords for employees who are locked out or have forgotten their credentials, without requiring developer intervention or self-service flows.

### Scope

**Included:**
- New "Reset Password" button on admin user detail page
- Modal with password input, confirmation, default value, and random generation
- New backend API endpoint for password reset
- New permission `user.general.reset-password`
- Role-based restriction logic (global_admin vs non-global_admin)
- Email notification with new credentials
- Shared password generation utility (extracted from user creation page)

**Excluded:**
- Self-service password reset (already exists separately)
- Password policy changes (strength requirements, expiry)
- Audit log for password resets (out of scope for this iteration)

## Glossary

| Term | Definition |
|------|------------|
| Reset_Password_Modal | The modal dialog displayed when the admin clicks the Reset Password button |
| Password_Generator | Shared utility function that generates a random 8-character numeric password |
| Target_User | The user whose password is being reset |
| Acting_User | The admin or global_admin performing the reset |

## Requirements

### Requirement 1: Reset Password Button

**User Story:** As an admin, I want to see a Reset Password button on the user detail page, so that I can initiate a password reset for a user.

#### Acceptance Criteria

1. WHEN Acting_User has the `user.general.reset-password` permission THEN User_Action SHALL display a "Reset Password" button
2. IF Acting_User does not have the `user.general.reset-password` permission THEN User_Action SHALL hide the "Reset Password" button
3. WHEN Acting_User clicks the "Reset Password" button THEN User_Action SHALL open the Reset_Password_Modal

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 2 (modal), Requirement 4 (permission)

### Requirement 2: Reset Password Modal

**User Story:** As an admin, I want a modal form to set a new password with a default value and a generate button, so that I can quickly reset a user's password.

#### Acceptance Criteria

1. WHEN Reset_Password_Modal opens THEN Reset_Password_Modal SHALL display a password field pre-filled with `12345678` and a password confirmation field
2. WHEN Acting_User clicks the "Generate" button THEN Password_Generator SHALL generate a random 8-character numeric password and populate both password fields
3. IF password and password confirmation do not match THEN Reset_Password_Modal SHALL display a validation error and prevent submission
4. IF password is empty THEN Reset_Password_Modal SHALL display a validation error and prevent submission
5. WHEN Acting_User clicks the submit button with valid input THEN Reset_Password_Modal SHALL call the reset password API endpoint
6. WHEN the API returns success THEN Reset_Password_Modal SHALL close and display a success notification
7. WHEN the API returns an error THEN Reset_Password_Modal SHALL display the error message and remain open
8. WHILE the API request is in progress THEN Reset_Password_Modal SHALL disable the submit button and show a loading state

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 3 (API), Requirement 5 (shared utility)

### Requirement 3: Reset Password API Endpoint

**User Story:** As a system, I want a secure API endpoint to reset a user's password, so that the password change is persisted and the user is notified.

#### Acceptance Criteria

1. WHEN a valid reset password request is received THEN the API SHALL hash and update the Target_User's password
2. WHEN the password is successfully updated THEN the API SHALL send an email to the Target_User with the new credentials
3. IF Acting_User does not have the `user.general.reset-password` permission THEN the API SHALL return a 403 Forbidden response
4. IF Acting_User does not have the `global_admin` role AND Target_User has a role other than `user` THEN the API SHALL return a 403 Forbidden response
5. IF Acting_User is the same as Target_User THEN the API SHALL return a 403 Forbidden response
6. IF Target_User does not exist THEN the API SHALL return a 404 Not Found response

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4 (permission)

### Requirement 4: Permission and Role-Based Access Control

**User Story:** As a system administrator, I want password reset access controlled by permissions and role hierarchy, so that only authorized users can reset passwords for appropriate targets.

#### Acceptance Criteria

1. WHEN the system is seeded THEN Permission_Seeder SHALL create the `user.general.reset-password` permission
2. IF Acting_User has `global_admin` role AND has the permission THEN the system SHALL allow resetting passwords for any user except themselves
3. IF Acting_User does not have `global_admin` role AND has the permission THEN the system SHALL only allow resetting passwords for users with the `user` role
4. IF Acting_User attempts to reset a password for a Target_User they are not authorized to reset THEN the system SHALL reject the request with a 403 response

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None (foundational requirement)

### Requirement 5: Shared Password Generation Utility

**User Story:** As a developer, I want a shared password generation function, so that both the user creation page and the reset password modal use the same logic.

#### Acceptance Criteria

1. WHEN Password_Generator is called THEN Password_Generator SHALL return a random 8-character string composed of digits 0-9 only
2. WHEN Password_Generator is used on the user creation page THEN the behavior SHALL be identical to the current implementation
3. WHEN Password_Generator is used on the Reset_Password_Modal THEN the behavior SHALL be identical to the user creation page

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: None

## Non-Functional Requirements

### Performance Requirements
- WHEN the reset password API is called THEN the API SHALL respond within 2 seconds (excluding email queue time)

### Security Requirements
- WHEN a password is reset THEN the system SHALL hash the password using bcrypt before storing
- WHEN a password is transmitted via email THEN the system SHALL send the plain-text password only in the initial notification email (queued, same pattern as employee creation)
- WHEN the reset password endpoint is called THEN the system SHALL validate the acting user's permission and role authorization server-side

### Usability Requirements
- WHEN the Reset_Password_Modal opens THEN the password field SHALL be pre-filled with the default password `12345678` for convenience
- WHEN the password is successfully reset THEN the system SHALL display a success toast notification

### Reliability Requirements
- IF email delivery fails THEN the system SHALL still persist the password change (email is queued asynchronously)

## Constraints and Assumptions

### Technical Constraints
- Must use existing Laratrust permission system
- Must follow the Action pattern for backend business logic
- Must use `ResponseHelper` for all API responses
- Password generation must use `window.crypto.getRandomValues` for randomness (frontend)

### Assumptions
- The email template for password reset notification will follow the same pattern as `EmployeeCreatedEmail`
- The permission will be manually assigned to appropriate roles via seeder or admin UI
- The frontend modal follows existing Mantine Modal patterns used in the user detail page

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] New permission is created and seeded
- [ ] Reset password button appears for authorized users only
- [ ] Modal form works with default password, generation, and validation
- [ ] API endpoint resets password with proper authorization checks
- [ ] Email notification is sent to the target user
- [ ] Password generation utility is shared between creation and reset flows
- [ ] Frontend passes `pnpm typecheck` and `pnpm prettier`
- [ ] Backend passes `npm run prettier` and `npm run pint`
