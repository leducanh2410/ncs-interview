# Requirements Document

## Introduction

The Admin Dashboard Overview is a read-only landing page at `/admin/dashboard` that gives workforce-management admins an at-a-glance picture of supply, demand, and reliability across the workplaces they have access to. It replaces ad-hoc reporting questions ("how big is our active pool right now?", "can we accept an order on Friday?", "which sites are flaky?") with a single page driven by aggregate SQL queries. No user-level data appears on the overview; per-candidate detail is reserved for the future forecast and drill-down specs.

### Feature Summary

A workplace-scoped admin dashboard with KPI cards, a 7/14-day capacity table, a day-of-week × shift-slot demand heatmap, a per-workplace and per-slot show-up reliability view, and a link card pointing at the (future) forecast page.

### Business Value

Admins can decide whether to accept a new shift order, where to over-assign for reliability, and which sites need attention — without exporting CSVs or running custom queries. The aggregate-only contract guarantees the page can be opened by any admin without leaking user-level data outside their permission scope.

### Scope

**Included**
- New page at `/admin/dashboard` (Next.js + Mantine) gated by the `shift.list` permission, with the existing permission-driven sidebar/router infrastructure deciding visibility
- Workplace multi-select switcher (default = all accessible workplaces) and a 30 / 60 / 90 day historical-window dropdown
- Four KPI cards: active worker pool, show-up rate, average daily check-ins, no-show risk last 30d
- Capacity pressure table for the next 14 days (workplace/date, assigned, active pool, utilization, capacity tag)
- Demand heatmap (day-of-week × grouped slot bucket, plus a capped top-slot view) over the selected historical window
- Show-up rate widget with two views: aggregate per workplace, drill-down per (workplace × shift slot)
- Forecast entry-point card linking to `/admin/forecast`
- New aggregate API endpoints under `/api/admin/dashboard/*`, all constrained by workplace IDs resolved with the same semantics as `FiltersByWorkplace`
- Sidebar nav entry for the dashboard (top-level item near Home in the existing sidebar config)
- Frontend translations for all new strings via the standard `pnpm trans:prepare` flow

**Excluded**
- Forecast computation / forecast page UI (separate spec)
- Top no-show users table and any other user-level surface on the dashboard
- Approved-absence count in the free-bench formula
- Caching, snapshot tables, or background precomputation
- Custom date-range picker (only fixed 30 / 60 / 90)

## Glossary

| Term | Definition |
|------|------------|
| Demand_Workplaces | The set of workplace IDs the caller can see for **demand/capacity** queries — resolved by the same logic as `applyWorkplaceFilter($user, 'shift.list')` (returns a list of IDs, or `null` for global access). |
| Reliability_Workplaces | The set of workplace IDs the caller can see for **check-in-derived** metrics — the intersection of `shift.list` and `work_history.list` accessible workplaces (per-permission lookup via `User::getWorkplaceIdsByPermission`). Used for Active_Pool, Show_Up_Rate, No-show risk, Demand_Heatmap, and the per-workplace reliability widget. |
| Historical_Window | The 30, 60, or 90-day lookback window controlled by the dashboard dropdown (default 90). |
| Approved_Assignment | A `shift_users` row whose `status` equals the approved value of `ShiftUserStatus`. `shift_users.is_absent` is an admin worktime-validation flag and SHALL NOT alter dashboard metric denominators or numerators. |
| Outcome_Class | The classification of one Approved_Assignment after its shift date has passed: `show` (has a matching `check_ins` row), `no_show` (no check-in AND a linked `shift_reports.is_absent = true` report), or `missing_unreported` (no check-in and no no-show report). |
| Active_Pool(W) | Distinct `user_id`s with at least one row in `check_ins` for a shift inside Reliability_Workplaces over lookback window W. Unless otherwise specified, W is the selected Historical_Window. |
| Show_Up_Rate | `COUNT(Outcome_Class = show) ÷ COUNT(Approved_Assignment)` over the Historical_Window, scoped to Reliability_Workplaces. |
| No_Show_Rate | `COUNT(Outcome_Class = no_show) ÷ COUNT(Approved_Assignment)` over the last 30 days, scoped to Reliability_Workplaces. |
| Free_Bench (workplace/date D, pool window W) | `max(0, |Active_Pool(W)| − assigned)` for a single workplace/date row. Capacity pressure uses the default 90-day pool window so the capacity table is stable when the dropdown changes. Free_Bench is supporting detail, not a global KPI. |
| Capacity_Utilization | `assigned ÷ Active_Pool(90d)` for a workplace/date row when Active_Pool is greater than zero. |
| Capacity_Pressure | A ratio-based workplace/date capacity tag: `critical` when utilization ≥ 90%, `tight` when utilization ≥ 75%, `healthy` otherwise. If `active_pool = 0` and `assigned > 0`, the row is `critical` with reason `no_pool`. |
| Shift_Slot | A `workplace_shifts` row, identified to the user by the `start_time–end_time` label of the underlying workplace shift. |
| Slot_Group | A stable time-of-day bucket derived from `workplace_shifts.start_time`, used to keep the default heatmap scannable. |
| Dashboard_API | The backend module exposing `/api/admin/dashboard/*` aggregate endpoints. |
| Dashboard_Page | The frontend page rendered at `/admin/dashboard`. |

