# Implementation Plan: Admin Dashboard Overview

## Overview

Build the dashboard back-to-front: workplace resolver and shared test trait first (everything depends on them), then each Action + endpoint in isolation, then the frontend layer in three stages (types → presentational components → mockup page with fake data → real data wiring), then end-to-end testing, profiling, and translations. The permission boundary work lands in Phase 1 so any later Action automatically inherits it; the aggregate-only shape assertion lands at the same time so every endpoint test enforces it from day one.

### Implementation Strategy

- **Foundation-first**: `DashboardWorkplaceResolver`, `DashboardThresholds`, and `AssertsAggregateOnlyResponse` ship before any endpoint, so the security/shape contract is set in stone.
- **Frontend in stages, not vertical slices**: types come first, then pure presentational components driven entirely by props, then a real mockup page composed of those components with fake fixture data. Only after the mockup is reviewable do we add the React Query hooks and replace fixtures with live data. This separates "does it look right" from "does it fetch right" so each can be reviewed in isolation.
- **Profile before merge**: the staging-data profiling pass is its own task with a written checkpoint, not a "we'll see" note.

## Implementation Plan

### Phase 1: Foundation and Setup

- [x] 1. Backend foundation
    - [x] 1.1 Add `DashboardWorkplaceResolver` (`backend/app/Services/Dashboard/DashboardWorkplaceResolver.php`)
        - `demand(): ?array`, `reliability(): ?array`, `intersectWithRequested(?array $set, ?array $requested): ?array`, `isEmpty(?array $set): bool`.
        - Uses `User::getWorkplaceIdsByPermission('shift.list')` and `'work_history.list'`.
        - All classes imported via `use` (project rule).
        - _Requirements: 1.3, 1.5, Security 1, Security 2, Security 3_
    - [x] 1.2 Add `DashboardThresholds` constant class (`backend/app/Support/Dashboard/DashboardThresholds.php`)
        - `CAPACITY_UTILIZATION = ['critical' => 0.90, 'tight' => 0.75]` and a `tagFor(?int $activePool, int $assigned): array` helper returning tag plus optional reason.
        - _Requirements: 4.3_
    - [x] 1.3 Add `AssertsAggregateOnlyResponse` trait (`backend/tests/Concerns/AssertsAggregateOnlyResponse.php`)
        - Recursive key walker; case-insensitive comparison against the disallowed list from Technical Requirement 1.1.
        - _Requirements: Technical 1.1, Technical 1.2_
    - [x] 1.4 Unit tests for the resolver (`backend/tests/Unit/Dashboard/DashboardWorkplaceResolverTest.php`)
        - camelCase test methods.
        - Cases: global on both, global on one, neither global, intersection collapsing to empty, out-of-scope request IDs.
        - _Requirements: 1.3, 1.5, 2.5_

- [x] 2. Frontend foundation
    - [x] 2.1 Add `ADMIN_DASHBOARD` to `frontend/src/configs/route.config.ts` and dashboard route file (`frontend/src/pages/admin/dashboard/index.page.tsx`)
        - Route config uses `PermissionNameEnum.SHIFT_LIST.value`; the page can render only a placeholder for this subtask because real composition lands in Phase 3.
        - _Requirements: 1.2, 1.6, 8.3_
    - [x] 2.2 Add `Dashboard` sidebar entry to `frontend/src/configs/sidebar.config.ts`
        - Top-level item near `HOME`, hidden through the existing route-config permission filtering when the user lacks `shift.list`.
        - _Requirements: 8.1, 8.2, 8.3_
    - [x] 2.3 Add dashboard API config constants and shared query keys (`frontend/src/pages/admin/dashboard/queryKeys.ts`)
        - Keys: `['dashboard', 'workplaces']`, `['dashboard', 'kpis', workplaceIds, window]`, etc.
        - _Requirements: 2.2, 2.3_

- [x] 3. Checkpoint — foundation merged and green
    - Verify resolver tests pass; verify sidebar entry hides for a user without `shift.list`.

### Phase 2: Backend endpoints

- [x] 4. Workplaces endpoint
    - [x] 4.1 `GET /api/admin/dashboard/workplaces` controller method + `ListDashboardWorkplacesAction`
        - Returns both `demand_workplaces` and `reliability_workplaces` lists plus the `*_is_global` booleans.
        - _Requirements: 1.3, 2.1_
    - [x] 4.2 Feature test (`backend/tests/Feature/Dashboard/ListDashboardWorkplacesActionTest.php`)
        - Cases: GlobalAdmin → `is_global = true`; Admin with mixed permissions → correct intersection.
        - Apply `assertNoUserIdentifyingFields`.
        - _Requirements: Technical 1.2_

