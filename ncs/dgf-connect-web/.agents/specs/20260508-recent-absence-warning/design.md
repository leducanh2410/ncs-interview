# Design Document: Recent Absence Warning

## Overview

Introduce a single backend Service — `AbsenceService` — that computes the per-user count of recent unannounced absences in one batched query. Three call sites consume it: the mobile shift-registration response, the admin shift-detail assignment list, and the admin add-user search. The admin web frontend renders a warning triangle icon next to user names whose count is ≥ 1 with a hover tooltip that shows the count only (no duration). The window length is read from a new backend config (`config/absence.php` → `env('ABSENCE_RECENT_WINDOW_DAYS', 180)`); no frontend code references the duration. No schema changes; no backfill.

### Design Goals

- One source of truth for the absence rule (Service pattern, batched + single-user use cases)
- Read-time computation only — no caching, denormalization, or jobs
- Strictly additive API responses — no breaking changes to existing payloads
- The window value lives in exactly one place (backend config), per Technical Requirement 2

### Key Design Decisions

- **Use a single Service (`App\Services\Common\AbsenceService`)** under `App\Services\Common` to be reachable from both `User\Schedule\RegisterShiftAction` and the admin `Manage\Shift\ShiftService` flow. Rationale: keeps mobile API, admin search, and shift detail consistent — avoids drift from re-implementation. A service is preferred over an Action here because Actions consume it (`RegisterShiftAction`) and injecting Actions into other Actions risks circular dependencies.
- **Filter by `shifts.date`, not a computed `end_time`.** A shift counts when `shifts.date <= today.subDays(2)` (i.e., its calendar date is on or before the day before yesterday) and `shifts.date >= today.subDays(window_days)`. Rationale: `end_time` is derived from `workplace_shifts.end_time` (a time-of-day) and overnight shifts cross midnight; computing an exact end-datetime in SQL adds joins and offset arithmetic for no business benefit. The two-day grace already absorbs the overnight edge.
- **Ignore `shift_users.is_absent`.** The new feature defines an absence by structural data (no `check_ins` row, no `shift_absent_notices` row) rather than by the existing flag, which can be set manually by admins and does not match "true unannounced no-show". Existing flag semantics remain untouched.
- **Window is read from config at request time**, not cached in a static property. Rationale: tooling like `php artisan config:cache` already memoizes config; reading per-request lets ops change `.env` and reload without a rebuild.
- **Tooltip shows `"{N} recent absences"` only** — no duration. Rationale: keeps the configured window value in exactly one place.
- **Mobile UI is delivered by a separate repo and team.** This spec only ships the `absence_count` field on the registration success response; the popup and copy are the mobile team's concern.

### API/Interface Change Summary

**Successful shift registration response (`POST /api/shifts/{shift}/register`):**

Before:
```json
{ "data": { /* ShiftUser fields */ } }
```

After:
```json
{ "data": { /* ShiftUser fields */, "absence_count": 0 } }
```

**Shift detail assignment list (each user record returned by `getShiftDetail`):**

Before: existing `User` shape
After: existing `User` shape + `"absence_count": 0`

**Admin user search (`GET /api/manage/shifts/users/search`):**

Before: array of `User` records
After: array of `User` records, each with `"absence_count": 0`

## Components and Interfaces

### Component 1: `AbsenceService` (backend)

**Purpose**: The single source of truth for computing recent unannounced absences per user.

**Location**: `backend/app/Services/Common/AbsenceService.php`

**Responsibilities**:
- Resolve the configured window length (days) from `config('absence.window_days')`
- Compute `[user_id => absence_count]` for one or many users in a single SQL query
- Return integer counts with 0 for any requested user that has no qualifying records

**Interfaces**:
- **Method**: `getUserAbsenceCount(int|array<int> $userIds): int|array<int, int>`
- **Input**: `int|array<int> $userIds` — a single user id or a list
- **Output**:
    - If single id: `int`
    - If list: `array<int, int>` keyed by `user_id`, with **every requested id present** (zero-filled)
- **Dependencies**: Eloquent (`ShiftUser`, `Shift`, `CheckIn`, `ShiftAbsentNotice`), `config('absence.window_days')`, `Illuminate\Support\Carbon`

