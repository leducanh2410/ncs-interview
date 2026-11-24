---
name: Employee Groups Decisions Log
description: Running log of clarification Q&A and confirmed decisions for the employee groups foundation feature
type: project
---

# Decisions Log: Employee Groups (Foundation)

## Overview

A flexible employee grouping mechanism. v1 (this spec) introduces a single `Group` concept with three kinds of membership: **system-defined** (auto-evaluated from criteria such as current workplace, work history, prefecture, worktime preference, and shift seniority), **manual admin-defined** (curated list of users), and **rule-based admin-defined** (simple AND of field=value predicates). Memberships are materialized in a `user_groups` join table that is updated by event listeners (no batch ETL). Surfaces in v1: dedicated `/admin/group` CRUD page, filter chip on `/admin/user`, and a member-list view on the group detail page. Shift-planning integration ("estimate available employees per shift") is split into a follow-up spec — see `roadmap.md`.

**Size classification:** **Medium** — one new core entity, ~7 system criteria types, one new admin page (CRUD + detail), event-listener wiring, and a filter integration in an existing page. Single bounded context (admin/user-management). Estimated 6–8 requirements.

## Questions and Answers

### Round 1: Concept, surfaces, primary goal, evaluation model

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Tag vs. group — one concept or two? | One concept called `Group` | Single `groups` table with a `kind` discriminator |
| Where is the feature surfaced? | `/admin/group` page + filter in `/admin/user` + (later) shift planning | v1 ships first two surfaces; shift-planning deferred to v2 |
| Primary goal? | Estimate available headcount per shift while planning | Drives the v2 spec; v1 builds the foundation |
| How are system groups evaluated? | Read from `user_groups` join table; write at event time (workplace update, address update, check-in, etc.) | Event-driven materialization, no live computation, no scheduled refresh |

### Round 2: v1 criteria, admin group style, planning view scope

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Which system criteria in v1? | Current workplace, worked-at workplace, prefecture, worktime preference, workplace seniority, shift seniority tiers | Six system kinds in v1; ward dropped (see Round 3) |
| Admin groups creation style? | Rule-based + manual (manual confirmed in Round 3) | Both admin kinds shipped in v1 |
| Definition of "available" for shift planning? | Out of scope for v1 | Removed from v1 requirements; deferred |

### Round 3: Scope split, criterion semantics, ward source

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Split shift-planning into a v2 spec? | Yes — v1 foundation only | `roadmap.md` created; v2 spec to follow |
| How to derive worktime preference? | Bucket by all-time majority of completed shift start times | Single membership per user (the dominant bucket); thresholds: morning <12:00, afternoon 12:00–18:00, night ≥18:00 |
| What is "workplace seniority"? | Per-workplace shift count tiers | Generates W × 3 groups (W = workplace count) |
| Ward source? | Skip ward in v1 | Dropped from v1 system kinds |

### Round 4: Rule complexity, system editability, rule fields, locked users

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Rule expression complexity for admin rule-based groups? | Simple AND of field=value | Stored as JSON array of `{field, value}`; UI is a list of field+value pickers |
| Can system groups be edited (renamed, hidden, deleted)? | No edits — names auto-generated, always visible | System group rows are read-only in admin UI; deletion handled automatically when source entity (workplace) is deleted |
| Which fields are exposed as rule operands? | Prefecture, japanese_level, gender, nationality | Hard-coded whitelist in v1 |
| Should locked (`is_disabled=true`) users be in groups? | Exclude locked users from all groups | Materializer skips locked users; toggling lock updates membership via event listener |

### Round 5: Naming and identification of system groups

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| `shift_seniority` — global or per-workplace? | Global. Total completed shifts across all workplaces, bucketed into 3 tiers (newcomer / regular / veteran) | Exactly 3 `shift_seniority` group rows system-wide; one membership per user |
| How do system groups identify their source and tier in the DB — pack into `name` (e.g., `workplace_seniority::Musashino::veteran`) or use structured columns? | Structured columns (`kind`, `source_workplace_id`, `source_prefecture`, `source_tier`) | Cleaner queries ("all groups for workplace X"), no string parsing, i18n-friendly. `name` column is only used for `manual` / `rule_based` groups |
| How is the human-readable display name produced? | Composed on the frontend via i18n keys + interpolation (e.g., `t('group.workplace_seniority.veteran', { workplace: 'Musashino' })`) | Backend returns the structured fields plus the resolved source label (workplace name / prefecture string); frontend renders the localized string |

## Confirmed Decisions

