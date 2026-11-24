# Implementation Plan: Shift Absent Notice

## Overview

Build order is bottom-up: database migration and config first, then model and relationships, then backend actions (mobile API submission, email, management list), then routes, and finally the frontend list page. The mobile app UI is out of scope — only the API endpoints and eager loading are needed.

## Tasks

- [ ] 1. Database migration and config
  - [ ] 1.1 Create migration `create_shift_absent_notices_table`
    - Columns: `id`, `shift_user_id` (foreignId → shift_users, unique, cascadeOnDelete), `timestamps`
    - _Requirements: 1.1, 1.2, 1.3, 1.4_

  - [ ] 1.2 Add config entries to `backend/config/work.php`
    - Add `'absent_notice' => ['before_hour' => (int) env('ABSENT_NOTICE_BEFORE_HOUR', 12)]`
    - Add `'system_notification_emails' => array_filter(array_map('trim', explode(',', env('SYSTEM_NOTIFICATION_EMAILS', ''))))`
    - _Requirements: 2.1, 2.2, 2.4_

  - [ ] 1.3 Add env vars to `backend/.env.example`
    - Add `ABSENT_NOTICE_BEFORE_HOUR=12`
    - Add `SYSTEM_NOTIFICATION_EMAILS=`
    - _Requirements: 2.2, 2.4_

- [ ] 2. Model and relationships
  - [ ] 2.1 Create `ShiftAbsentNotice` model
    - `$fillable = ['shift_user_id']`
    - `shiftUser(): BelongsTo` relationship
    - _Requirements: 1.1_

  - [ ] 2.2 Add `absentNotice` relationship to `ShiftUser` model
    - `absentNotice(): HasOne` → `ShiftAbsentNotice`
    - _Requirements: 1.1_

  - [ ] 2.3 Eager load `absentNotice` in user schedule service
    - Update `ScheduleService::getListSchedules()` to include `absentNotice` in eager loading
    - Update `ScheduleService::getShiftUserDetail()` to include `absentNotice` in eager loading
    - _Requirements: 3.5_

- [ ] 3. Checkpoint - Verify migration and models
  - Run migration, verify table created
  - Verify `ShiftUser::absentNotice` and `ShiftAbsentNotice::shiftUser` relationships work
  - Verify schedule endpoints return `absent_notice` field

- [ ] 4. Create absent notice action (mobile API)
  - [ ] 4.1 Create `CreateAbsentNoticeAction`
    - Validate `$shiftUser->user_id === $authUser->id` (403 if not)
    - Validate `$shiftUser->status === 'approved'` (400 if not)
    - Compute shift start datetime from `shift.date` + `shift.workplaceShift.start_time`
    - Validate current time is at least `config('work.absent_notice.before_hour')` hours before start (400 if not)
    - Check no existing `ShiftAbsentNotice` for this `shift_user_id` (409 if exists)
    - Create `ShiftAbsentNotice` record
    - Queue email if `config('work.system_notification_emails')` is not empty
    - Return 201 response
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, 3.8, 3.9, 4.1, 4.4, 5.1, 5.2_

  - [ ] 4.2 Add `createAbsentNotice` method to User `ScheduleController`
    - Route model bind `ShiftUser`
    - Call `CreateAbsentNoticeAction` with shiftUser and `Auth::user()`
    - _Requirements: 3.1_

- [ ] 5. Email notification
  - [ ] 5.1 Create `ShiftAbsentNoticeEmail` mailable
    - Accept: employee name, shift date, workplace name, shift time, position
    - Use existing mail patterns (Markdown template or view)
    - _Requirements: 4.2, 4.3_

- [ ] 6. Checkpoint - Verify mobile API
  - Test submit absent notice endpoint via API client
  - Verify validation errors (wrong user, wrong status, past deadline, duplicate)
  - Verify email is queued on success

