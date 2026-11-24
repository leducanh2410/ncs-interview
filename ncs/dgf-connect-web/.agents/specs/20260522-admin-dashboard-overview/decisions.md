---
name: decisions
description: Clarifications and confirmed decisions for the Admin Dashboard overview spec (Spec 1 of the dashboard initiative)
---

# Decisions Log: Admin Dashboard Overview

## Overview

Spec 1 of a multi-spec dashboard initiative. Delivers a read-only admin overview page at `/admin/dashboard` with KPI cards, a 7/14-day capacity table, a demand heatmap, and a workplace × shift-slot show-up reliability view, plus a small link card pointing at the (future) forecast page. All data is computed by aggregate SQL queries with strict workplace-permission filtering — no user-level information surfaces on the overview. **Size: Medium** — a single primary user journey (admin opens dashboard), 5–6 widgets, multiple new aggregate endpoints, but no new data models and leverages the existing `FiltersByWorkplace` trait.

## Questions and Answers

### Round 1: Audience, freshness, and scope shape

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Who is the primary user and how strict is workplace scoping? | Both `GlobalAdmin` and `Admin`, with a workplace multi-select switcher | Page is accessible to both admin roles. A multi-select control at the top defaults to all accessible workplaces; KPIs recompute on change. Admin users with zero accessible workplaces see an empty state. |
| Data freshness model? | Live SQL aggregates per request | No caching layer, no precomputed snapshot table for V1. Endpoints must hit indexed columns; queries are profiled before merge. |
| Definition of the "absence / no-show risk" KPI? | % no-shows in last 30 days | Single number = no-show report count ÷ approved assignments over last 30d (scoped to accessible workplaces). Sub-line shows delta vs prior 30d. No upcoming-absence count in V1. |
| Forecast entry point shape? | Link card only | Small card with a button that links to `/admin/forecast`. No embedded mini-form, no forecast computation in this spec. The forecast page itself is out of scope (later spec). |

### Round 2: Date window, free-bench, and per-workplace detail

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Date window for KPIs and heatmap? | Selectable 30 / 60 / 90 days | A dropdown at the dashboard top drives the historical window for show-up rate, heatmap, and active-pool KPI. Default 90d. Capacity table is always next 7/14 days regardless of dropdown. |
| Definition of "free bench" for KPI + capacity table? | Active pool − distinct approved users assigned that date | Simpler formula. Approved absences are not handled as a separate add-back in V1; `assigned` follows Approved_Assignment semantics and therefore includes approved absent rows. |
| Per-workplace detail in show-up-rate widget? | Per workplace + per shift slot drill-down | Two views: (a) bar chart of show-up % by accessible workplace, (b) drill-down view per (workplace × shift slot). No user-level data ("top no-show users" table from the mockup is **out of scope**). |

### Round 3: Permission model, query scoping, denominator semantics, shape assertion, and free-bench floor

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Should page access be role-based or permission-based? | Permission-based, via `shift.list` | Page gating, sidebar visibility, and `/api/admin/dashboard/*` 403 behavior all driven by `shift.list` (the existing frontend permission-driven router handles the page surface). `RoleName` checks removed from the requirement. |
| Is `shift.list` the right permission boundary for check-in-derived metrics? | No — use **intersection** of `shift.list` and `work_history.list` for those | Introduced two glossary terms: **Demand_Workplaces** (`shift.list`) for demand/capacity widgets, and **Reliability_Workplaces** (`shift.list ∩ work_history.list`) for Active_Pool, Show_Up_Rate, No_Show_Rate, demand heatmap, and the show-up reliability widget. A caller with only `shift.list` on a workplace cannot infer check-in patterns there. |
| Original wording said "apply `applyWorkplaceFilter` before every aggregate query" — too strict for raw SQL. | Reworded | Now: "resolve accessible workplace IDs using the same logic as `applyWorkplaceFilter`, then constrain every aggregate query — Eloquent or raw — by those IDs." |
| Does Approved_Assignment exclude absent rows? | No — keep them in the denominator | Approved_Assignment is now any `shift_users` row in approved status. `shift_users.is_absent` is an admin worktime-validation flag and is ignored by dashboard metrics. Show_Up_Rate = check-ins ÷ all approved; No_Show_Rate = absent shift reports ÷ all approved. |
| Should the aggregate-only shape test reject generic key `name`? | No — that collides with `workplace_name` | Shape assertion now rejects an explicit list of user-identifying keys: `user_id`, `user_name`, `username`, `email`, `staff_no`, `employee_no`, `phone`, `first_name`, `last_name`, `full_name`. Generic `name` and `id` are allowed (and used for workplace identifiers). |
| Should Free_Bench be capped at zero? | Yes | Glossary updated: `Free_Bench(D) = max(0, |Active_Pool| − assigned_distinct_users(D))`. Prevents negative values when committed users exceed the active pool (e.g., low-activity historical users assigned but not yet checked in for the window). |