- [x] 5. KPI endpoint
    - [x] 5.1 `GetKpisAction` with the four aggregates
        - Active_Pool query helper defined once and reused.
        - Outcome_Class `CASE WHEN` uses `check_ins` for show and `shift_reports.is_absent = true` for no-show; ignores `shift_users.is_absent`.
        - Average daily check-ins KPI = `COUNT(check_ins) ÷ window_days` over the Historical_Window, scoped to Reliability_Workplaces, rounded to nearest int.
        - No-show risk uses fixed 30d window; ignores `window` param.
        - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 3.6_
    - [x] 5.2 Controller route + request validation (`window ∈ {30,60,90}`, `workplace_ids` array of int)
        - _Requirements: 2.3, 2.5_
    - [x] 5.3 Feature tests (`GetKpisActionTest`) — see Component 3 test list in design.md
        - Including `testShowUpRateUsesAllApprovedAsDenominator`, `testNoShowRiskUsesShiftReportsIsAbsent`, and `testShiftUsersIsAbsentDoesNotAffectMetrics`.
        - _Requirements: 3.2, 3.3, 3.4, 3.5, 3.6, 3.7, Technical 1.2_

- [x] 6. Capacity endpoint
    - [x] 6.1 `GetCapacityTableAction`
        - Workplace/date pressure aggregate for next 14 days; `assigned` counts Approved_Assignment rows; `active_pool` is per-workplace 90-day Active_Pool; `free_bench = max(0, active_pool - assigned)`; `utilization = assigned / active_pool`; `capacity_tag` via `DashboardThresholds::tagFor`.
        - Default multi-workplace response omits zero-assigned rows and sorts by severity/utilization; single-workplace response returns the full 14-day date series.
        - When Reliability_Workplaces empty → keep `assigned`, but set `active_pool = null`, `free_bench = null`, `utilization = null`, `capacity_tag = null`.
        - _Requirements: 4.1, 4.2, 4.3, 4.5, 4.6_
    - [x] 6.2 Controller route + tests (`GetCapacityTableActionTest`) — covers the design.md cases
        - _Requirements: 4.1–4.6, Technical 1.2_

- [x] 7. Demand heatmap endpoint
    - [x] 7.1 `GetDemandHeatmapAction`
        - Uses Reliability_Workplaces. Supports `mode=groups` and `mode=top_slots`; grouped mode buckets by start-time group, top-slot mode caps exact labels with default `limit=12`, max `20`.
        - Returns `null` for empty cells. Collapses identical top-slot labels across workplaces.
        - _Requirements: 5.1, 5.2, 5.4, 5.5, 5.6_
    - [x] 7.2 Controller route + tests
        - _Requirements: 5.1, 5.2, 5.4, 5.5, 5.6, Technical 1.2_

- [x] 8. Show-up rate endpoint
    - [x] 8.1 `GetShowUpRateAction` with both `by_workplace` and `by_workplace_slot` payloads
        - One SQL aggregate grouped by `(workplace_id, workplace_shift_id)`; PHP rolls up `by_workplace`.
        - `low_sample` set when `assigned_count < 5`. Sorted by `show_up_rate ASC`.
        - _Requirements: 6.1, 6.2, 6.4_
    - [x] 8.2 Controller route + tests
        - Includes assertion that response contains only workplace-level identifiers (`workplace_id`, `workplace_name`, `workplace_shift_id`, `slot_label`).
        - _Requirements: 6.1, 6.2, 6.4, 6.5, Technical 1.1, Technical 1.2_

- [x] 9. Checkpoint — all backend endpoints respond < 500 ms p95 on staging
    - Run each endpoint against staging-sized data; record p50/p95 in the PR description. If any endpoint misses the budget, raise a follow-up (caching is intentionally out of scope per Round 1).
    - [x] 9.1 Add index-only migration if EXPLAIN confirms the production-data plan
        - Existing indexes sufficient: `shifts(workplace_id, date)`, `check_ins(shift_user_id)`, `shift_reports(shift_user_id)`, `shift_users(shift_id, user_id)`. No additional migration needed.
        - _Requirements: Performance 1_

### Phase 3: Frontend types and presentational components

The goal of this phase is to land all visible building blocks of the dashboard with **no data fetching**. Every component takes its data through props so it can be reviewed in isolation and reused unchanged once real hooks are wired in Phase 4.

