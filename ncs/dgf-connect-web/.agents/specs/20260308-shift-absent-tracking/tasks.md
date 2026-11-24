# Implementation Plan: Shift Absent Tracking

## Overview

This implementation follows a backend-first strategy: database migration, then work hour calculation update, then API endpoint, and finally frontend UI. This ordering ensures each layer has the necessary foundation before building on top. The migration must run first to add the `is_absent` column, then `WorkHelper` is updated to use it, then the toggle API is created, and finally the frontend modal is enhanced with the toggle button and confirmation flow.

### Implementation Strategy

- Database migration first (foundation for all other changes)
- Update `WorkHelper::getWorkHours()` to exclude absent shifts
- Create Action class and controller endpoint for toggle
- Add frontend toggle button with confirmation modal
- Use React Query cache invalidation for schedule refresh

## Implementation Plan

### Phase 1: Database Migration

- [ ] 1. Create database migration
    - [ ] 1.1 Generate migration file
        - Run `docker compose exec php php artisan make:migration add_is_absent_to_shift_users_table`
        - Location: `backend/database/migrations/YYYYMMDD_add_is_absent_to_shift_users_table.php`
        - _Requirements: 1.1_

    - [ ] 1.2 Implement `up()` method
        - Add `is_absent` boolean column after `is_reported`
        - Set default value to `false`
        - Add index for query performance
        - _Requirements: 1.1, 1.2_

    - [ ] 1.3 Implement `down()` method
        - Drop the `is_absent` column
        - Ensure rollback works correctly
        - _Requirements: 1.4_

    - [ ] 1.4 Run migration
        - Execute `docker compose exec php php artisan migrate`
        - Verify column exists in database
        - Verify default value is `false`
        - _Requirements: 1.1, 1.2, 1.3_

- [ ] 2. Checkpoint - Verify migration
    - Confirm `is_absent` column exists in `shift_users` table
    - Confirm index is created
    - Confirm rollback works (`php artisan migrate:rollback`)

### Phase 2: Backend - Work Hour Calculation

- [ ] 3. Update ShiftUser model
    - [ ] 3.1 Add `is_absent` to casts array
        - Location: `backend/app/Models/ShiftUser.php`
        - Add `'is_absent' => 'boolean'` to `$casts`
        - _Requirements: 1.3_

    - [ ] 3.2 Add to docblock
        - Add `@property bool $is_absent` to class docblock
        - _Requirements: 1.3_

- [ ] 4. Update WorkHelper::getWorkHours()
    - [ ] 4.1 Modify the query in `getWorkHours()` method
        - Location: `backend/app/Helpers/WorkHelper.php`
        - Add `->where('shift_users.is_absent', false)` filter
        - Ensure filter only applies when no check-in exists (use `orWhereHas('checkIn')`)
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

    - [ ] 4.2 Test the change
        - Run existing tests to ensure no regression
        - Manually test with absent shift data
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

- [ ] 5. Checkpoint - Verify work hour calculation
    - Create test data with absent shifts
    - Verify `getWorkHours()` excludes absent shifts without check-in
    - Verify shifts with check-in are still included regardless of `is_absent`

### Phase 3: Backend - Toggle API

- [ ] 6. Create ToggleAbsentAction
    - [ ] 6.1 Create Action class
        - Location: `backend/app/Actions/Manage/Shift/ToggleAbsentAction.php`
        - Implement `__invoke(int $shiftUserId): ActionResult`
        - Use `DB::transaction()` for data consistency
        - _Requirements: 4.1, 4.6_

    - [ ] 6.2 Implement validation logic
        - Find shift_user by ID, return error if not found
        - Check status is 'Approved', return error if not
        - Toggle `is_absent` field (`!$shiftUser->is_absent`)
        - Touch the record to update `updated_at`
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.6_

    - [ ] 6.3 Add error codes
        - Add `SHIFT_USER_NOT_FOUND` to `ErrorCode` enum
        - Add `SHIFT_USER_INVALID_STATUS` to `ErrorCode` enum
        - _Requirements: 4.3, 4.4_

- [ ] 7. Update ScheduleController
    - [ ] 7.1 Add `toggleAbsent()` method
        - Location: `backend/app/Http/Controllers/Manage/ScheduleController.php`
        - Inject `ToggleAbsentAction`
        - Call action with `$shiftUserId` parameter
        - Return `$result->toResponse()`
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

    - [ ] 7.2 Add route
        - Location: `backend/routes/api.php`
        - Add `POST /manage/schedules/{shiftUserId}/toggle-absent`
        - Apply existing manage permission middleware
        - _Requirements: 4.5_

- [ ] 8. Update ScheduleService (optional)
    - [ ] 8.1 Include `is_absent` in schedule query if not already selected
        - Location: `backend/app/Services/Manage/ScheduleService.php`
        - Ensure `is_absent` is included in the `with()` eager loading
        - _Requirements: 3.1_

