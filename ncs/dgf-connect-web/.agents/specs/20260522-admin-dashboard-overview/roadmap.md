---
name: roadmap
description: Multi-spec roadmap for the Admin Dashboard / Forecast initiative
---

# Roadmap: Admin Workforce Insights

## Current Focus

- **20260522-admin-dashboard-overview** — Fast read-only admin overview at `/admin/dashboard` (KPIs, capacity table, demand heatmap, reliability view, forecast entry link). Aggregate-only API, strict workplace permissions.

## Sub-Features

| Order | Name | Status | Description | Depends On | Notes |
|-------|------|--------|-------------|------------|-------|
| 1 | 20260522-admin-dashboard-overview | In Progress | Read-only admin overview with KPI cards, capacity table, demand heatmap, show-up rate by workplace/slot, and a link card to the forecast page. | — | This spec. Aggregate API only; no user-level details. |
| 2 | admin-forecast (future) | Deferred | Forecast page at `/admin/forecast`: given a workplace + date + slot + requested count, return historical avg delivered, free pool, slot show-up rate, recommended assignment count, and (permission-gated) candidate list. | 1 | Adds the candidate-level surface the dashboard intentionally omits. |
| 3 | admin-dashboard-drilldowns (future) | Deferred | Permission-gated drill-downs (per-workplace no-show user list, late check-in detail) embedded under existing dashboard widgets. | 1 | Surfaces user-level data only where the caller has `user.general.list` on the relevant workplace. |

## Breakdown Rationale

The original mockup mixes a fast read-only overview, an interactive forecaster, and user-level drill-down tables. Shipping it as one spec would mean three distinct user journeys, mixed permission models (aggregate vs. user-level), and a much larger API surface. Splitting on the **aggregate vs. user-level data** boundary lets Spec 1 ship a hardened "no PII leakage" overview quickly; later specs can add candidate and per-user detail behind the same workplace-permission gates without re-litigating the overview.