- [ ] 7. Management list API
  - [ ] 7.1 Create `IndexRequest` form request
    - Validate `date_from` (date, optional, default today), `date_to` (date, optional, default today + 7), `workplace_id` (integer, optional, exists in workplaces)
    - _Requirements: 6.2_

  - [ ] 7.2 Create `ShiftAbsentNoticeFilter` model filter
    - Filter by shift date range (`date_from`, `date_to`) via join on `shift_users → shifts`
    - Filter by `workplace_id` via join on `shifts`
    - _Requirements: 6.2_

  - [ ] 7.3 Create `GetAbsentNoticesAction`
    - Query `ShiftAbsentNotice` with eager loads: `shiftUser.shift.workplace`, `shiftUser.shift.workplaceShift`, `shiftUser.user`, `shiftUser.workplacePosition`
    - Apply filters, paginate
    - Transform results to flat response: shift_date, workplace_name, shift_time, user_name, position_name, requested_at
    - Sort by shift date asc, then requested_at asc
    - _Requirements: 6.1, 6.3, 6.4, 6.5_

  - [ ] 7.4 Create `AbsentNoticeController` with `index` method
    - Call `GetAbsentNoticesAction` with validated conditions
    - _Requirements: 6.1_

- [ ] 8. Backend routes
  - [ ] 8.1 Add mobile route to `routes/api.php`
    - `POST /user/my-schedules/{shiftUser}/absent-notice` → `User\ScheduleController@createAbsentNotice`
    - Place inside existing user schedule route group
    - _Requirements: 3.1_

  - [ ] 8.2 Add management route to `routes/api.php`
    - `GET /manage/absent-notices` → `Manage\AbsentNoticeController@index`
    - Apply `permission:shift.list` middleware
    - _Requirements: 6.1_

- [ ] 9. Unit tests
  - [ ] 9.1 Create `CreateAbsentNoticeActionTest`
    - Test successful creation for approved shift within deadline
    - Test rejection when ShiftUser belongs to another user (403)
    - Test rejection when ShiftUser status is not approved (400)
    - Test rejection when current time is past deadline (400)
    - Test rejection when notice already exists (409)
    - Test email is queued on successful creation
    - Test email is skipped when `SYSTEM_NOTIFICATION_EMAILS` is empty
    - _Requirements: 3.2, 3.3, 3.4, 3.6, 3.7, 3.8, 3.9, 4.1, 4.4_

  - [ ] 9.2 Create `GetAbsentNoticesActionTest`
    - Test returns paginated results with correct fields
    - Test date range filter defaults (today to today + 7)
    - Test workplace_id filter
    - Test sort order (shift date asc, requested_at asc)
    - _Requirements: 6.2, 6.3, 6.4, 6.5_

- [ ] 10. Checkpoint - Verify backend complete
  - Run all tests: `docker compose exec php ./vendor/bin/phpunit`
  - Run `npm run prettier` and `npm run pint` in backend

- [ ] 11. Frontend management list page
  - [ ] 11.1 Add API config and route config
    - Add `MANAGE_ABSENT_NOTICES: 'manage/absent-notices'` to `api.config.ts`
    - Add `MANAGE_ABSENT_NOTICE` route with `shift.list` permission to `route.config.ts`
    - Add sidebar nav item to `sidebar.config.ts`
    - _Requirements: 7.1_

  - [ ] 11.2 Create API hooks (`frontend/src/pages/manage/absent-notice/api.ts`)
    - `useGetAbsentNoticesQuery(params, hasPermission)` — useQuery with queryKey `['absent-notices', params]`
    - _Requirements: 7.4_

  - [ ] 11.3 Create list page (`frontend/src/pages/manage/absent-notice/index.page.tsx`)
    - Breadcrumbs: Home > Absent Notices
    - `CommonSearchBox` with `date-range` (default today to today+7) and `select` for workplace
    - `DDataTable` with columns: shift date, workplace, shift time, user, position, requested at
    - Follow existing management page patterns (work-history or shift-tracking as reference)
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

- [ ] 12. Code Quality
  - [ ] 12.1 Run `pnpm prettier` and `pnpm typecheck` in `frontend/`
  - [ ] 12.2 Run `npm run prettier` and `npm run pint` in `backend/`

## Notes

- The mobile app UI for submitting absent notices is out of scope — only the API endpoint and eager loading are provided
- `system_notification_emails` config is intentionally placed at the top level of `work.php` (not nested under `absent_notice`) for reuse by future notification features
- The `is_absent` flag on `shift_users` is not affected by this feature — they remain independent
- Tasks are ordered: schema → models → mobile API → email → management API → routes → frontend
- Checkpoint tasks verify each major milestone before moving on
