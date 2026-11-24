# Requirements Document

## Introduction

Surface a per-employee count of recent absences (true unannounced no-shows) so the mobile app can warn the registering employee and the admin web app can warn admins on the add-user modal and the shift detail assignment table. An absence is a past approved shift where the employee neither checked in nor submitted an absent notice. The "recent" window is configurable via a backend environment variable (default 180 days) and is owned exclusively by the backend — the frontend never references the duration. Existing `shift_users.is_absent` and `shift_absent_notices` rows are not modified; the count is derived at read time. This is an additive change with no breaking schema or contract changes.

### Feature Summary

Compute and expose a rolling `absence_count` per user (window length configured by backend `.env`, default 180 days) for use by the mobile shift-registration response and admin web list endpoints, and render a warning indicator on admin UIs.

### Business Value

Repeated unannounced absences disrupt scheduling. Surfacing the count to the employee at registration time reinforces accountability ("repeated absences may reduce your selection priority"); surfacing it to admins enables informed approval decisions on the assignment table and add-user modal.

### Scope

In scope:
- Backend: a single shared computation of `absence_count` per user over a trailing window of N days, where N is read from a new backend `.env` setting (default 180)
- Backend: extend the mobile shift-registration successful response with `absence_count`
- Backend: extend the admin shift-detail assignment list endpoint and the add-user search endpoint with `absence_count` per user
- Frontend (admin web): warning icon + hover tooltip on the assignment table and add-user modal search list, shown only when `absence_count ≥ 1`. Tooltip shows the count only — no duration text.

Out of scope:
- Mobile app UI (separate repo) — backend delivers the field; mobile team implements the popup
- Changes to `is_absent` semantics, `shift_absent_notices` schema, or any retroactive backfill
- Caching, scheduled aggregation, or denormalized columns
- Filtering or hiding terminated / on-leave users
- Any selection-priority logic (the warning text only references it)

## Glossary

| Term | Definition |
|------|------------|
| Absence | A past approved shift for which the user has no `check_ins` row and no `shift_absent_notices` row, and whose `end_time` is strictly before the start of yesterday (giving a two-day grace period) |
| Absence_Window | Trailing N days from "now" (server time), where N is configured via backend `.env` (default 180) |
| Absence_Count | Number of Absences within the Absence_Window for a given user |
| Absence_Count_Service | The single backend service that computes Absence_Count for one or many users |

## Requirements

### Requirement 1: Mobile employee sees absence count after registering a shift

**User Story:** As an employee using the mobile app, I want to see how many recent absences I have after registering for a shift, so that I understand the impact repeated absences may have on my selection priority.

#### Acceptance Criteria

1. WHEN a successful shift registration request is processed THEN the registration endpoint SHALL include `absence_count` (integer ≥ 0) in the response body, computed by Absence_Count_Service for the registering user
2. IF the shift registration fails validation or authorization THEN the response SHALL NOT include `absence_count`
3. WHEN Absence_Count_Service computes the value for the registration response THEN it SHALL count only Absences whose shift `end_time` is strictly before the start of yesterday and within Absence_Window
4. WHEN the just-registered shift exists THEN it SHALL NOT contribute to `absence_count` (its `end_time` is in the future)

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 4 (Absence_Count_Service)

### Requirement 2: Admin sees absence warning on every assignment table of the shift detail page

**User Story:** As a shift admin reviewing the shift detail page, I want to see a warning indicator next to any listed employee who has recent absences — whether they are pending approval, already approved into a position group, or rejected — so that I can use this signal when approving, reassigning, or revisiting decisions.

The shift detail page renders the same assignment-table component once per group (pending / register list, rejected list, and one per position). The warning must appear in **every** instance.

#### Acceptance Criteria