- [x] 10. Shared types
    - [x] 10.1 Type definitions (`frontend/src/pages/admin/dashboard/types.ts`) mirroring every interface in design.md (`KpiResponse`, `CapacityRow`, `HeatmapResponse`, `ReliabilityRow`, `ReliabilityResponse`, workplace list response, etc.)
        - Include the `Window = 30 | 60 | 90` literal union and a `WorkplaceOption` shape used by the multiselect.
        - No React, no hooks — pure types only.
        - _Requirements: 3.1, 4.2, 5.1, 6.1_

- [x] 11. Presentational components (`frontend/src/pages/admin/dashboard/components/`)
    - Each component is a pure client component: data comes in as props, no React Query, no `fetch`. Each gets a co-located `*.fixtures.ts` exporting representative fake data (loading, empty, redacted, populated) for use by the mockup page in Task 12 and by tests later.
    - [x] 11.1 `WorkplaceMultiSelect` (Mantine `MultiSelect`)
        - Props: `options: WorkplaceOption[]`, `value`, `onChange`. Default-selects all when uncontrolled. No data fetching.
        - _Requirements: 2.1, 2.2, 2.4_
    - [x] 11.2 `WindowDropdown` (Mantine `SegmentedControl`, options 30/60/90, default 90)
        - Props: `value: Window`, `onChange`.
        - _Requirements: 2.3_
    - [x] 11.3 `KpiCardRow` — four cards driven by a `KpiResponse | null` prop plus a `loading` boolean
        - Skeletons while `loading`; `—` for null values; delta indicator inverts colour semantics for `no_show_risk`; free-bench card shows the date at which the minimum occurs.
        - _Requirements: 3.1, 3.4, 3.6, 3.7_
    - [x] 11.4 `CapacityTable` — 14-row Mantine `Table` driven by `rows: CapacityRow[]`
        - Rows 0–6 visually highlighted; renders `—` and a redacted label when `free_bench` is null.
        - _Requirements: 4.1, 4.4, 4.6_
    - [x] 11.5 `DemandHeatmap` — CSS grid driven by `data: HeatmapResponse`
        - Six-bucket quantile classification across visible cells; null cells in muted grey. Bucketing math lives here and is pure.
        - _Requirements: 5.3, 5.4_
    - [x] 11.6 `ShowUpRateWidget` — per-workplace bar chart driven by `data: ReliabilityResponse`
        - Low-sample badge; inline drill-down per (workplace × shift slot) via row expansion or a "View by slot" affordance; renders only workplace-level identifiers.
        - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_
    - [x] 11.7 `ForecastEntryCard` — static card with `next/link` to `/admin/forecast`
        - No inputs, no candidate lists.
        - _Requirements: 7.1, 7.2, 7.3, 7.4_
    - [x] 11.8 `ReliabilityRedactedHint` — explicit "Reliability data hidden — needs `work_history.list` permission" banner used by KPI/reliability widgets when reliability data is unavailable
        - Pure presentational; KPI and reliability components accept a `reliabilityAvailable` prop and render this hint when false.
        - _Requirements: 1.5_

- [x] 12. Mockup page composition (real components, fake data)
    - [x] 12.1 Replace the Phase 2 placeholder in `frontend/src/pages/admin/dashboard/index.page.tsx` with the full layout: Mantine `Grid` + `Card` shell, controls row (`WorkplaceMultiSelect`, `WindowDropdown`), and every widget from Task 11 composed in their final positions.
        - Wire the controls to local `useState` so the user can click around — no API calls.
        - Feed each widget from its fixtures file from Task 11; expose a small dev-only fixture switcher (or static fixture choice per widget) so reviewers can see populated, empty, redacted, and low-sample states.
        - Render the empty state when the (faked) demand workplaces array is empty.
        - This task ends when a reviewer can open `/admin/dashboard` in the browser and see the real layout populated with fake data — no network calls in the panel.
        - _Requirements: 1.6, 2.1, 2.2, 2.3, 2.4_

- [x] 12.2 Phase 3 mockup-review follow-ups (Round 5)
    - `CapacityTable` and `ShowUpRateWidget` wrap their rows in bounded-height scroll containers to handle ~48-workplace × 14-day production scale.
    - `CapacityTable` adds a `· small pool` indicator and a tooltip when `active_pool > 0 AND active_pool ≤ 5`, so admins can recognise noisy critical rows without us moving the threshold.
    - `WorkplaceMultiSelect` rebuilt as a single-row chip trigger (first 3 chips + "+N more") with a modal containing search, Select all / Clear, and per-workplace checkboxes.
    - KPI row: Capacity Pressure card replaced with **Average daily check-ins** (`COUNT(check_ins) ÷ window_days`, scoped to Reliability_Workplaces). Backend Task 5.1 and `KpiResponse` updated; the dedicated Capacity widget remains the single surface for capacity pressure.