| Decision | Rationale | Source |
|----------|-----------|--------|
| Single `Group` entity with `kind` discriminator | Simpler than separate tag/group concepts | Round 1 |
| Materialize membership in `user_groups` (group_id, user_id) join table | Read-optimized; consistent with existing `workplace_users` pattern | Round 1 |
| Update membership via Laravel event listeners on relevant model events | No batch ETL; consistent freshness; matches existing architecture | Round 1 |
| Six system group kinds in v1: workplace_current, workplace_history, prefecture, worktime, shift_seniority, workplace_seniority | Covers location + history + preference signals identified by the user | Round 2/3 |
| Two admin group kinds in v1: manual and rule-based | Cover both curated lists and predicate-driven cohorts | Round 2/4 |
| Worktime preference uses all-time majority over completed shifts; one bucket per user | User preference; simpler than multi-membership | Round 3 |
| Workplace seniority is per-workplace shift count tiers — exact boundaries: newcomer 0–4 / regular 5–29 / veteran 30+ | User confirmed semantics; boundaries are spec assumption (revisable). The earlier shorthand "new <5, regular 5–30, veteran 30+" was ambiguous at 30 — the canonical form is the half-open intervals 0–4, 5–29, 30+ used in requirements.md / tasks.md / GroupTierConfig | Round 3 |
| Ward groups are out of scope for v1 | `user_infos` lacks a ward field; skip until structured field exists | Round 3 |
| Rule-based groups support only AND of field=value predicates over a fixed whitelist (prefecture, japanese_level, gender, nationality) | Covers majority of cohort needs without query-builder complexity | Round 4 |
| System group names auto-generated, not editable; system group rows are not deletable from admin UI | Avoids drift between source data and group display | Round 4 |
| Locked users are excluded from all groups | Cleaner counts; matches admin's mental model when planning | Round 4 |
| Shift-planning headcount estimation deferred to a follow-up spec | Reduces v1 scope; foundation is independently shippable | Round 3 (see `roadmap.md`) |
| User detail page shows the user's group memberships | Uses the same `user_groups` table; natural extension of existing user detail | Inferred / Round 1 |
| `shift_seniority` is global (3 groups total), `workplace_seniority` is per-workplace (3 × W groups) | Matches user's mental model; keeps shift_seniority cheap to render | Round 5 |
| `groups` schema uses structured columns (`kind`, `source_workplace_id`, `source_prefecture`, `source_tier`) plus a unique constraint over the four; `name` is reserved for `manual` / `rule_based` | Avoids encoding semantics in a string; supports cascaded source-entity changes; supports i18n | Round 5 |
| Display name for system groups is rendered on the frontend via i18n keys with interpolation; backend returns structured fields + resolved source label only | Localization-friendly; backend stays free of presentation concerns | Round 5 |

## Open Questions

| Question | Blocking? | Notes |
|----------|-----------|-------|
| Exact tier boundaries for shift_seniority and workplace_seniority groups | No | Default proposal in spec: shift_seniority = 0–10 / 11–50 / 51+; workplace_seniority = 0–4 / 5–29 / 30+. Revisable in design review |
| Number of completed shifts that count toward seniority — does cancelled/absent count? | No | Default: only `shift_users.is_absent=false` and a check-out has been recorded (see Round 6) |
| Should rule-based group membership recompute on every user_info edit, or be queued? | No | Default: synchronous via event listener (current `workplace_users` pattern is synchronous) |

### Round 7: Claude review follow-ups (2026-05-10)

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Tier boundary at 30 — is 30 in regular or veteran? | Veteran (30+) | Canonical form across all docs is 0–4 / 5–29 / 30+ (half-open intervals). Earlier shorthand "regular 5–30, veteran 30+" deleted as ambiguous. |
| Worktime tie-break rule (slipped into tasks without a Q&A) | Latest completed shift's bucket wins | Documented in tasks 3.4 alongside the `workplace_shifts.start_time` traversal note; ratifies the previously-unsourced spec language. |
| Action method convention (`__invoke` vs `execute`) | `__invoke`, project-wide | Spec rewritten to use `Action::__invoke(...)` everywhere; all existing actions in `backend/app/Actions/` use `__invoke`. |
| Permission seeding — does migration run the seeder? | No, Laravel migrations don't run seeders | Req 8.1 + task 1.5 rewritten to specify `php artisan db:seed --class=RolePermissionSeeder` as a deploy step alongside `groups:backfill`; seeder must be idempotent. |
| Pivot event firing on `attach()` / `detach()` (Claude flagged as broken) | Verified to work given `->using(WorkplaceUser::class)` is already set on `User::workplaces()` and `Workplace::users()` | Laravel's `BelongsToMany::attachUsingCustomClass` saves the pivot model (firing model events) when `using()` is configured. The previous round's regression test (task 7.2) protects this invariant. No spec change beyond clarification. |
| Shift `start_time` location | On `workplace_shifts`, not `shift_users` | Strategy 3.4 spelled out the traversal `shift_user → shift → workplaceShift.start_time`; do NOT add a denormalized column. |
| `workplace_history` removal semantics | Recompute every tick (consistent with the rest of the materializer) | Earlier draft language "never removes" was rejected as inconsistent. Strategy 3.2 now removes the membership when the last qualifying completed shift disappears. |
| Rule predicate value source for free-text vs enum fields | Enum-backed fields (`japanese_level`, `gender`, `nationality`) use `Rule::in(<Enum>::values())`; `prefecture` uses a `Select` populated from currently-known prefecture values | Documented in tasks Notes; no manual free-text entry in v1. Stale predicate values are not auto-cleaned. |
| Group_Catalog default sort | `kind ASC, source_workplace_id ASC NULLS LAST, source_tier ASC NULLS LAST, name ASC` | Documented in tasks Notes. |
| Empty state for non-locked users with no groups | Distinct copy from the locked-user empty state (req 7.3) | Translation key `groups.empty_state.no_memberships` reserved. |
| Bulk manual member add | Out of scope for v1 — single-user endpoint only | Tracked as UX follow-up. |
| Audit trail | Out of scope for v1 unless an existing audit convention is discovered during implementation | Tracked as follow-up. |
| Concurrency on rule recompute vs manual add | Last-write-wins; out of scope for v1 | Documented in tasks Notes. |
| Backfill production safety | Add `--chunk` option (default 500); identity_key unique + recompute-from-source make double-writes safe | Documented in tasks Notes. |
| Nested `DB::transaction` semantics | Use `DB::transaction(...)` directly; Laravel auto-savepoints | Removed the bespoke "is there an outer transaction?" check from task 4.1. |
| Stale enum values in predicates after a future enum rename | Not auto-cleaned in v1 — predicate produces an empty group, admin must edit it | Tracked as follow-up. |