1. WHEN the shift-detail endpoint returns its user lists (pending, rejected, and per-position assignments) THEN every user record across every group SHALL include `absence_count` (integer ≥ 0) computed by Absence_Count_Service
2. WHERE the assignment-table component renders a user row in any group AND `absence_count ≥ 1` THEN the row SHALL display a warning triangle icon next to the user's name
3. WHEN the admin hovers the warning icon THEN a tooltip SHALL display the count only (e.g., "{N} recent absences"); the tooltip SHALL NOT reference the duration of the time window
4. IF `absence_count` is 0 THEN no warning icon SHALL be rendered for that row
5. WHEN the shift-detail endpoint computes counts for the page THEN it SHALL do so in a single batched call covering the union of users across all groups (no per-group or per-row N+1)

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 4 (Absence_Count_Service)

### Requirement 3: Admin sees absence warning on the add-user modal search list

**User Story:** As a shift admin, I want to see a warning indicator next to candidate employees while picking who to add to a shift, so that I can avoid adding employees with a recent pattern of unannounced absences without further review.

#### Acceptance Criteria

1. WHEN the add-user modal's user-search endpoint returns results THEN each result SHALL include `absence_count` (integer ≥ 0) computed by Absence_Count_Service
2. WHERE the search result list renders a candidate row AND `absence_count ≥ 1` THEN the row SHALL display a warning triangle icon next to the user's name
3. WHEN the admin hovers the warning icon THEN a tooltip SHALL display the count only (e.g., "{N} recent absences"); the tooltip SHALL NOT reference the duration of the time window
4. IF `absence_count` is 0 THEN no warning icon SHALL be rendered for that row

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 4 (Absence_Count_Service)

## Technical Requirements

### Technical Requirement 1: Single source of truth for Absence_Count

**Goal:** Absence_Count is computed by exactly one backend service, callable for one or many users in a single batched operation.

**Rationale:** The mobile registration response, admin assignment list, and add-user search must all return the same value for the same user at the same moment in time. Drift between three independent SQL queries is a known failure mode; centralizing avoids it.

**Acceptance Criteria:**

1. WHERE `absence_count` is returned by any endpoint THEN the value SHALL come from Absence_Count_Service
2. WHEN Absence_Count_Service is called with a list of user IDs THEN it SHALL return all counts in a single database query (no per-user N+1)
3. WHEN Absence_Count_Service is called with a single user ID THEN it SHALL return one integer ≥ 0
4. IF a user has no qualifying shifts in Absence_Window THEN Absence_Count_Service SHALL return 0 for that user

### Technical Requirement 2: Configurable Absence_Window owned by the backend

**Goal:** The Absence_Window duration is read from a backend environment variable at request time, with a sane default. No frontend code or copy references the duration.

**Rationale:** Operations may want to tune the window without a code change; keeping the value in exactly one place avoids drift between backend logic and frontend copy / translations.

**Acceptance Criteria:**

1. WHEN Absence_Count_Service computes a count THEN it SHALL read the window length (in days) from a backend configuration value backed by an environment variable
2. IF the environment variable is unset or invalid (non-positive integer) THEN Absence_Count_Service SHALL fall back to the default value of 180 days
3. WHERE the duration is referenced in the codebase THEN it SHALL appear only on the backend; no frontend code, translation key, or tooltip text SHALL embed the number of days
4. WHEN the configuration value changes THEN subsequent requests SHALL use the new value without code changes or migrations

## Success Criteria

### Definition of Done

- [ ] All functional acceptance criteria pass via PHPUnit feature tests covering the registration endpoint, assignment list endpoint, and add-user search endpoint
- [ ] Absence_Count_Service has unit tests covering: zero absences, qualifying absence, disqualifier with check-in, disqualifier with absent notice, shift inside grace period, shift outside Absence_Window, custom window length via config override
- [ ] Backend `.env.example` documents the new variable; default fallback (180) is verified by a test
- [ ] The two-day grace period boundary is verified by tests (a shift ending yesterday is not counted; a shift ending two days ago is)
- [ ] The admin web app renders the warning icon + tooltip in both surfaces, verified manually
- [ ] No N+1 query regressions on the assignment list or add-user search (verified via query log on a representative dataset)
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` pass on frontend
- [ ] `npm run prettier` and `npm run pint` pass on backend
- [ ] No changes to existing `is_absent` values or `shift_absent_notices` rows