**Implementation Notes**:
- Single SQL via Eloquent query builder:
    ```text
    SELECT shift_users.user_id, COUNT(*) AS absence_count
    FROM shift_users
    JOIN shifts ON shifts.id = shift_users.shift_id
    LEFT JOIN check_ins ON check_ins.shift_user_id = shift_users.id
    LEFT JOIN shift_absent_notices ON shift_absent_notices.shift_user_id = shift_users.id
    WHERE shift_users.user_id IN (:user_ids)
      AND shift_users.status = 'approved'
      AND shifts.date <= :cutoff_old        -- today.subDays(2)
      AND shifts.date >= :cutoff_new        -- today.subDays(window_days)
      AND check_ins.id IS NULL
      AND shift_absent_notices.id IS NULL
    GROUP BY shift_users.user_id
    ```
- The `LEFT JOIN ... IS NULL` pattern is preferred over `WHERE NOT EXISTS` for batchability and existing query-builder ergonomics
- Resolve invalid config (non-positive integer) by falling back to `180` defensively in the Service; matches Technical Requirement 2.2
- Zero-fill the result so callers can rely on `array_key_exists` for every requested id

### Component 2: `RegisterShiftAction` extension

**Purpose**: Attach `absence_count` to the successful registration response.

**Location**: `backend/app/Actions/User/Schedule/RegisterShiftAction.php` (existing — extended)

**Responsibilities**:
- After the existing successful `ShiftUser` creation, call `AbsenceService::getUserAbsenceCount` for the registering user
- Return `data: ['shift_user' => $shiftUser, 'absence_count' => $count]` (or merge into the existing data shape — see Implementation Notes)

**Interfaces**:
- Input/Output: unchanged `ActionResult` contract; new field appears on success only
- **Dependencies**: `AbsenceService` injected via constructor

**Implementation Notes**:
- The existing action returns `new ActionResult(error: null, data: $shiftUser)`. To stay backward-compatible for any consumer reading `data` directly as a `ShiftUser` shape, prefer **mutating the serialization** at the controller boundary or wrapping `data` as an associative array. Confirm with the mobile team during the controller PR; the simplest path is to merge `absence_count` into the existing `ShiftUser` array representation.
- Error paths are unchanged — `absence_count` only appears when `error === null`

### Component 3: `ShiftService::searchUserForShift` extension

**Purpose**: Attach `absence_count` to each user returned by the admin add-user search.

**Location**: `backend/app/Services/Manage/ShiftService.php` (extension to existing `searchUserForShift`)

**Responsibilities**:
- After collecting the search result list, call `AbsenceService::getUserAbsenceCount` with the result's user ids
- Attach the count per user (e.g., as a transient model attribute or in the array transformation that the controller serializes)

**Interfaces**:
- **Input**: existing `searchUserForShift($actor, $conditions)` signature unchanged
- **Output**: each user record gains `absence_count: int`
- **Dependencies**: `AbsenceService`

**Implementation Notes**:
- Limit the batched call to the user ids returned in the page of results
- Result ordering is preserved — only the per-user attribute is added

### Component 4: Shift detail user-list extension

**Purpose**: Attach `absence_count` to every user record returned for the shift detail page across all groups (pending, rejected, and per-position assignments).

**Location**: `backend/app/Services/Manage/ShiftService.php` → the method that powers `getShiftDetail` (or its child action). Extend at the place where the shift's user lists are assembled for the response.

**Responsibilities**:
- Collect the **union of distinct user ids** across all groups returned by `getShiftDetail` (pending / rejected / per-position) into one set
- Call `AbsenceService::getUserAbsenceCount` once with that set
- Attach the per-user count to every user record across every group

**Implementation Notes**:
- Single batched call per request — N+1 across groups is explicitly disallowed by Requirement 2.5
- Position-group assignments may share the same user only across groups in unusual cases; deduplicate before the batched call regardless

### Component 5: `AbsenceWarning` (frontend, admin web)

**Purpose**: Render the warning triangle icon and tooltip when `absence_count >= 1`.

**Location**: `frontend/src/pages/manage/shift/partials/absence_warning.tsx` (new) — small reusable component used by both the assignment table and the add-user modal.

**Responsibilities**:
- Receive `absenceCount: number` as a prop
- Render `null` if count is `< 1`
- Render `LuTriangleAlert` (Mantine `Tooltip` wrapping) with `t('{{count}} recent absences', { count })` (uses i18next pluralization keys `_one`/`_other`) — no duration text

**Interfaces**:
- **Props**: `{ absenceCount: number }`
- **Output**: an icon + tooltip, or `null`
- **Dependencies**: Mantine `Tooltip`, `react-icons/lu` (`LuTriangleAlert` already imported in `add_user_modal.tsx` for paper warnings — same pattern)