## Requirements

### Requirement 1: Permission-scoped dashboard access

**User Story:** As a workforce admin, I want the dashboard to show only data for the workplaces my permissions allow, so that I can trust the numbers without worrying about leaking other teams' data.

#### Acceptance Criteria

1. WHEN an unauthenticated request reaches any `/api/admin/dashboard/*` endpoint THEN Dashboard_API SHALL respond with `401 Unauthorized`.
2. IF the authenticated user does not have the `shift.list` permission on any workplace THEN Dashboard_API SHALL respond with `403 Forbidden` AND the frontend permission-driven router SHALL hide the dashboard route and sidebar entry for that user.
3. WHEN any `/api/admin/dashboard/*` endpoint runs THEN Dashboard_API SHALL resolve **Demand_Workplaces** and **Reliability_Workplaces** up front using the same logic as `applyWorkplaceFilter` (a list of workplace IDs, or `null` for global access) and SHALL constrain every aggregate query — Eloquent or raw — by the relevant set.
4. IF Demand_Workplaces is an empty set THEN Dashboard_API SHALL return a `200` response with zeroed counts and empty arrays for demand/capacity widgets, so Dashboard_Page can render an empty state without a follow-up call.
5. IF Reliability_Workplaces is an empty set THEN Dashboard_API SHALL return `null` (not zero) for Show_Up_Rate, No_Show_Rate, Active_Pool, and the show-up reliability widget, AND Dashboard_Page SHALL render an explicit "Reliability data hidden — needs `work_history.list` permission" indicator on those widgets.
6. WHEN Dashboard_Page loads AND the user has no accessible workplaces under `shift.list` THEN Dashboard_Page SHALL render a full-page empty-state message instead of the widgets.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Existing `FiltersByWorkplace` trait and `User::getWorkplaceIdsByPermission`.

### Requirement 2: Workplace and window controls

**User Story:** As a workforce admin, I want to switch between accessible workplaces and pick a 30 / 60 / 90 day window, so that I can focus on a subset of sites or a different time horizon.

#### Acceptance Criteria

1. WHEN Dashboard_Page first mounts THEN Dashboard_Page SHALL fetch the user's Demand_Workplaces and Reliability_Workplaces and select all Demand_Workplaces by default in the switcher.
2. WHEN the user changes the workplace multi-select THEN Dashboard_Page SHALL refetch every widget endpoint using the new workplace selection.
3. WHEN the user picks a window value of `30`, `60`, or `90` THEN Dashboard_Page SHALL refetch the KPI, demand heatmap, and show-up rate widget requests with the new window. Capacity table SHALL NOT be refetched. No-show risk remains fixed to the latest 30-day window even when it is returned by a refetched KPI payload.
4. IF the workplace multi-select selection is empty THEN Dashboard_Page SHALL disable refetching and render the empty state from Requirement 1.6.
5. WHERE a workplace ID submitted in a request is not in the relevant accessible set (Demand_Workplaces for demand/capacity endpoints; Reliability_Workplaces for reliability endpoints) THEN Dashboard_API SHALL silently exclude it from the aggregate (no error).

#### Additional Details
- **Priority**: High
- **Complexity**: Low

### Requirement 3: KPI cards

**User Story:** As a workforce admin, I want four headline numbers above the fold, so that I can read the team's current state in under five seconds.

#### Acceptance Criteria

