# Implementation Plan: Vote/Feedback

## Overview

Implement the feature foundation-first: create the unified poll schema and core backend services first, then expose admin and user APIs, then wire eligibility recalculation into poll activation and first `staff_no` assignment flows, and finally build the admin-only web pages. Employee voting UI is out of scope for this repository; the backend only needs to provide payloads and submission endpoints for the mobile app.

### Implementation Strategy
- Build database schema and backend services before UI work
- Treat eligibility recalculation and result-counter consistency as the main technical risks
- Keep frontend work limited to admin management and per-poll results pages
- Reuse existing auth, permissions, and notification infrastructure

## Implementation Plan

### Phase 1: Database Schema and Models

- [x] 1. Create database migrations for polls and pending flags  
      _Requirements: 1, 2, 3, 4_
- [x] 1.1 Create `polls` table migration  
      _Requirements: 1, 5_
    - Columns: `id`, `type`, `workplace_id` (nullable FK), `title_vi`, `title_en`, `title_ja`, `description_vi`, `description_en`, `description_ja`, `is_active`, `is_required`, `expires_at`, `is_multiple`, `max_choice`, `options` (JSON), `result_counters` (JSON, default `{}`), `created_by`, timestamps
    - `result_counters` initialized to zero-counts at poll creation time
    - Add indexes on `type`, `workplace_id`, `is_active`, and `expires_at`
- [x] 1.2 Create `poll_responses` table migration  
      _Requirements: 3, 5_
    - Columns: `id`, `user_id`, `poll_id`, `answer` (JSON), `ip_address`, `device_info` (JSON, nullable), timestamps
    - Add unique constraint on `(user_id, poll_id)`
    - Add index on `poll_id`
    - [x] 1.3 Add `has_pending_votes` boolean column to `users` table  
          _Requirements: 2, 4_
        - Default `false`
        - Add index for fast lookup

- [x] 2. Create and update Eloquent models  
      _Requirements: 1, 2, 3, 5_
- [x] 2.1 Create `Poll` model with casts, scopes, and relations  
      _Requirements: 1, 5_
    - Add `scope` as derived/appended attribute (null `workplace_id` = global, non-null = workplace)
- [x] 2.2 Create `PollResponse` model with JSON casts and relations  
      _Requirements: 3, 5_
    - [x] 2.3 Update `User` model to cast `has_pending_votes` and add `pollResponses()` relation  
          _Requirements: 2, 4_

- [x] 3. Checkpoint: run migrations and verify schema behavior  
      _Requirements: 1, 2, 3_

### Phase 2: Backend Actions

- [x] 4. Implement Common Poll Actions (`App\Actions\Common\Poll`)  
      _Requirements: 2, 3, 4_
    - [x] 4.1 `GetPendingPollsAction` - Compute pending polls with eligibility rules  
          _Requirements: 2, 4_
        - Global poll eligibility: user has non-null `staff_no`
        - Workplace poll eligibility: user has non-null `staff_no` and has a record in `workplace_users` for the poll's workplace
        - Exclude inactive, expired, and already-responded polls
    - [x] 4.2 `EvaluatePendingVotesFlagAction` - Manage `has_pending_votes` flag  
          _Requirements: 2, 4_
        - `__invoke(User $user)` - Evaluate and set flag
        - `getForAuthResponse(User $user)` - Get pending polls for auth response with early return optimization
    - [x] 4.3 `SerializePendingPollsAction` - Transform polls for API payloads  
          _Requirements: 4_
        - Vote payload includes candidate IDs and names
        - Feedback payload includes choice IDs and multilingual labels
    - [x] 4.4 `FlagEligibleUsersAction` - Bulk flag eligible users when poll becomes active  
          _Requirements: 1, 2_
        - Evaluate current eligible recipients
        - Set `has_pending_votes = true` only for users with unresponded eligible polls
        - Dispatch notifications in chunks (500 users per chunk)
    - [x] 4.5 `EvaluateUserAfterStaffNoAssignmentAction` - Evaluate pending polls after first staff_no  
          _Requirements: 2_
        - Evaluate active unresponded polls for that user only
        - Set/reset `has_pending_votes`
        - Send notification if the user newly becomes pending