### Round 6: Codex review follow-ups (2026-05-10)

| Question | Answer | Decision / Impact |
|----------|--------|-------------------|
| Locking a user — preserve manual membership, or destroy it? | Soft-suspend (keep `user_groups` rows; filter at query layer) | Manual + rule-based memberships survive a lock cycle. Read endpoints `JOIN users` and exclude `is_disabled=true`. Materializer's `is_disabled` short-circuit applies on insert only, never delete. Updated requirements 3.7 / 3.8 and design "Locked users" decision. |
| What event triggers seniority/worktime recomputation given there is no `completed` shift status? | Check-out event (CheckIn create/update with `shift_users.is_absent=false`) | New `CheckInObserver` replaces the planned `ShiftUserObserver`; no scheduled job, no `end_time` polling. Updated req 3.6 + tasks 7.4. |
| Permission scope for groups — global or workplace-scoped like other admin permissions? | Global only in v1 | `GROUP_VIEW` / `GROUP_EDIT` ignore `team_id`. Documented as a v1 simplification; workplace-scoped visibility deferred to roadmap. New requirement 8.5. |
| Backfill — auto on first boot or explicit artisan command? | Artisan command only (`php artisan groups:backfill` as a deploy step) | App must function with empty group tables. Updated req 3.1 + tasks 5.2. |
| How to enforce uniqueness given MySQL/MariaDB treats NULLs as distinct in unique indexes? | Add NOT NULL `identity_key VARCHAR(191) UNIQUE` computed by Action layer on insert | Replaces the originally-proposed unique on `(kind, source_workplace_id, source_prefecture, source_tier)`. `identity_key = kind|source_workplace_id|source_prefecture|source_tier` (NULLs → `null`), with `|<name>` appended for manual / rule_based. Migration adds a non-unique `(kind, source_workplace_id)` index for query patterns. |
| Member-table "current workplace" column — singular or plural? | Plural — return `current_workplaces[]` matching the existing /admin/user list | Member API includes the user's full workplace set; frontend joins names with `, `. Updated req 2.1 + design API §"Show group + members". |
| Search semantics in the catalog — match the rendered Display_Name including localized tier strings? | No, v1 limitation: match name + workplace name + prefecture only | Tier strings (`veteran`, `morning`, …) are NOT searchable in v1. UI documents the limitation in the search input's help text; admins narrow tiers via the kind filter. |
| Prefecture normalization — already done by the model? | No, only `address` is normalized today; `prefecture` is free-text | Add `setPrefectureAttribute` mutator (trim + lowercase) on `UserInfo` plus a one-shot data migration. Mapping free-text variants to a controlled vocabulary is deferred. New task 1.7. |
| Pivot observer — does `attach()` / `detach()` actually fire `WorkplaceUser` events? | Yes, because `User::workplaces()` and `Workplace::users()` already declare `->using(WorkplaceUser::class)` | Spec now states this dependency explicitly and adds a regression test (task 7.2) that fails if a future change drops `using()` or replaces the relationship write with a raw DB write. |

## Assumptions

| Assumption | Validation Needed | Impact |
|------------|-------------------|--------|
| Group CRUD UI follows the existing `/admin/workplace` pattern (Mantine table on index, detail page on `/admin/group/[id]`) | Confirm during design review | Frontend file layout |
| Permission required to manage groups maps to a new `GROUP_EDIT` permission; viewing requires `GROUP_VIEW` | Confirm permission seeding plan | Backend Laratrust setup |
| Worktime preference is recomputed for affected users when a shift completes (status transitions to past), not on every shift edit | Verify shift lifecycle events | Listener wiring |
| `user_groups` rows are recomputed in full for the affected `(user, kind)` bucket when an event fires (not incremental diff) | Confirm acceptable for membership sizes | Materializer design |
