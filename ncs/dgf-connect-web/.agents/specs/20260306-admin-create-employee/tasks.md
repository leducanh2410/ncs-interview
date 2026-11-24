# Implementation Plan: Admin Create Employee

## Overview

This implementation follows a foundation-first strategy: backend permission and enum setup, then the 3-step API endpoints (mirroring the registration flow), then the credential email, and finally the frontend multi-step page. Each backend step reuses existing registration patterns (cache session, TempUser, RegisterNewUserAction logic) to minimize new code. The frontend is built last since it depends on all backend endpoints being ready.

### Implementation Strategy
- Reuse existing `AuthService` methods and `RegisterNewUserAction` logic wherever possible
- Build backend endpoints in flow order (init → set-password → register) so each can be tested independently
- Email mailable and templates built before the register endpoint that sends them
- Frontend built as a single multi-step page using Mantine Stepper

## Implementation Plan

### Phase 1: Backend Foundation

- [x] 1. Add permission enum and seeder
    - [x] 1.1 Add `UserCreateEmployee` case to `PermissionName` enum with value `'user.general.create-employee'`
        - Add to `app/Enums/PermissionName.php`
        - Follow existing naming convention (`user.general.*`)
        - _Requirements: 6.1_

    - [x] 1.2 Run permission seeder to insert the new permission into the database
        - `docker compose exec php php artisan db:seed --class=LaratrustSeeder` (or equivalent)
        - Verify permission is created in the permissions table
        - _Requirements: 6.1, 6.4_

- [x] 2. Checkpoint - Verify permission setup
    - Ensure the new permission exists in the database and is assignable to roles

### Phase 2: Backend API - Init Endpoint