- [x] 5. Implement User Poll Actions (`App\Actions\User\Poll`)  
      _Requirements: 3_
    - [x] 5.1 `SubmitPollResponseAction` - Validate and record user poll response  
          _Requirements: 3_
        - Validate active/non-expired config
        - Validate current eligibility
        - Validate type-specific answer rules (vote: yes/no/blank per candidate; feedback: choice IDs)
        - Reject duplicates
        - Lock poll row with `lockForUpdate()`, create response, increment result counters, re-evaluate pending flag in one transaction

- [x] 6. Implement Admin Poll Actions (`App\Actions\Admin\Poll`)  
      _Requirements: 1, 5_
    - [x] 6.1 `GetPollsAction` - List polls with filtering and pagination  
          _Requirements: 1_
    - [x] 6.2 `GetPollAction` - Get poll detail with resolved candidate users  
          _Requirements: 1, 5_
    - [x] 6.3 `CreatePollAction` - Create poll with normalized options  
          _Requirements: 1_
        - Generate ULID IDs for feedback options missing `id`
        - Initialize `result_counters` to zero-counts
        - If `is_active = true`, call `FlagEligibleUsersAction`
    - [x] 6.4 `UpdatePollAction` - Update poll with locked field protection  
          _Requirements: 1_
        - Exclude `is_active` from updatable fields (use activate/deactivate)
        - Lock options, `is_multiple`, and `max_choice` after responses exist
    - [x] 6.5 `DeletePollAction` - Delete poll (prevent if responses exist)  
          _Requirements: 1_
    - [x] 6.6 `ActivatePollAction` - Activate poll and flag eligible users  
          _Requirements: 1, 2_
    - [x] 6.7 `DeactivatePollAction` - Deactivate poll without flag recalculation  
          _Requirements: 1_
    - [x] 6.8 `GetPollResponsesAction` - Get paginated responses for a poll  
          _Requirements: 5_

- [x] 7. Checkpoint: run backend linting (prettier + pint)  
      _Requirements: 1, 2, 3, 4, 5_

### Phase 3: Backend Requests, Controllers, Routes, and Auth Integration

- [x] 8. Create FormRequest validation classes  
      _Requirements: 1, 3_
- [x] 8.1 Create `StorePollRequest` and `UpdatePollRequest`  
      _Requirements: 1_
    - Validate titles, descriptions, workplace, options, `is_multiple`, `max_choice`, `expires_at`, `is_active`, `is_required`
    - Validate feedback option payloads and duplicate option IDs
    - [x] 8.2 Create `SubmitPollResponseRequest`  
          _Requirements: 3_
        - Validate `answer`
        - Validate optional `device_info`

- [x] 9. Create backend controllers  
      _Requirements: 1, 3, 5_
- [x] 9.1 Create `Admin\\PollController` with `index`, `show`, `store`, `update`, `destroy`, `activate`, `deactivate`, and `responses`  
      _Requirements: 1, 5_
- [x] 9.2 Create `User\\PollResponseController` with `respond` action  
      _Requirements: 3_

- [x] 10. Register routes and permissions  
      _Requirements: 1, 3, 5_
- [x] 10.1 Add admin poll routes under `/admin/polls`  
      _Requirements: 1, 5_
    - `GET /admin/polls`
    - `GET /admin/polls/{poll}`
    - `POST /admin/polls`
    - `PUT /admin/polls/{poll}`
    - `DELETE /admin/polls/{poll}`
    - `POST /admin/polls/{poll}/activate`
    - `POST /admin/polls/{poll}/deactivate`
    - `GET /admin/polls/{poll}/responses`
- [x] 10.2 Add user submission route `POST /user/polls/{poll}/respond`  
      _Requirements: 3_
- [x] 10.3 Add `PollList` and `PollEdit` permissions to backend and frontend permission enums  
      _Requirements: 1, 5_

- [x] 11. Integrate pending poll payloads into employee auth responses  
      _Requirements: 4_
    - [x] 11.1 Use `EvaluatePendingVotesFlagAction` in `AuthController`  
          _Requirements: 4_
    - [x] 11.2 Add `pending_votes` only to employee-facing auth payloads  
          _Requirements: 4_
        - Keep admin auth payload unchanged

- [x] 12. Checkpoint: add feature tests for API endpoints and auth payloads  
      _Requirements: 1, 3, 4, 5_

### Phase 4: Eligibility Trigger Integration

