# Implementation Plan: Include face_registered in /auth/check Response

## Overview

This is a minimal backend-only change. The implementation adds `'face_registered'` to the existing `$user->append([...])` call in `AuthController::getAuthData()`, then validates with PHPUnit tests. The frontend `User` type already declares `face_registered: boolean`, so no frontend work is required. The strategy is bottom-up: make the single-line backend change, write tests, then run code quality checks.

### Implementation Strategy
- **Foundation-First (trivially scoped)**: One line change in the controller, then tests to validate
- Backend only — no frontend changes needed

## Implementation Plan

### Phase 1: Backend Change

- [x] 1. Append `face_registered` to the auth check user object
    - [x] 1.1 In `backend/app/Http/Controllers/AuthController.php`, `getAuthData()` method (line 287), add `'face_registered'` to the `$user->append([...])` array in the `else` branch
        - Before: `$user->append(['student_card_expiry_alert', 'residence_card_expiry_alert', 'passport_expiry_alert']);`
        - After: `$user->append(['student_card_expiry_alert', 'residence_card_expiry_alert', 'passport_expiry_alert', 'face_registered']);`
        - _Requirements: 1.1, 1.4_

- [ ] 2. Checkpoint — Manual verification
    - Call `GET /auth/check` with a valid token and confirm `face_registered` appears in the `user` object of the response
    - Confirm `GET /auth/check-manager` does NOT include `face_registered`
    - Ensure all tests pass; ask the user if questions arise.

### Phase 2: Testing

- [x] 3. PHPUnit tests for the updated endpoint
    - [x] 3.1 Test `face_registered: true` when user has a matching `UserFace` record
        - Create a `UserFace` factory record for the test user with `provider` matching `config('services.face_recognition.provider')`
        - Call `GET /auth/check` as the user
        - Assert `response->json('data.user.face_registered') === true`
        - _Requirements: 1.1, 1.2_

    - [x] 3.2 Test `face_registered: false` when user has no matching `UserFace` record
        - Ensure no `UserFace` record exists for the test user
        - Call `GET /auth/check` as the user
        - Assert `response->json('data.user.face_registered') === false`
        - _Requirements: 1.1, 1.3_

    - [x] 3.3 Test that `face_registered` is absent from the manager auth check response
        - Call `GET /auth/check-manager` as a manager user
        - Assert `face_registered` key is not present in `data.user`
        - _Requirements: 1.5_

- [ ] 4. Checkpoint — Run PHPUnit
    - Run `docker compose exec php ./vendor/bin/phpunit --filter AuthCheck`
    - All tests must pass; ask the user if questions arise.

### Phase 3: Final Validation

- [ ] 5. Code Quality
    - [ ] 5.1 Run `npm run prettier` in `./backend` to format PHP files
    - [ ] 5.2 Run `npm run pint` in `./backend` to lint/style check

## Notes

- The entire backend change is one line; risk of regression is minimal since the accessor already exists and is tested via `/admin/users`
- The `else` branch in `getAuthData()` is the correct scope — it handles only regular users, ensuring managers are not affected (Requirement 1.5)
- No migration or schema changes are needed
- Frontend already has `face_registered: boolean` in the `User` interface — do not modify frontend types