1. WHEN Dashboard_Page renders the KPI row THEN Dashboard_Page SHALL display four cards in this order: Active worker pool, Average show-up rate, Average daily check-ins, No-show risk (30d).
2. WHEN Dashboard_API computes the Active worker pool KPI THEN Dashboard_API SHALL return `|Active_Pool|` over the Historical_Window plus a `delta` versus the immediately preceding window of equal length.
3. WHEN Dashboard_API computes the Show_Up_Rate KPI THEN Dashboard_API SHALL return the percentage rounded to one decimal place plus a `delta` versus the immediately preceding window of equal length.
4. WHEN Dashboard_API computes the Average daily check-ins KPI THEN Dashboard_API SHALL return `COUNT(check_ins) ÷ window_days` over the Historical_Window scoped to Reliability_Workplaces, rounded to the nearest integer, plus a `delta` versus the immediately preceding window of equal length.
5. WHEN Dashboard_API computes the No-show risk KPI THEN Dashboard_API SHALL return `No_Show_Rate` using `shift_reports.is_absent = true` as the no-show source plus a delta versus the prior 30 days. (Independent of the Historical_Window dropdown.)
6. IF the denominator for show-up rate or no-show risk is zero THEN Dashboard_API SHALL return `null` for that value AND Dashboard_Page SHALL render `—` instead of a percentage.
7. WHEN a delta is negative for show-up rate or active pool THEN Dashboard_Page SHALL display a downward indicator. WHEN a delta is negative for no-show risk THEN Dashboard_Page SHALL display an **improvement** indicator (down-arrow styled positively), because lower no-show risk is better.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium

### Requirement 4: Capacity pressure — next 14 days

**User Story:** As a workforce admin, I want a workplace-by-date capacity pressure table for the next two weeks, so that I can answer "which site is constrained on date D?" without leaving the dashboard.

#### Acceptance Criteria

1. WHEN Dashboard_Page renders the capacity widget THEN Dashboard_Page SHALL display workplace/date rows for the next 14 days starting today (Asia/Tokyo), sorted by `capacity_tag` severity, utilization descending, assigned descending, then date ascending.
2. WHEN Dashboard_API returns a capacity row THEN the row SHALL contain `date`, `day_of_week`, `workplace_id`, `workplace_name`, `active_pool`, `assigned` (distinct `user_id`s in Approved_Assignment for that workplace/date), `free_bench`, `utilization`, `capacity_tag`, and optional `capacity_reason`.
3. WHEN Dashboard_API computes `capacity_tag` THEN Dashboard_API SHALL classify rows as `critical` when `active_pool = 0 AND assigned > 0`, or when utilization ≥ 90%; `tight` when utilization ≥ 75%; and `healthy` otherwise. Thresholds SHALL live in a single backend constant.
4. WHERE the rendered row's date is today or within the next 7 days THEN Dashboard_Page SHALL visually highlight the row so the 7-day horizon is distinguishable from the 8–14 day rows.
5. IF a workplace/date has zero assigned users THEN Dashboard_API MAY omit the row from the default pressure table unless the selected workplace count is one, in which case it SHALL return the full 14-day date series for that workplace.
6. IF Reliability_Workplaces is empty (so Active_Pool is unavailable) THEN Dashboard_API SHALL still return assigned counts where possible, but SHALL set `active_pool = null`, `free_bench = null`, `utilization = null`, and `capacity_tag = null`, AND Dashboard_Page SHALL render a redacted state for the capacity columns.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium

### Requirement 5: Demand heatmap

**User Story:** As a workforce admin, I want to see which day-of-week and shift slot we historically staff the most, so that I know where demand reliably lands.

#### Acceptance Criteria

1. WHEN Dashboard_API computes the default heatmap THEN Dashboard_API SHALL return one row per Slot_Group used by any shift in Reliability_Workplaces during the Historical_Window, and one cell per (group, day_of_week) pair.
2. WHEN Dashboard_API computes a cell value THEN the cell SHALL contain the average count of `check_ins` per occurrence of that (slot, day_of_week) pair in the window, rounded to the nearest integer.
3. WHEN Dashboard_Page renders the heatmap THEN Dashboard_Page SHALL bucket cell values into six intensity bands (`h1`–`h6`) by quantile over the visible cells, matching the mockup palette.
4. WHEN no shifts exist for a (slot, day_of_week) pair in the window THEN Dashboard_API SHALL return `null` for that cell AND Dashboard_Page SHALL render the cell as muted/grey.
5. WHERE the user switches to the top-slot view THEN Dashboard_API SHALL return exact Shift_Slot labels capped by a `limit` parameter with default 12 and maximum 20, sorted by occurrence count descending, then check-in count descending.
6. WHERE the slot label collides between workplaces (e.g., two workplaces both have "Morning · 06:00–14:00") THEN Dashboard_API SHALL group them under a single top-slot row, averaging across all matching workplace shifts.