- [x] 3. Create init endpoint
    - [x] 3.1 Add `initEmployee()` method to `Admin\UserController`
        - Validate email: required, string, email, max:255, unique:users,email
        - Call `CrossStaffApiService::searchUser()` with the email
        - Map CrossStaff response using same logic as `AuthService::verifyRegisterOtp()`
        - Create cache entry with key `'emp_' . Str::random()`, TTL 1 day, containing: email, is_verified: true, user: crossStaffData|null
        - Return `ResponseHelper::success(['key' => $key, 'email' => $email, 'user' => $crossStaffData])`
        - Wrap CrossStaff call in try-catch — on failure, set user to null (don't block init)
        - _Requirements: 1.1, 1.2, 1.3, 1.4_

    - [x] 3.2 Add route `POST /admin/users/employees/init`
        - Add to `routes/api.php` under admin user routes
        - Apply permission middleware: `'permission:' . PermissionName::UserCreateEmployee->value`
        - _Requirements: 6.2_

- [x] 4. Checkpoint - Test init endpoint
    - Verify cache entry is created with correct structure
    - Verify CrossStaff data is returned when found
    - Verify user: null when CrossStaff has no match or fails
    - Verify 422 on duplicate email
    - Verify 403 without permission

### Phase 3: Backend API - Set Password Endpoint

- [x] 5. Create set-password endpoint
    - [x] 5.1 Add `setEmployeePassword()` method to `Admin\UserController`
        - Validate: key (required, string), password (required, string, confirmed)
        - Retrieve cache by key, verify `is_verified` is true
        - If cache invalid/expired, return `REGISTER_INVALID_SESSION` error
        - Create or update `TempUser` with email from cache and hashed password
        - Reuse same logic as `AuthService::storeTempUser()` or call it directly
        - _Requirements: 2.3, 2.4_

    - [x] 5.2 Add route `POST /admin/users/employees/set-password`
        - Add to `routes/api.php` under admin user routes
        - Apply same permission middleware
        - _Requirements: 6.2_

- [x] 6. Checkpoint - Test set-password endpoint
    - Verify TempUser is created with correct email and hashed password
    - Verify error on invalid/expired cache key
    - Verify 403 without permission

### Phase 4: Backend API - Register Endpoint

- [x] 7. Create EmployeeCreatedEmail mailable
    - [x] 7.1 Create `app/Mail/EmployeeCreatedEmail.php`
        - Extend `Mailable`, use `Queueable`, `SerializesModels`, `LocalizableMail` traits
        - Accept `User` model and plaintext password in constructor
        - Define `content()` method with view path and `with` data (user name, email, password)
        - _Requirements: 5.1, 5.2, 5.3_

    - [x] 7.2 Create email templates for all locales
        - Create `resources/views/employee/created.blade.php` (English, default)
        - Create `resources/views/employee/ja/created.blade.php` (Japanese)
        - Create `resources/views/employee/vi/created.blade.php` (Vietnamese)
        - Include employee name, email, and password in template
        - Use `<x-mail::message>` component consistent with existing templates
        - _Requirements: 5.1_

- [x] 8. Create CreateEmployeeAction
    - [x] 8.1 Create `app/Actions/Admin/User/CreateEmployeeAction.php`
        - Follow same logic as `RegisterNewUserAction` but with these differences:
            - Always set `paper_status` to `Registered` (never auto-approve, even with CrossStaff data)
            - Always create `UserPaper` record
            - Send `EmployeeCreatedEmail` instead of `RegistrationSuccessEmail`
            - Accept plaintext password for email (hash for storage)
        - Wrap in `DB::transaction()`:
            1. Get cache session (validate is_verified)
            2. Get TempUser by email (error if not found)
            3. Merge CrossStaff user data + request data for User creation
            4. Create User with `paper_status: Registered`
            5. Create UserInfo with merged CrossStaff info + request data
            6. Create UserPaper
            7. Assign "User" role via `$user->addRole('user')`
            8. Delete TempUser
            9. Forget cache key
        - After transaction: queue `EmployeeCreatedEmail` with user + plaintext password
        - Return `ActionResult` with created user
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 4.8_

    - [x] 8.2 Add `registerEmployee()` method to `Admin\UserController`
        - Validate request using same rules as `StoreRegisterRequest` (key + all profile fields)
        - Can reuse `StoreRegisterRequest` directly or create `StoreEmployeeRequest` with same rules
        - Call `CreateEmployeeAction` with validated data
        - Return `$result->toResponse()`
        - _Requirements: 3.3, 3.4_

    - [x] 8.3 Add route `POST /admin/users/employees/register`
        - Add to `routes/api.php` under admin user routes
        - Apply same permission middleware
        - _Requirements: 6.2_

- [ ] 9. Checkpoint - Test register endpoint
    - Verify full 3-step flow: init → set-password → register
    - Verify User, UserInfo, UserPaper records are created
    - Verify paper_status is "Registered"
    - Verify "User" role is assigned
    - Verify CrossStaff data (staff_no, cross_staff_id) stored when present
    - Verify TempUser is deleted and cache is forgotten
    - Verify credential email is queued
    - Verify 422 on duplicate residence number / passport
    - Verify error on expired cache key / missing TempUser

### Phase 5: Frontend

- [x] 10. Add API config and route config
    - [x] 10.1 Add API endpoints to `frontend/src/configs/api.config.ts`
        - `ADMIN_USER_EMPLOYEE_INIT: 'admin/users/employees/init'`
        - `ADMIN_USER_EMPLOYEE_SET_PASSWORD: 'admin/users/employees/set-password'`
        - `ADMIN_USER_EMPLOYEE_REGISTER: 'admin/users/employees/register'`
        - _Requirements: 1.1, 2.3, 3.3_

    - [x] 10.2 Add route config to `frontend/src/configs/route.config.ts`
        - Add `ADMIN_USER_CREATE` with path `/admin/user/create` and permission `user.general.create-employee`
        - _Requirements: 6.3_

    - [x] 10.3 Add API mutation hooks to `frontend/src/pages/admin/user/api.ts`
        - `useInitEmployeeMutation()` — POST to init endpoint
        - `useSetEmployeePasswordMutation()` — POST to set-password endpoint
        - `useRegisterEmployeeMutation()` — POST to register endpoint
        - _Requirements: 1.1, 2.3, 3.3_

- [x] 11. Create the multi-step Create Employee page
    - [x] 11.1 Create `frontend/src/pages/admin/user/create.page.tsx`
        - Use Mantine `Stepper` with 3 steps: Email, Password, Profile
        - Store cache key and CrossStaff data in component state
        - Step 1 (Email): TextInput for email + submit button. On success, store key and CrossStaff data, advance to step 2
        - Step 2 (Password): PasswordInput + confirmation + "Generate Random" button. On success, advance to step 3
        - Step 3 (Profile): Full form with all profile fields, pre-filled from CrossStaff data. Sections: Basic Info (name, kana), Personal Details (birthday, gender, nationality, japanese_level), Address (postal_code, prefecture, address), Documents (residence card, passport), Other (registered_location, facebook_link, is_household_head)
        - On final submit success: show success notification, redirect to user list page
        - Handle errors: display field-level errors, redirect to step 1 on session expiry
        - Use `useForm` with `validatorResolver` for each step's validation
        - _Requirements: 1.2, 1.3, 1.5, 2.1, 2.2, 3.1, 3.2, 3.4_

    - [x] 11.2 Add "Create Employee" button to user list page
        - Add button to `frontend/src/pages/admin/user/index.page.tsx`
        - Button navigates to `/admin/user/create`
        - Hide button if user lacks `user.general.create-employee` permission
        - Place next to existing "Create Manager" button
        - _Requirements: 6.3_

- [ ] 12. Checkpoint - Test frontend flow
    - Verify 3-step flow works end-to-end
    - Verify CrossStaff data pre-fills form fields
    - Verify manual entry works when no CrossStaff match
    - Verify random password generation
    - Verify error handling (duplicate email, expired session, validation errors)
    - Verify "Create Employee" button visibility based on permission

### Phase 6: Final Validation

- [x] 13. Code Quality
    - [x] 13.1 Run `npm run prettier` in `./backend` to format PHP files
    - [x] 13.2 Run `npm run pint` in `./backend` to lint PHP files
    - [x] 13.3 Run `pnpm prettier` in `./frontend` to format frontend files
    - [x] 13.4 Run `pnpm typecheck` in `./frontend` to verify no type errors

## Notes

- **Ordering rationale**: Permission first (needed by all routes), then endpoints in flow order (init → set-password → register), email before register (since register sends it), frontend last (depends on all endpoints)
- **Code reuse**: The init endpoint reuses `CrossStaffApiService::searchUser()` and the same data mapping from `AuthService`. The set-password step can call `AuthService::storeTempUser()` directly. The register step follows `RegisterNewUserAction` logic with minor differences (no auto-approve, different email)
- **Risk: CrossStaff data mapping duplication**: The mapping logic from CrossStaff API response to cache format exists in `AuthService::verifyRegisterOtp()`. Consider extracting it to a shared method to avoid duplication. If extraction is too risky, duplicating the mapping in the controller is acceptable for this scope
- **Risk: Cache key prefix**: Registration uses `'reg_'` prefix. Employee creation uses `'emp_'` prefix to avoid collisions. Ensure all cache operations use the correct prefix
- **Risk: TempUser cleanup**: If a manager starts the flow but doesn't complete it, TempUser records may linger. This is the same behavior as the registration flow — existing cleanup mechanisms (if any) apply equally