- [ ] 9. Checkpoint - Test toggle API
    - Test successful toggle (false → true)
    - Test successful toggle (true → false)
    - Test 404 on non-existent shift_user
    - Test 400 on non-approved status
    - Test 403 on missing permission
    - Verify `updated_at` is updated

### Phase 4: Frontend - Type Definitions

- [ ] 10. Update TypeScript types
    - [ ] 10.1 Update `ShiftUserSchedule` type
        - Location: `frontend/src/types/models/shift_user.d.ts`
        - Add `is_absent?: boolean;` field
        - _Requirements: 3.1_

    - [ ] 10.2 Update `CalendarCard` type
        - Location: `frontend/src/types/shift.d.ts`
        - Add `isAbsent?: boolean;` field
        - _Requirements: 3.1_

- [ ] 11. Update API config
    - [ ] 11.1 Add API endpoint config
        - Location: `frontend/src/configs/api.config.ts`
        - Add `MANAGE_SCHEDULE_TOGGLE_ABSENT: 'manage/schedules/:shiftUserId/toggle-absent'`
        - _Requirements: 4.1_

    - [ ] 11.2 Add mutation hook
        - Location: `frontend/src/pages/manage/user/[id]/api.ts`
        - Add `useToggleAbsentMutation(shiftUserId: number)`
        - Use `useMutation` with `onSuccess` callback
        - Invalidate `['user-schedule', userId, params]` query on success
        - _Requirements: 5.1, 5.2, 5.3, 5.4_

### Phase 5: Frontend - UI Components

- [ ] 12. Update Shift Info Modal
    - [ ] 12.1 Add toggle button
        - Location: `frontend/src/pages/manage/user/[id]/partials/shift_info_modal.tsx`
        - Add conditional rendering: show only when `card.status === 'approved'`
        - Button text: "Mark as Absent" when `isAbsent` is false, "Mark as Present" when true
        - Button color: red for "Mark as Absent", green for "Mark as Present"
        - _Requirements: 3.1, 3.2, 3.6_

    - [ ] 12.2 Add confirmation modal
        - Use Mantine `Modal` component for confirmation
        - Display message: "Mark this shift as absent?" or "Mark this shift as present?"
        - Add "Confirm" and "Cancel" buttons
        - _Requirements: 3.3, 3.4, 3.5_

    - [ ] 12.3 Implement toggle logic
        - Call `useToggleAbsentMutation` on confirm
        - Show success toast on success
        - Show error toast on failure
        - Close modal on success
        - _Requirements: 3.4, 3.5, 3.6, 3.7_

    - [ ] 12.4 Add i18n translations
        - Add translation keys for button text and confirmation modal
        - Support en, vi, ja locales
        - _Requirements: 3.6_

- [ ] 13. Checkpoint - Test frontend UI
    - Verify button shows only for approved shifts
    - Verify confirmation modal displays
    - Verify API call on confirm
    - Verify schedule refreshes after toggle
    - Verify success/error toasts display
    - Verify error handling (network errors, API errors)

### Phase 6: Final Validation

- [ ] 14. Integration testing
    - [ ] 14.1 Test end-to-end flow
        - Open schedule page
        - Click on approved shift
        - Click "Mark as Absent"
        - Confirm in modal
        - Verify schedule refreshes
        - Verify work hours are recalculated
        - _Requirements: 3.1, 3.4, 5.1, 5.2, 5.3_

    - [ ] 14.2 Test edge cases
        - Toggle same shift multiple times
        - Toggle shift without check-in
        - Toggle shift with check-in (should still work, but check-in takes precedence in calculations)
        - _Requirements: 2.4, 4.1_

- [ ] 15. Code Quality
    - [ ] 15.1 Run `npm run prettier` in `./backend`
    - [ ] 15.2 Run `npm run pint` in `./backend`
    - [ ] 15.3 Run `pnpm prettier` in `./frontend`
    - [ ] 15.4 Run `pnpm typecheck` in `./frontend`

## Notes

- **Ordering rationale**: Database migration must run first to add the column. Then `WorkHelper` is updated to use the column (backend logic). Then the toggle API is created (backend interface). Finally, the frontend UI is built on top of the working backend.
- **Risk: Existing data**: All existing records will have `is_absent = false` by default, which is correct behavior (no retroactive absence marking).
- **Risk: Check-in precedence**: The design specifies that check-in data takes precedence over `is_absent`. This means if a shift is marked absent but has check-in data, it will still be counted. This is intentional to handle edge cases.
- **Cache invalidation**: The `onSuccess` callback should invalidate the specific user's schedule query, not all schedules. Use the query key pattern `['user-schedule', userId, params]` to ensure selective invalidation.
- **i18n keys**: Suggested translation keys:
  - `Mark as Absent` / `Mark as Present`
  - `Confirm absence` / `Confirm attendance`
  - `Shift marked as absent` / `Shift marked as present`
  - `Are you sure you want to mark this shift as absent?` / `Are you sure you want to mark this shift as present?`