- [x] 13. Wire poll evaluation into activation and first `staff_no` assignment flows  
      _Requirements: 2_
    - [x] 13.1 Ensure poll activation path uses `ActivatePollAction` (calls `FlagEligibleUsersAction`)  
          _Requirements: 1, 2_
    - [x] 13.2 Update every code path where `staff_no` can transition from `null` to a value  
          _Requirements: 2_
        - Current likely integration points: admin user update flow and paper approval flow
        - Call `EvaluateUserAfterStaffNoAssignmentAction` only on first assignment
    - [x] 13.3 Do not add poll recalculation to shift approval flows  
          _Requirements: 2_

- [x] 14. Checkpoint: add trigger-specific tests  
      _Requirements: 2_
    - [x] 14.1 Activation flags eligible users and sends notifications
    - [x] 14.2 First `staff_no` assignment evaluates pending polls for that user

### Phase 5: Frontend Admin Web

- [x] 15. Create frontend types and API hooks for admin pages  
      _Requirements: 1, 5_
- [x] 15.1 Add `Poll`, `PollChoice`, and `PollResponse` TypeScript types  
      _Requirements: 1, 5_
- [x] 15.2 Add admin API constants and React Query hooks  
      _Requirements: 1, 5_
    - `ADMIN_POLL_LIST`
    - `ADMIN_POLL_DETAIL`
    - `ADMIN_POLL_RESPONSES`
    - `ADMIN_POLL_ACTIVATE`
    - `ADMIN_POLL_DEACTIVATE`
    - `usePollsQuery()`
    - `usePollQuery()`
    - `usePollResponsesQuery()`
    - `useCreatePollMutation()`
    - `useUpdatePollMutation()`
    - `useDeletePollMutation()`
    - `useActivatePollMutation()`
    - `useDeactivatePollMutation()`

- [x] 16. Build admin poll pages  
      _Requirements: 1, 5_
    - [x] 16.1 Create poll list page  
          _Requirements: 1_
        - Columns: ID, Type, Workplace, Title, Required, Expires, Multiple, Max Choice, Responses Count, Active, Actions
        - Filters: type
        - Actions: Create, Edit, Delete, Activate, Deactivate, View Results
    - [x] 16.2 Create poll form modal  
          _Requirements: 1_
        - Two submit buttons: "Create draft" (sets `is_active=false`) and "Create and send" (sets `is_active=true`)
        - Type selector locked after creation
        - Vote: candidate user selector
        - Feedback: dynamic choice rows with multilingual labels
        - Lock options, `is_multiple`, and `max_choice` after responses exist
    - [x] 16.3 Create per-poll results page at `/admin/polls/[id]/results`  
          _Requirements: 5_
        - Load config detail and `result_counters`
        - Show aggregated results
        - Show paginated individual response table
        - No participation-rate or workplace filter UI

- [x] 17. Add admin navigation and route configuration  
      _Requirements: 1, 5_
- [x] 17.1 Add sidebar entry for poll management
- [x] 17.2 Add route config for the new results page

- [x] 18. Checkpoint: run admin frontend verification  
      _Requirements: 1, 5_

### Phase 6: Translation and Final Validation

- [x] 19. Add translation keys for all admin-facing UI text
      _Requirements: 1, 5_
    - [x] 19.1 Convert all new admin page text to `t(...)`
    - [x] 19.2 Run `pnpm trans:prepare`, fill `vi` and `ja`, then run `pnpm trans:apply`

- [x] 20. Run code quality checks
      _Requirements: 1, 2, 3, 4, 5_
    - [x] 20.1 Run frontend checks: `pnpm prettier` and `pnpm typecheck`
    - [x] 20.2 Run backend checks: `npm run prettier` and `npm run pint`
    - [x] 20.3 Run backend tests: `docker compose exec php ./vendor/bin/phpunit`

## Notes

- Employee voting UI, pending-poll popup behavior, and optional-poll skip behavior are out of scope for this repository
- Eligibility is driven by active polls plus first `staff_no` assignment, not by shift approval
- Feedback option IDs should be backend-generated ULIDs and stored inside `polls.options`
- Use a row lock on `polls` when updating `result_counters` to avoid lost updates
- Keep one admin route family at `/admin/polls`
- New permissions must be seeded in both backend and frontend permission definitions