**Implementation Notes**:
- Place the icon adjacent to the user-name rendering inside `assignment_table.tsx` row cell (this single edit covers all four instances of the component used by the shift detail page — pending, rejected, and per-position groups) and inside the search-result row of `add_user_modal.tsx`
- Use yellow/orange color consistent with the existing paper-warning style; do not introduce new color tokens

### Component 6: `UserWithAbsenceCount` type (frontend)

**Purpose**: Make `absence_count` discoverable in TypeScript only at the boundaries that actually carry it, without polluting the base `User` interface.

**Location**: `frontend/src/types/models/user.d.ts` (existing)

**Implementation Notes**:
- Define a narrow extension type alongside the existing `UserWithInfo`:
    ```ts
    export interface UserWithAbsenceCount extends User {
        absence_count: number;
    }
    ```
- Rationale: `absence_count` is a derived field attached only by specific endpoints (shift detail, add-user search). Adding it as an optional field on `User` would imply every consumer (auth, profile, etc.) might receive it, which is misleading. The pattern mirrors the existing `UserWithInfo extends User` convention in the same file.
- Use sites:
    - **Shift detail / assignment table**: the `ShiftUser.user` reference is typed as `UserWithAbsenceCount` (combine with `UserWithInfo` if `info` is also loaded).
    - **Add-user search**: the result type is already a flat custom shape declared locally to the modal — that shape declares `absence_count: number` directly; no new type needed.
- Because `absence_count` is required (not optional) on the extension type, call sites do not need defensive `?? 0` fallbacks — TypeScript guarantees the field is present.

## Data Models

No new entities. The Service reads from existing tables only:

- `shift_users` (`user_id`, `shift_id`, `status`)
- `shifts` (`id`, `date`)
- `check_ins` (`shift_user_id`)
- `shift_absent_notices` (`shift_user_id`)

### Configuration

```php
// backend/config/absence.php (new)
return [
    'window_days' => (int) env('ABSENCE_RECENT_WINDOW_DAYS', 180),
];
```

Add `ABSENCE_RECENT_WINDOW_DAYS=180` to `backend/.env.example`.

## Testing Strategy

### Unit Tests

**`AbsenceService` (`backend/tests/Unit/Services/Common/AbsenceServiceTest.php`):**
- Returns 0 for a user with no shifts
- Counts exactly one absence for an approved past shift with no check-in and no notice
- Excludes a shift with a `CheckIn` row
- Excludes a shift with a `ShiftAbsentNotice` row
- Excludes a pending or rejected shift
- Excludes a shift inside the two-day grace period (date = yesterday)
- Excludes a shift older than the configured window
- Includes a shift exactly at the boundaries (date = today − 2 days, date = today − window_days)
- Honors a custom `config('absence.window_days')` override (e.g., 30)
- Falls back to 180 when the config returns a non-positive value
- Batched call returns an array with every requested id zero-filled
- Batched call uses a single SQL query (assert via `DB::enableQueryLog`)

### Integration / Feature Tests

**Mobile registration (`backend/tests/Feature/User/Schedule/RegisterShiftTest.php`):**
- Successful registration response includes `absence_count` matching the registering user's recent absences
- Registration validation failure response does not include `absence_count`
- The just-registered shift does not contribute to the response's `absence_count`

**Admin shift-detail user lists (`backend/tests/Feature/Manage/Shift/GetShiftDetailTest.php`):**
- Every user in every group (pending, rejected, each position) has `absence_count` ≥ 0
- A user with one qualifying absence shows `absence_count = 1`, regardless of which group they appear in
- The same user appearing in multiple groups (when applicable) shows the same `absence_count` in each
- No N+1 across groups: the endpoint executes a constant number of queries regardless of the number of groups or user-count per group (assert via query log)

**Admin add-user search (`backend/tests/Feature/Manage/Shift/SearchUserTest.php`):**
- Each result has `absence_count`
- No N+1 across results

### Frontend Tests

Manual verification:
- Assignment table: row with `absence_count = 0` shows no icon; row with `absence_count >= 1` shows the triangle and tooltip
- Add-user modal search list: same behavior on each result row
- Tooltip text shows the count only — no duration
- Pluralization (`1 recent absence` vs. `2 recent absences`) verified across `en`, `vi`, `ja` locales after `pnpm trans:apply`