#### Additional Details
- **Priority**: Medium
- **Complexity**: High
- **Dependencies**: `workplace_shifts`, `shifts`, `check_ins`.

### Requirement 6: Show-up rate by workplace and slot

**User Story:** As a workforce admin, I want to compare reliability across my sites and shift slots, so that I know where to over-assign.

#### Acceptance Criteria

1. WHEN Dashboard_API computes the per-workplace show-up rate THEN Dashboard_API SHALL return one row per workplace in Reliability_Workplaces with at least one Approved_Assignment in the Historical_Window, containing `workplace_id`, `workplace_name`, `assigned_count`, `check_in_count`, and `show_up_rate` (rounded to one decimal place).
2. WHEN Dashboard_API computes the (workplace × slot) drill-down THEN Dashboard_API SHALL return rows keyed by `(workplace_id, workplace_shift_id)` with the same metrics, sorted by `show_up_rate` ascending so the least-reliable combinations surface first.
3. WHEN Dashboard_Page renders the widget THEN Dashboard_Page SHALL show the per-workplace bar chart by default and reveal the (workplace × slot) drill-down inline when the user expands a row or clicks a "View by slot" affordance.
4. IF a workplace has fewer than 5 Approved_Assignments in the window THEN Dashboard_API SHALL still return the row but SHALL set `low_sample = true` so Dashboard_Page can visually mark it.
5. WHEN the drill-down view is rendered THEN Dashboard_Page SHALL NOT display any user-level information (no names, no IDs).

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium

### Requirement 7: Forecast entry-point card

**User Story:** As a workforce admin, I want a clearly visible jump-off point to the forecast tool from the dashboard, so that I can move from a capacity question to a forecast in one click.

#### Acceptance Criteria

1. WHEN Dashboard_Page renders THEN Dashboard_Page SHALL display a single card titled "Forecast an incoming order" with a one-sentence description and a primary button labeled "Open forecast".
2. WHEN the user clicks the "Open forecast" button THEN Dashboard_Page SHALL navigate to `/admin/forecast` using the existing Next.js router.
3. WHERE the route `/admin/forecast` does not yet exist THEN Dashboard_Page SHALL still link to it. (The destination is the responsibility of the future forecast spec.)
4. Dashboard_Page SHALL NOT render any forecast inputs, computed numbers, or candidate lists.

#### Additional Details
- **Priority**: Low
- **Complexity**: Low

### Requirement 8: Sidebar navigation entry

**User Story:** As a workforce admin, I want a dashboard link in the admin sidebar, so that I can return to the overview from any other admin page.

#### Acceptance Criteria

1. WHEN the admin layout sidebar renders for a user whose effective permissions include `shift.list` on at least one workplace THEN the sidebar SHALL include a `Dashboard` entry as a top-level item near Home in the existing sidebar config.
2. WHEN the user is currently on `/admin/dashboard` THEN the sidebar entry SHALL render in its active state matching the existing admin nav styling.
3. IF the user does not hold `shift.list` on any workplace THEN both the sidebar entry and the `/admin/dashboard` route SHALL be hidden by the existing permission-driven routing.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low

## Technical Requirements

### Technical Requirement 1: Aggregate-only API surface

**Goal:** Every endpoint under `/api/admin/dashboard/*` returns only aggregate counts, rates, dates, and workplace/slot identifiers — never user IDs, names, or any per-user payload. Workplace identifiers (e.g., `workplace_name`) are explicitly allowed.

**Rationale:** Hard-coding this at the API boundary (rather than only at the UI) keeps later specs honest: any future "drill-down" surface will be a new, separately-permissioned endpoint, not a flag on an existing one. It also protects against UI bugs leaking user-level data picked up from a richer endpoint.

**Acceptance Criteria:**
1. WHEN a `/api/admin/dashboard/*` endpoint constructs its response THEN the response SHALL contain no field that identifies a specific user — explicitly disallowed keys (case-insensitive, at any nesting level) are `user_id`, `user_name`, `username`, `email`, `staff_no`, `employee_no`, `phone`, `first_name`, `last_name`, and `full_name`.
2. WHEN endpoint tests assert response shape THEN the assertion SHALL recursively walk the response body and fail if any key in the disallowed list above appears. Generic keys such as `name` (used for `workplace_name`) and `id` (used for `workplace_id`, `workplace_shift_id`) SHALL NOT trigger the assertion.
3. WHEN a new dashboard endpoint is added in future work THEN the same shape assertion SHALL apply unless the endpoint lives under a different route prefix.

### Technical Requirement 2: Action-pattern implementation per project conventions

