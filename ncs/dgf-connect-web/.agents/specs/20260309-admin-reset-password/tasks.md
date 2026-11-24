# Implementation Plan: Admin Reset Password

## Overview

This plan follows a foundation-first approach: backend permission, email Mailable, and Action class first, then the API endpoint, then frontend shared utility, and finally the UI components. This ensures each layer is testable before building on top of it.

### Implementation Strategy
- Start with backend permission enum to establish the foundation
- Create the new `PasswordResetByAdminEmail` Mailable with localized templates
- Build the Action class with full authorization logic and email notification
- Wire up the controller method and route
- Extract shared frontend password utility
- Build the modal form component and integrate into the user detail page
- Run code quality checks at the end

## Implementation Plan

### Phase 1: Backend - Permission, Email & Action

- [ ] 1. Add new permission enum case
    - [ ] 1.1 Add `UserResetPassword = 'user.general.reset-password'` to `backend/app/Enums/PermissionName.php` in the User section (after `UserCreateEmployee`)
        - Follow existing naming convention: `case UserResetPassword = 'user.general.reset-password';`
        - _Requirements: 4.1_

- [ ] 2. Create PasswordResetByAdminEmail Mailable
    - [ ] 2.1 Create `backend/app/Mail/PasswordResetByAdminEmail.php`
        - Follow `EmployeeCreatedEmail` structure exactly
        - Use traits: `LocalizableMail`, `Queueable`, `SerializesModels`
        - Constructor: `User $user`, `#[\SensitiveParameter] string $password`
        - Store `$name`, `$email`, `$password` as private readonly properties
        - Call `$this->forUser($user)` for locale detection
        - Template path: `$this->getLocalizedTemplate('password.reset-by-admin')`
        - Pass `name`, `email`, `password` to template
        - _Requirements: 3.2_

    - [ ] 2.2 Create English email template `backend/resources/views/password/reset-by-admin.blade.php`
        - Use `<x-mail::message>` component (same as `employee/created.blade.php`)
        - Title: "Your password has been reset"
        - Body: inform user their password was reset by an administrator
        - Include email and new password in `<x-mail::panel>`
        - Remind user to change password after login
        - _Requirements: 3.2_

    - [ ] 2.3 Create Vietnamese email template `backend/resources/views/password/vi/reset-by-admin.blade.php`
        - Same structure as English template, translated to Vietnamese
        - _Requirements: 3.2_

    - [ ] 2.4 Create Japanese email template `backend/resources/views/password/ja/reset-by-admin.blade.php`
        - Same structure as English template, translated to Japanese
        - _Requirements: 3.2_

- [ ] 3. Create ResetPasswordAction
    - [ ] 3.1 Create `backend/app/Actions/Admin/User/ResetPasswordAction.php`
        - Invokable class with `__invoke(User $targetUser, string $password, User $actingUser): ActionResult`
        - Check `$actingUser->id === $targetUser->id` → return `ActionResult(error: ErrorCode::Forbidden, status: 403)`
        - Check if acting user does NOT have `global_admin` role: query target user roles, if any role is not `user` → return 403
        - Hash password with `Hash::make($password)`
        - Update target user password: `$targetUser->update(['password' => $hashedPassword])`
        - Queue email: `Mail::to($targetUser->email)->queue(new PasswordResetByAdminEmail($targetUser, $password))`
        - Return `ActionResult(data: null)`
        - _Requirements: 3.1, 3.2, 3.4, 3.5, 4.2, 4.3_

- [ ] 4. Checkpoint - Backend Action & Email
    - Verify `ResetPasswordAction` and `PasswordResetByAdminEmail` classes compile without errors
    - Run `npm run pint` and `npm run prettier` in `backend/`

### Phase 2: Backend - Controller & Route

- [ ] 5. Add controller method and request validation
    - [ ] 5.1 Add `resetPassword` method to `backend/app/Http/Controllers/Admin/UserController.php`
        - Method signature: `public function resetPassword(User $user, Request $request, ResetPasswordAction $action): JsonResponse`
        - Validate: `'password' => ['required', 'string', 'confirmed']`
        - Get acting user: `$request->user()`
        - Call: `$action($user, $validated['password'], $request->user())`
        - Return: `$result->toResponse()`
        - _Requirements: 3.1, 3.2, 3.3, 3.6_

    - [ ] 5.2 Add route in `backend/routes/api.php`
        - Add after the existing user enable/disable/restore routes
        - `Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('permission:' . PermissionName::UserResetPassword->value);`
        - _Requirements: 3.3, 4.1_

- [ ] 6. Checkpoint - Backend API
    - Verify route is registered: `docker compose exec php php artisan route:list --path=reset-password`
    - Run `npm run pint` and `npm run prettier` in `backend/`