### Round 4: Production-data validation refinements

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Should dashboard metrics use `shift_users.is_absent`? | No | `shift_users.is_absent` is toggled by admins so a shift is excluded from worktime calculation validation. It is not a workforce reliability signal and SHALL NOT be used by dashboard metrics. |
| What source defines no-show? | `shift_reports.is_absent` | No-show count = approved assignments with no check-in and a linked `shift_reports.is_absent = true` report. Missing check-ins without such a report are not counted as no-show in V1. |
| Global free-bench/capacity was always healthy in production data. What replaces it? | Capacity pressure | Replace the KPI's "Free bench next 7 days" with "Capacity pressure next 7 days": count of critical/tight workplace-days plus the worst date/workplace. The capacity widget becomes a workplace-day pressure table instead of a global 14-row date table when multiple workplaces are selected. |
| How should capacity tags be scaled? | Ratio-based utilization | `utilization = assigned ÷ active_pool` when active pool is available. Tag critical when utilization ≥ 90%, tight when utilization ≥ 75%, healthy otherwise. Rows with `active_pool = 0` and `assigned > 0` are critical with a `no_pool` reason. Thresholds live in one backend constant. |
| Demand heatmap row count was too high. How should it be controlled? | Grouping plus top-N | Default heatmap shows grouped time-of-day buckets. A "Top slots" view shows the top exact slot labels by occurrence/check-in volume, capped by backend `limit` default 12. |
| Reliability query exceeded 500 ms globally. What changes? | Query reshape plus indexes | Use one slot-grain aggregate query and roll it up to workplace rows in PHP; filter shifts date-first; add targeted composite indexes if EXPLAIN confirms the production shape. |

### Round 5: Phase 3 mockup review against production data

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Is the Capacity Pressure KPI redundant with the dedicated Capacity widget? | Yes | Replace the KPI slot with **Average daily check-ins** over the Historical_Window, scoped to Reliability_Workplaces. Formula: `COUNT(check_ins) ÷ window_days`, rounded to the nearest integer, with a delta vs. the immediately prior equal-length window. The capacity widget remains the single surface for workplace-day pressure. |
| ~48 workplaces + 14 days yields 100+ capacity rows and ~48 reliability bars. How should the frontend handle this without dominating the page? | Bounded scroll regions | `CapacityTable` uses `Table.ScrollContainer maxHeight=520`; `ShowUpRateWidget` wraps bars in `ScrollArea.Autosize mah={520}`. Out-of-bounds rows remain accessible via scroll instead of pushing other widgets down. |
| Production has tiny-pool workplaces where utilization ≥ 90% comes from `pool=4, assigned=5`. How should this be surfaced without changing thresholds? | Small-pool indicator | When `active_pool > 0` and `active_pool ≤ 5`, `CapacityTable` annotates the badge with `· small pool` and a tooltip explaining the signal is advisory. No threshold change — the tag still drives sort order. |
| With global admins seeing 80+ workplaces, the default Mantine `MultiSelect` chip stack grows multi-row. | Custom chips + modal | `WorkplaceMultiSelect` is rebuilt: a single-row trigger shows the first 3 chips plus `+N more`, clicking opens a modal with a search field, Select all / Clear actions, and per-workplace checkboxes. |