**Goal:** Each dashboard endpoint is implemented via the project's existing Action pattern.

**Rationale:** Project rule in `CLAUDE.md`. Keeps the controller thin and lets unit tests target the Action directly.

**Acceptance Criteria:**
1. WHEN a controller method handles a `/api/admin/dashboard/*` request THEN the controller method SHALL delegate to an Action class.
2. WHEN an Action class is added THEN it SHALL import all referenced classes via `use` statements (no inline FQNs).
3. WHEN a PHPUnit test is added THEN every method (including helpers and `setUp`) SHALL be camelCase.

## Non-Functional Requirements

### Performance Requirements

1. WHEN any `/api/admin/dashboard/*` endpoint is called with realistic staging data (≈100k `shift_users`, ≈100k `check_ins`, ≈500 active users) THEN Dashboard_API SHALL respond within 500 ms p95.
2. WHEN Dashboard_Page first paints THEN Dashboard_Page SHALL render KPI cards within 1.5 s on a typical office connection, allowing other widgets to stream in afterwards.

### Security Requirements

1. WHEN any `/api/admin/dashboard/*` request is processed THEN Dashboard_API SHALL resolve Demand_Workplaces and Reliability_Workplaces (using the same logic as `applyWorkplaceFilter`) before executing any aggregate query — Eloquent or raw — and SHALL constrain that query by the relevant workplace ID list. (Re-stated from Requirement 1 to make it explicit at the security layer.)
2. WHEN a workplace ID is submitted via query string THEN Dashboard_API SHALL intersect it with the relevant accessible set (Demand or Reliability depending on the endpoint) before use; out-of-scope IDs are silently dropped, never error-revealing.
3. WHEN an endpoint serves a check-in-derived metric (Active_Pool, Show_Up_Rate, No_Show_Rate, demand heatmap, show-up reliability widget) THEN Dashboard_API SHALL use Reliability_Workplaces, not Demand_Workplaces, as the workplace boundary — so a caller with `shift.list` but not `work_history.list` on a workplace cannot infer check-in patterns at that workplace.

### Usability Requirements

1. WHEN any KPI value or widget has insufficient data THEN Dashboard_Page SHALL render an explicit "Not enough data" or `—` indicator rather than `0` or an empty chart.
2. WHEN Dashboard_Page renders any visible text THEN the text SHALL come from translation keys (no hardcoded user-facing strings), per the project translation rules.

## Constraints and Assumptions

### Technical Constraints

- Live SQL aggregates only — no caching layer, snapshot tables, or background jobs in V1.
- Backend stays on Laravel 11; new endpoints reuse the existing `Route::group(['prefix' => 'admin'])` and middleware stack.
- Frontend stays on Next.js 15 + React 19 + Mantine; no new UI library may be introduced.

### Assumptions

- Production-data validation showed the global reliability query needs optimization. Validate endpoint SQL with EXPLAIN during implementation, and add targeted composite indexes in the same implementation if needed. Candidate indexes are `shifts(date, workplace_id, id, workplace_shift_id)`, `shift_users(shift_id, status, user_id)`, and `shift_reports(shift_user_id, is_absent)`.
- `ShiftUserStatus` has a single "approved" terminal value used as the show-up-rate denominator.
- All dates use the project's existing Asia/Tokyo timezone configuration.

## Success Criteria

### Definition of Done

- [ ] All Requirement 1–8 acceptance criteria pass automated test coverage where applicable.
- [ ] Technical Requirements 1–2 are enforced by tests (aggregate-only shape assertion, Action-pattern usage).
- [ ] Performance Requirement 1 is verified by a manual profiling pass on staging data, results recorded in the PR description.
- [ ] All visible strings flow through the standard translation pipeline (`pnpm trans:prepare` → fill `vi` and `ja` → `pnpm trans:apply`).
- [ ] `pnpm lint:fix`, `pnpm prettier`, and `pnpm typecheck` succeed on the frontend.
- [ ] `npm run prettier` and `npm run pint` succeed on the backend.
- [ ] No `/api/admin/dashboard/*` response includes any disallowed user-identifying field (`user_id`, `user_name`, `username`, `email`, `staff_no`, `employee_no`, `phone`, `first_name`, `last_name`, `full_name`) at any nesting level (asserted in tests).
- [ ] Admin sidebar shows the `Dashboard` entry for users with `shift.list` and hides it for users without (asserted in a frontend test or verified manually).
- [ ] A user with `shift.list` but no `work_history.list` on any workplace sees demand/capacity widgets populated and reliability widgets in the "redacted" state (manual verification).