### Phase 3: Frontend - Shared Utility & API

- [ ] 7. Extract shared password generation utility
    - [ ] 7.1 Create `frontend/src/utils/password.utils.ts`
        - Export `generateRandomPassword(length = 8): string` function
        - Move logic from `frontend/src/pages/admin/user/create.page.tsx` (the `generateRandomPassword` function)
        - Uses `window.crypto.getRandomValues` with charset `'0123456789'`
        - _Requirements: 5.1, 5.2, 5.3_

    - [ ] 7.2 Update `frontend/src/pages/admin/user/create.page.tsx` to import from shared utility
        - Remove inline `generateRandomPassword` function
        - Add `import { generateRandomPassword } from '@/utils/password.utils';`
        - Verify no behavior change
        - _Requirements: 5.2_

- [ ] 8. Add frontend API configuration and mutation
    - [ ] 8.1 Add `ADMIN_USER_RESET_PASSWORD: 'admin/users/:id/reset-password'` to `frontend/src/configs/api.config.ts`
        - Add after `ADMIN_USER_ENABLE` entry
        - _Requirements: 2.5_

    - [ ] 8.2 Add `useResetPasswordMutation` to `frontend/src/pages/admin/user/api.ts`
        - Follow `useEnableUserMutation` pattern
        - `mutationFn`: `ApiHelper.post(CommonHelper.replaceUrlParam(ApiConfig.ADMIN_USER_RESET_PASSWORD, { id }), data)`
        - No query invalidation needed (password change doesn't affect displayed data)
        - _Requirements: 2.5_

    - [ ] 8.3 Add `USER_RESET_PASSWORD` to `frontend/src/enums/permission_name.enum.ts`
        - Add to `PermissionName` type union: `| 'USER_RESET_PASSWORD'`
        - Add to `PermissionNameEnum` object: `USER_RESET_PASSWORD: { value: 'user.general.reset-password', text: 'User Reset Password' }`
        - Place after `USER_ASSIGN_ROLE` to match backend ordering
        - _Requirements: 1.1, 1.2, 4.1_

- [ ] 9. Checkpoint - Frontend API layer
    - Run `pnpm typecheck` in `frontend/`

### Phase 4: Frontend - UI Components

- [ ] 10. Create ResetPasswordForm component
    - [ ] 10.1 Create `frontend/src/pages/admin/user/partials/reset_password_form.tsx`
        - Follow `UserLockForm` pattern exactly
        - Props: `{ id: string; close: () => void }`
        - Use `useForm` with initial values: `{ password: '12345678', password_confirmation: '12345678' }`
        - Validation: `password` required string, `password_confirmation` required string
        - Add "Generate" button with `LuRefreshCw` icon that calls `generateRandomPassword()` and sets both fields
        - Submit handler: call `useResetPasswordMutation`, show teal success notification on success, red error notification on error, call `close()` only on success (keep modal open on error)
        - Layout: password field, password_confirmation field, generate button, then Cancel + Submit buttons in footer
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8_

- [ ] 11. Integrate into UserAction component
    - [ ] 11.1 Update `frontend/src/pages/admin/user/partials/user_action.tsx`
        - Add `'USER_RESET_PASSWORD'` to `usePermissions` array
        - Add `useDisclosure` for reset password modal: `const [isShowResetPassword, { open: openResetPassword, close: closeResetPassword }] = useDisclosure(false);`
        - Add "Reset Password" button in the left actions area (after Lock/Unlock buttons), with `LuKeyRound` icon
        - Button visibility: show when `perms.USER_RESET_PASSWORD` is true and user is not disabled
        - Add `<Modal>` with `<ResetPasswordForm>` at the bottom of the component (following Lock modal pattern)
        - _Requirements: 1.1, 1.2, 1.3_

- [ ] 12. Checkpoint - Frontend UI
    - Run `pnpm typecheck` in `frontend/`
    - Manually verify the button appears/hides based on permission

### Phase 5: Backend Tests

- [ ] 13. Create PasswordResetByAdminEmail unit test
    - [ ] 13.1 Create `backend/tests/Unit/Mail/PasswordResetByAdminEmailTest.php`
        - Follow `SpoofingDetectedEmailTest` pattern: extend `TestCase`, use `RefreshDatabase`
        - Helper method `createUser(array $overrides = [])` to create a `User::factory()->create()` with defaults
        - Test `testEmailUsesCorrectTemplateForEnglishLocale()`:
            - Create user with settings `['lang' => 'en']`
            - Instantiate `PasswordResetByAdminEmail($user, 'testpass123')`
            - Assert `$content->markdown` equals `'password.reset-by-admin'`
        - Test `testEmailUsesCorrectTemplateForVietnameseLocale()`:
            - Create user with settings `['lang' => 'vi']`
            - Assert `$content->markdown` equals `'password.vi.reset-by-admin'`
        - Test `testEmailUsesCorrectTemplateForJapaneseLocale()`:
            - Create user with settings `['lang' => 'ja']`
            - Assert `$content->markdown` equals `'password.ja.reset-by-admin'`
        - Test `testEmailContainsAllRequiredData()`:
            - Assert `$content->with` contains keys `name`, `email`, `password`
            - Assert values match the user's name, email, and provided password
        - _Requirements: 3.2_

- [ ] 14. Create ResetPasswordAction unit test
    - [ ] 14.1 Create `backend/tests/Unit/Actions/Admin/User/ResetPasswordActionTest.php`
        - Follow `AssignRolesActionTest` pattern: extend `TestCase`, use `RefreshDatabase`
        - `setUp()`: create Laratrust roles (`global_admin`, `admin`, `user`) and permissions, use `Mail::fake()`
        - Helper method `createUserWithRole(string $roleName)` to create user and assign role
        - Test `testSuccessfullyResetsPasswordForUserRole()`:
            - Acting user = admin with permission, target = user role
            - Assert `ActionResult` has no error
            - Assert `Hash::check($newPassword, $targetUser->fresh()->password)` is true
            - _Requirements: 3.1, 4.3_
        - Test `testEmailIsQueuedAfterSuccessfulReset()`:
            - Use `Mail::fake()`, perform successful reset
            - `Mail::assertQueued(PasswordResetByAdminEmail::class)` with correct recipient
            - _Requirements: 3.2_
        - Test `testSelfResetReturns403()`:
            - Acting user = target user
            - Assert `$result->getError()` equals `ErrorCode::Forbidden` and `$result->getStatus()` equals 403
            - Assert password is NOT changed
            - `Mail::assertNothingQueued()`
            - _Requirements: 3.5_
        - Test `testNonGlobalAdminCannotResetAdminPassword()`:
            - Acting user = admin role (not global_admin), target = admin role
            - Assert 403 error, password unchanged, no email queued
            - _Requirements: 3.4, 4.3_
        - Test `testNonGlobalAdminCannotResetGlobalAdminPassword()`:
            - Acting user = admin role, target = global_admin role
            - Assert 403 error
            - _Requirements: 3.4, 4.3_
        - Test `testGlobalAdminCanResetAdminPassword()`:
            - Acting user = global_admin, target = admin role
            - Assert success, password updated, email queued
            - _Requirements: 4.2_
        - Test `testGlobalAdminCanResetUserPassword()`:
            - Acting user = global_admin, target = user role
            - Assert success
            - _Requirements: 4.2_
        - Test `testGlobalAdminCannotResetOwnPassword()`:
            - Acting user = global_admin, target = same user
            - Assert 403 error
            - _Requirements: 3.5_
        - _Requirements: 3.1, 3.2, 3.4, 3.5, 4.2, 4.3_

- [ ] 15. Checkpoint - Backend Tests
    - Run `docker compose exec php ./vendor/bin/phpunit tests/Unit/Mail/PasswordResetByAdminEmailTest.php`
    - Run `docker compose exec php ./vendor/bin/phpunit tests/Unit/Actions/Admin/User/ResetPasswordActionTest.php`
    - Ensure all tests pass

### Phase 6: Final Validation

- [ ] 16. Code Quality
    - [ ] 16.1 Run `pnpm prettier` in `frontend/` and fix any issues
    - [ ] 16.2 Run `pnpm typecheck` in `frontend/` and fix any type errors
    - [ ] 16.3 Run `npm run prettier` in `backend/` and fix any issues
    - [ ] 16.4 Run `npm run pint` in `backend/` and fix any style issues
    - [ ] 16.5 Run `docker compose exec php ./vendor/bin/phpunit` to verify all tests pass

## Notes

- **Ordering rationale**: Backend first so the API is available for frontend testing. Shared utility before UI components so imports are ready. Email Mailable created before Action since the Action depends on it.
- **Email templates**: Three localized templates (en, vi, ja) following the `LocalizableMail` trait convention. The template path `password.reset-by-admin` maps to `resources/views/password/reset-by-admin.blade.php` for English, and `resources/views/password/{locale}/reset-by-admin.blade.php` for other locales.
- **Error code**: Using the existing `ErrorCode::Forbidden` for authorization failures. A specific error code (e.g., `ErrorCode::ResetPasswordForbidden`) can be added if more granular frontend error handling is needed.
- **Permission seeding**: The new permission must be assigned to appropriate roles via the admin UI or a seeder. This is an operational step outside the code implementation.