## Confirmed Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Page route is `/admin/dashboard` | Sits inside the existing `frontend/src/pages/admin/` tree | Codebase pattern |
| Page access gated by **permission** `shift.list`, not by role | Frontend routing/sidebar is permission-based throughout the app; role-based gating would be inconsistent | Round 3 |
| All read endpoints live under `/api/admin/dashboard/*` | Matches the existing `Route::group(['prefix' => 'admin'])` structure | `backend/routes/api.php` |
| Endpoints return **only aggregate counts and rates**; no user-identifying fields. Workplace identifiers (`workplace_id`, `workplace_name`) are allowed. | Spec scope: "Fast aggregate API, no candidate/user-level details unless clearly permitted" | User input + Round 3 |
| Demand/capacity widgets use **Demand_Workplaces** (`shift.list`); check-in-derived widgets use **Reliability_Workplaces** (`shift.list ∩ work_history.list`) | A caller with `shift.list` but not `work_history.list` should not be able to infer check-in patterns at a workplace | Round 3 |
| Workplace ID resolution uses the same logic as `applyWorkplaceFilter`, then constrains both Eloquent and raw aggregate queries by the resolved ID list | Original "apply the scope before every query" wording was too strict — many aggregates will be raw SQL | Round 3 |
| Historical window: 30 / 60 / 90 day dropdown, default 90 | Balances flexibility vs. query surface area | Round 2 |
| Capacity table always shows the next 14 days (with a 7-day "today + 7" sub-header) | Mockup shows 7 rows; user asked for "next 7/14 day" — settle on 14 rows so both ranges are visible at a glance | Round 1 + mockup |
| Capacity free-bench uses the default 90-day Active_Pool window | The capacity endpoint intentionally does not refetch when the historical-window dropdown changes, so its Active_Pool input must be fixed | Spec review fix |
| KPI: active worker pool = distinct `user_id`s with ≥1 `check_ins` row in the selected historical window, scoped to Reliability_Workplaces | Mirrors mockup definition; cheap distinct count | Mockup + Rounds 2/3 |
| Approved_Assignment denominator includes all approved rows; `shift_users.is_absent` is ignored by dashboard metrics | That flag is for worktime calculation validation, not workforce reliability | Round 4 |
| Show_Up_Rate = `shows ÷ Approved_Assignment` over window, scoped to Reliability_Workplaces | Round 3 denominator fix | Round 3 |
| KPI: average daily check-ins = `COUNT(check_ins) ÷ window_days` over the Historical_Window, scoped to Reliability_Workplaces, with a delta vs. the prior equal-length window | The earlier Capacity Pressure KPI duplicated the dedicated Capacity widget; an operational throughput number is a better fourth headline | Round 5 |
| Capacity tags use utilization ratios: critical ≥ 90%, tight ≥ 75%, healthy otherwise; assigned-with-zero-pool is critical | Fixed 10/30 free-bench thresholds were out of scale globally and noisy for small sites | Round 4 |
| KPI: no-show risk = approved assignments with no check-in and `shift_reports.is_absent = true` ÷ Approved_Assignment over last 30d, with prior-30d delta | Independent of dropdown window so the KPI is comparable across loads; `shift_users.is_absent` is ignored | Round 4 |
| Heatmap default rows = grouped time-of-day buckets; Top Slots view = top exact `workplace_shifts` slot labels capped by backend default limit 12 | Production data had 149 exact slot labels in 90d, too many for a flat heatmap | Round 4 |
| Show-up rate widget: aggregate bar chart per workplace + drill-down per (workplace × slot) | Drill-down opens in a side panel or expands inline; still no user data | Round 2 |
| Forecast entry: single card with title, one-sentence description, and a primary button linking to `/admin/forecast` (route does not need to exist yet — link renders but may 404) | Defers forecast UI cleanly | Round 1 |
| No top-no-show-users table in V1 | Out of scope per Round 2 user-level constraint | Round 2 |
| Frontend uses Mantine UI + existing translation flow (`t()`); page text added through `pnpm trans:prepare` workflow | Project conventions | `CLAUDE.md` |

## Open Questions

| Question | Blocking? | Notes |
|----------|-----------|-------|
| Tooltip / explanation copy for each KPI | No | Use the mockup sub-text verbatim where applicable; translations handled in the standard flow. |

## Assumptions

| Assumption | Validation Needed | Impact |
|------------|-------------------|--------|
| Existing indexes are not enough for the global reliability query shape | Profile each aggregate endpoint with EXPLAIN on staging data before merge | Prefer a date-first `shifts` index and a covering `shift_users` index if EXPLAIN confirms the same plan; caching remains out of scope for V1 |
| `ShiftUserStatus` has a single "approved" terminal value that defines the Approved_Assignment set | Confirm against `App\Enums\ShiftUserStatus` during implementation | If multiple approved-like statuses exist, expand the predicate and update [[requirements]] accordingly |
| `shift_reports.is_absent` is the source of no-show truth | Confirm join cardinality during implementation | If multiple reports can exist per `shift_user_id`, aggregate or `EXISTS` rather than direct joining to avoid duplicate denominators |
| Permission lookup helpers (`User::getWorkplaceIdsByPermission`) accept both `shift.list` and `work_history.list` and return list-or-`null` semantics consistently | Quick code-read during implementation | If not, add a small helper that intersects two such results, treating `null` as "all" |
| `/admin/forecast` route may not exist yet; a placeholder is acceptable | None | Forecast spec ships separately |
| Day-of-week labels follow the project's existing locale-aware formatting (Mantine `dayjs` + i18n) | None | UI only |