### Phase 4: Frontend data wiring

- [x] 13. React Query hooks (`frontend/src/pages/admin/dashboard/hooks/`)
    - [x] 13.1 `useDashboardWorkplaces`, `useDashboardKpis`, `useCapacityTable`, `useDemandHeatmap`, `useShowUpRate`
        - Each backed by React Query; key includes workplaceIds + window where relevant (capacity does not depend on window). Each returns typed data from Task 10.
        - _Requirements: 2.2, 2.3_

- [x] 14. Replace mockup fixtures with live data in `dashboard/index.page.tsx`
    - [x] 14.1 Swap each component's `data` prop from fixtures to hook results; thread loading and error states (per-widget error banner with retry, does not unmount siblings).
        - Empty state now triggers from the real `demand_workplaces` response.
        - Reliability-redacted hint now driven by real `reliability_available` flag.
        - Remove the dev-only fixture switcher from Task 12.1.
        - _Requirements: 1.5, 1.6, 2.4, 3.1_

### Phase 5: Translations and polish

- [x] 15. Translation flow
    - [x] 15.1 Wrap every visible string with `t(...)` keys (no hardcoded text)
        - _Requirements: Usability 2_
    - [x] 15.2 Run `pnpm trans:prepare`; fill `.translation-workflow/translation-batch.json` with `vi` and `ja` values; run `pnpm trans:apply`; run `pnpm trans:cleanup`
        - _Requirements: Usability 2_

### Phase 6: Testing

- [x] 16. Frontend tests (`frontend/src/pages/admin/dashboard/__tests__/dashboard.test.tsx`)
    - [x] 16.1 Skeleton render before queries resolve
        - _Requirements: 3.1_
    - [x] 16.2 Empty state when `demand_workplaces` is empty
        - _Requirements: 1.6, 2.4_
    - [x] 16.3 Sidebar entry hides for a user without `shift.list`
        - _Requirements: 8.3_
    - [x] 16.4 Changing window refetches KPI, demand heatmap, and show-up rate requests, does not refetch capacity, and leaves no-show risk value independent of the selected window
        - _Requirements: 2.3, 3.5_

- [x] 17. End-to-end integration smoke
    - [x] 17.1 Seeded scenario: GlobalAdmin sees all KPIs populated; mixed-permission Admin sees demand widgets for {1, 2} and reliability widgets computed only on {2}
        - _Requirements: 1.3, 1.5, Security 3_

### Phase 7: Final Validation

- [ ] 18. Code Quality
    - [ ] 18.1 Backend: `npm run pint` (inside `./backend`)
    - [ ] 18.2 Backend: `npm run prettier` (inside `./backend`)
    - [ ] 18.3 Frontend: `pnpm lint:fix` (inside `./frontend`)
    - [ ] 18.4 Frontend: `pnpm prettier` (inside `./frontend`)
    - [ ] 18.5 Frontend: `pnpm typecheck` (inside `./frontend`)
    - [ ] 18.6 Backend: `docker compose exec php ./vendor/bin/phpunit` (full suite)

## Notes

- **Conventions**: PHP — `use` imports only, Action pattern, camelCase PHPUnit methods. Frontend — `t(...)` for all visible text, no manual locale-JSON edits before code changes.
- **Ordering rationale**: Phase 1 sets the security + shape contract once so every later test inherits it; Phase 2 finishes each endpoint with its own tests before any frontend code so the contract is locked before composing widgets; Phase 3 lands the frontend visuals in three steps — types, then prop-driven components, then a real-page mockup with fake data — so layout and visual states are reviewable before any network code is written; Phase 4 swaps fixtures for live React Query hooks against the now-locked endpoint contracts; translations land late (Phase 5) so we run the scanner over the final code.
- **Risks**:
    - Active_Pool can be expensive on large `check_ins` tables — the staging profiling pass in Task 9 is load-bearing. If it fails, the spec stops and re-opens caching (Round 1 fallback).
    - No-show metrics depend on `shift_reports.is_absent` join cardinality — use `EXISTS` or pre-aggregation if needed so multiple reports cannot duplicate assignments.
    - Permission helper return semantics — `null` vs. empty array — must be handled identically by `DashboardWorkplaceResolver::intersect`; Task 1.4 tests this explicitly.
