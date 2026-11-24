# Roadmap: Employee Groups

## Current Focus
- **20260510-employee-groups** — Foundation: `Group` entity, system + admin (manual + rule-based) groups, materialized membership, admin CRUD page, user-list filter, user detail display.

## Sub-Features

| Order | Name | Status | Description | Depends On | Notes |
|-------|------|--------|-------------|------------|-------|
| 1 | 20260510-employee-groups | In Progress | Group model, materialization via event listeners, admin CRUD, user-list filter, user detail display | — | This spec |
| 2 | employee-groups-shift-planning | Deferred | Headcount estimation per shift while planning: pick a workplace+date, see counts of group members net of conflicts (assigned shifts, absences, fixed schedule) | 1 | Original primary motivation; needs clear "available" semantics |
| 3 | employee-groups-bulk-actions | Deferred | Use a group as the audience for notifications, document distribution, or CSV export | 1 | Nice-to-have |
| 4 | employee-groups-advanced-criteria | Deferred | Distance/proximity to workplace, ward (after structured field exists), additional rule operators (range, OR, IN) | 1 | Lower priority |

## Breakdown Rationale

The original request bundled three independently shippable capabilities: (a) a flexible group model with built-in and admin-managed groups, (b) headcount estimation in the shift-planning workflow, and (c) bulk operations targeted at groups. Each has a different primary user (admin curator vs. shift planner vs. comms operator) and different downstream dependencies.

The foundation slice (this spec) is the smallest end-to-end vertical that unlocks the rest: once groups are queryable from the backend, the shift-planning view and bulk actions become straightforward feature additions on top. Splitting also keeps the "available employee" semantics — which is the most contentious design question — out of v1 so that foundation work can ship without that debate blocking it.
