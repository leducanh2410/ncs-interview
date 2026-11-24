# Implementation Plan: Employee Groups (Foundation)

## Overview

Build the foundation in dependency order: schema → materializer strategies → observers → backend API → frontend pages → tests. Materializer comes before observers so observers have something concrete to call; backend API comes before frontend so the UI can be built against a working contract. Every system-group write path goes through the materializer; controllers never touch `user_groups` directly.

### Implementation Strategy
- **Foundation-First** for the schema, enums, models, and tier config — everything else depends on them.
- **Strategy-per-kind** ordering inside the materializer phase: ship the simpler strategies (`workplace_current`, `prefecture`) before history/seniority/worktime so each is independently testable.
- **API contract before UI**: complete Phase 4 (backend endpoints) before Phase 5 (frontend pages) so the frontend wires to real responses.
- Observers register only after their target strategies pass unit tests.
- One-time backfill ships in Phase 2 so it can be run against fixture data when verifying observers and the API.

## Implementation Plan

### Phase 1: Foundation and Setup

- [x] 1. Schema, enums, permissions, models
    - [x] 1.1 Create migration `create_groups_table` with structured columns AND a NOT NULL `identity_key VARCHAR(191) UNIQUE`
        - Columns per design.md Data Models section
        - FK on `source_workplace_id` cascades on delete
        - Do NOT rely on a unique on `(kind, source_workplace_id, source_prefecture, source_tier)` alone — MySQL/MariaDB treats NULLs as distinct, so kinds that leave source columns NULL (worktime, shift_seniority, manual, rule_based) would not be uniqueness-protected. `identity_key` is the canonical uniqueness column; computed by the Action layer on insert as `kind|source_workplace_id|source_prefecture|source_tier` (NULLs → `null`), with `|<name>` appended for manual / rule_based.
        - Add a non-unique index on `(kind, source_workplace_id)` to keep the workplace-scoped lookup fast.
        - _Requirements: 3.10, 4.1, 5.1_
    - [x] 1.2 Create migration `create_user_groups_table` with `(group_id, user_id)` unique and reverse index
        - _Requirements: 3.1, 4.2, 5.4_
    - [x] 1.3 Add `GroupKind` enum and `SourceTier` enum (`backend/app/Enums/`)
        - Use `EnumTrait` per project convention
        - _Requirements: 3.10_
    - [x] 1.4 Add `Group` and `UserGroup` Eloquent models with relationships
        - `Group::workplace()`, `Group::users()`, `Group::userGroups()`
        - `UserGroup` extends `Pivot` with `incrementing=true`
        - _Requirements: 1.1, 2.1, 3.1_
    - [x] 1.5 Seed `GROUP_VIEW` and `GROUP_EDIT` permissions and grant to admin role
        - Add cases to `App\Enums\PermissionName`
        - Extend the existing `RolePermissionSeeder` (already wired through `DatabaseSeeder`); make the additions idempotent (use `firstOrCreate` / `syncWithoutDetaching`) so re-running the seeder is safe
        - Note: Laravel migrations do NOT run seeders. Document the deploy step as: run migrations → run `php artisan db:seed --class=RolePermissionSeeder` → run `php artisan groups:backfill`
        - _Requirements: 8.1, 8.4_
    - [x] 1.6 Add `GroupTierConfig` value object holding tier boundaries and worktime window thresholds
        - Defaults: shift_seniority 0–10/11–50/51+; workplace_seniority 0–4/5–29/30+; worktime morning <12:00, afternoon 12:00–18:00, night ≥18:00
        - _Requirements: 3.6_
    - [x] 1.7 Add `setPrefectureAttribute` mutator on `App\Models\UserInfo` (trim + lowercase) and a one-shot data migration that normalizes existing `user_infos.prefecture` values
        - Today the field is free-text (`UpdateRequest::rules()` accepts `nullable|string|max:255`) and the model only normalizes `address`. Without a normalization step, identical prefectures with different casing/whitespace will create duplicate prefecture groups.
        - Mapping free-text variants to a controlled vocabulary (e.g., 東京都 / Tokyo / TOKYO → one canonical value) is out of scope for v1; document the limitation in the migration's down-log and roadmap.
        - _Requirements: Constraints and Assumptions §prefecture normalization, 3.5_

- [x] 2. Checkpoint — Schema review
    - Run migration on a dev DB; verify unique index and FK cascade
    - Confirm permission seeding does not break existing roles

### Phase 2: Materializer (Single Writer to user_groups)

- [x] 3. Per-kind membership strategies
    - [x] 3.1 `WorkplaceCurrentStrategy` — read from `workplace_users`; one membership per current pivot row
        - _Requirements: 3.4_
    - [x] 3.2 `WorkplaceHistoryStrategy` — present iff the user currently has at least one completed shift at the workplace, RECOMPUTED ON EVERY OBSERVER TICK (not "never remove")
        - "Completed" = a `CheckIn` row representing check-out exists for the shift AND `shift_users.is_absent=false` (per req 3.6)
        - Recompute semantics MUST match the rest of the materializer: when the affected `(user, kind)` bucket is recomputed (e.g., a shift later marked absent or a check-in deleted), membership is removed if no qualifying completed shifts remain. Earlier draft language ("never removes") is rejected — it conflicts with the "fully recompute the bucket" doctrine and would leave stale memberships if a shift is corrected after the fact.
        - _Requirements: 3.6_
    - [x] 3.3 `PrefectureStrategy` — read `user_infos.prefecture`; lazily create the prefecture group row when missing
        - _Requirements: 3.5_
    - [x] 3.4 `WorktimeStrategy` — bucket by all-time majority of completed shift start times into one of `morning` / `afternoon` / `night`; tie-break by latest shift's bucket (chronologically most recent completed shift wins)
        - Schema reminder: shift start time lives on `workplace_shifts.start_time`, NOT on `shift_users`. The strategy MUST traverse `shift_user → shift → workplaceShift.start_time` (see `App\Models\Shift::workplaceShift` and the existing reference at `App\Models\Shift.php:76-77`). Do NOT add a denormalized column to `shift_users`.
        - "Completed" in v1 means: a `CheckIn` row representing the shift's check-out exists AND `shift_users.is_absent = false` (per req 3.6).
        - _Requirements: 3.6_
    - [x] 3.5 `ShiftSeniorityStrategy` — global tier from total completed shift count
        - Boundaries from `GroupTierConfig`
        - _Requirements: 3.6_
    - [x] 3.6 `WorkplaceSeniorityStrategy` — per-workplace tier from per-workplace completed shift count
        - _Requirements: 3.6_
    - [x] 3.7 `RuleBasedStrategy` — evaluate AND-of-equality predicate against `user_infos`; NULL field excludes the user
        - Whitelist enforcement is in the FormRequest, but strategy double-checks
        - _Requirements: 5.4, 5.7_
    - [x] 3.8 Shared helper: short-circuit insert paths when `user.is_disabled=true`
        - On lock the strategies STOP creating new `user_groups` rows for the user, but they MUST NOT delete existing rows — manual and rule-based memberships are preserved through a lock cycle.
        - Locked-user filtering at READ time is the responsibility of the API layer (Group_Detail member table, /admin/user `groups[]` filter, user-detail Groups section), not the strategies.
        - _Requirements: 3.7_

- [x] 4. Materializer Actions
    - [x] 4.1 `RecomputeUserMembershipsAction::__invoke(User $user, ?array $kinds, ?int $workplaceId)` — replaces user's `user_groups` rows for the given kinds (and optional workplace scope) using strategies
        - Wraps work in `DB::transaction` only if no outer transaction is active
        - _Requirements: 3.4, 3.5, 3.6, 3.7, 3.8_
    - [x] 4.2 `RecomputeGroupMembershipAction::__invoke(Group $group)` — replaces all `user_groups` for one group; used after rule predicate updates and on demand
        - _Requirements: 5.4, 5.5_
    - [x] 4.3 `EnsureSystemGroupsForWorkplaceAction::__invoke(Workplace $workplace)` — creates the 5 system group rows for a workplace if missing (idempotent)
        - _Requirements: 3.2_
    - [x] 4.4 All new Actions MUST use `__invoke` (the project's existing convention — see `backend/app/Actions/User/Auth/RequestPasswordResetUserAction.php` etc.). Do NOT introduce `execute()` methods even if older spec drafts mentioned them.
        - Wrap-only-when-no-outer-transaction logic from 4.1 is unnecessary in practice: Laravel's `DB::transaction(...)` automatically uses savepoints for nested calls (`MySqlConnection` `useSavepoints` is on by default). Just call `DB::transaction(...)` and let Laravel handle nesting; remove the bespoke "is there an outer transaction?" check from the implementation.

- [x] 5. Backfill artisan command
    - [x] 5.1 `BackfillGroupsAction` — orchestrates: ensure system group rows exist for every workplace + distinct (post-normalization) prefecture + worktime/shift_seniority tiers; then walk users and call `RecomputeUserMembershipsAction(all kinds)` on each
        - Idempotent; deletes orphan `user_groups` rows
        - Sets `identity_key` on every group row inserted
        - _Requirements: 3.1, NFR Reliability_
    - [x] 5.2 `php artisan groups:backfill` console command wrapping the action with progress output
        - This is the ONLY backfill entry point — no AppServiceProvider boot hook, no migration-time auto-run. Document it as a deploy step in the project README / deployment runbook.
        - The application MUST function with empty group tables (catalog renders empty, user-list filter shows no options) so a missed deploy step is not a hard failure.
        - _Requirements: 3.1_

- [x] 6. Checkpoint — Materializer verification
    - Run `groups:backfill` against a seeded dataset; spot-check counts per kind
    - Re-run command; verify zero new rows (idempotency)

### Phase 3: Observers (Event Wiring)

- [x] 7. Observers and registration
    - [x] 7.1 `WorkplaceObserver` — `created` calls `EnsureSystemGroupsForWorkplaceAction`; `deleted` cascade is handled by FK
        - _Requirements: 3.2, 3.3_
    - [x] 7.2 `WorkplaceUserObserver` — `created` and `deleted` recompute the affected user's `workplace_current`, `workplace_history`, `workplace_seniority` for that workplace
        - Verify in tests that `WorkplaceService::attachUsers()` / `detachUser()` (which call `$workplace->users()->attach(...)` / `detach(...)`) actually fire the pivot model's `created` / `deleted` events. This depends on the existing `->using(WorkplaceUser::class)` declarations on `User::workplaces()` and `Workplace::users()`.
        - Add a regression test that fails if a future change drops `using()` or replaces the relationship write with a raw `DB::table('workplace_users')` insert/delete.
        - _Requirements: 3.4_
    - [x] 7.3 `UserInfoObserver` — `saved` triggers when `prefecture` is dirty (recompute prefecture kind); when any whitelisted predicate field is dirty, recompute every Rule_Based_Group whose predicate references those fields
        - Use `isDirty([...])` guards
        - _Requirements: 3.5, 5.5_
    - [x] 7.4 `CheckInObserver` — on `created` / `updated` representing a check-out being recorded, recompute `worktime`, `shift_seniority`, `workplace_history`, `workplace_seniority` for the user (scoped to the relevant workplace for the per-workplace kinds)
        - The codebase has no `completed` shift status (`ShiftUserStatus` only has Pending/Approved/Rejected), and there is no scheduled job that retro-completes shifts — check-out is the canonical "shift was actually worked" signal.
        - Guard against double-firing: only recompute when the check-out attribute(s) become populated AND `shift_users.is_absent = false`.
        - DO NOT register a `ShiftUserObserver` for membership purposes; approval/rejection alone do not change membership in v1.
        - _Requirements: 3.6_
    - [x] 7.5 `UserObserver` — when `is_disabled` flips true → DO NOT delete `user_groups` rows (manual + rule-based memberships must survive a lock); when `is_disabled` flips false → recompute every system kind for the user
        - Filtering locked users out of read responses is handled at the query layer (task 9.2 / 9.5 / 9.6), not here.
        - _Requirements: 3.7, 3.8_
    - [x] 7.6 Register all observers in `AppServiceProvider::boot()`
        - _Requirements: 3.4–3.8_

- [x] 8. Checkpoint — Observer integration smoke
    - Manually attach a workplace user via tinker; verify `user_groups` rows appear
    - Lock a user; verify existing `user_groups` rows for that user are preserved and the user is filtered out by read queries

### Phase 4: Backend API

- [x] 9. FormRequests, service, controller, routes
    - [x] 9.1 FormRequests under `App\Http\Requests\Admin\Group\`
        - `IndexRequest` (kind[], search, pagination)
        - `StoreManualRequest`, `StoreRuleBasedRequest` (with whitelist + non-empty predicate validation)
        - `UpdateRequest` (name, optional predicate)
        - `AttachMemberRequest` (rejects locked user)
        - _Requirements: 4.1, 4.2, 5.1, 5.2, 5.3_
    - [x] 9.2 `App\Services\Admin\GroupService` — list, show (with members pagination), store, update, destroy, attach/detach member
        - Throws `SystemGroupReadonlyException` on system-group writes
        - Delegates to materializer actions for all `user_groups` mutations
        - Member-listing queries MUST `JOIN users` and `WHERE users.is_disabled = false` so locked users never appear in the API response, even though their `user_groups` rows are retained. The `member_count` returned in list/show responses MUST count only non-locked users.
        - The members payload returns `current_workplaces` as an array (or eager-loaded relation) — NOT a single primary workplace — to match the user model's many-to-many relationship and the existing /admin/user list rendering.
        - Search behavior in the list endpoint matches admin name + joined workplace name + `source_prefecture`; no localized tier-string match in v1.
        - _Requirements: 1.1, 1.2, 2.1, 4.1–4.6, 5.1–5.7_
    - [x] 9.3 `App\Http\Controllers\Admin\GroupController` — six endpoints per design API section
        - Wraps writes in `DB::transaction`
        - Returns existing `ResponseHelper` envelopes
        - _Requirements: 1.1, 2.1, 4.1–4.6, 5.1–5.7_
    - [x] 9.4 Register routes in `routes/api.php` under the existing admin group with `permission:GROUP_VIEW` / `GROUP_EDIT` middleware
        - _Requirements: 8.2, 8.3_
    - [x] 9.5 Extend `AdminUserController` index FormRequest + query: accept `groups[]` and apply `whereExists` per group id
        - AND semantics across selected groups
        - Existing user-list query already excludes locked users where appropriate; if not, ensure the group filter does not surface a locked user via their retained `user_groups` row
        - _Requirements: 6.1, 6.2, 6.4_
    - [x] 9.6 Source label hydration in API responses: include `source_workplace { id, name }` and resolved tier strings; never compute display name server-side
        - _Requirements: 1.1, 2.1_

- [x] 10. Checkpoint — API contract
    - Run the routes through Postman/curl; confirm response shapes match `design.md` API section
    - Verify 422 responses for system-group writes and locked-user attaches

### Phase 5: Frontend

- [x] 11. Types, API client, display name utility
    - [x] 11.1 `frontend/src/types/group.ts` — `Group`, `GroupKind`, `SourceTier`, `PredicateClause`
        - _Requirements: 1.1_
    - [x] 11.2 `frontend/src/pages/admin/group/api.ts` — list, show, create, update, destroy, attach/detach member
        - _Requirements: 1.1, 2.1, 4.1, 5.1_
    - [x] 11.3 `frontend/src/utils/group_display_name.ts` — pure function `(group) => string` covering all kinds
        - Uses fixed i18n keys per design Display_Name table
        - Register dynamic keys in `frontend/src/locales/for_scanner.ts` if any
        - _Requirements: 1.1, 2.1_

- [x] 12. /admin/group pages
    - [x] 12.1 `index.page.tsx` — Mantine table; kind multi-select filter; search input bound to query string; "Create Group" button gated by `GROUP_EDIT`
        - _Requirements: 1.1, 1.2, 1.3, 1.5_
    - [x] 12.2 `[id].page.tsx` — fetches the group; branches into one of three partials based on kind
        - _Requirements: 2.1, 2.2_
    - [x] 12.3 `partials/group_system_view.tsx` — header (Display_Name + source label + count), read-only members table
        - _Requirements: 2.2_
    - [x] 12.4 `partials/group_manual_view.tsx` — header, members table with Remove action, AddUserModal trigger
        - _Requirements: 2.3, 4.2, 4.3_
    - [x] 12.5 `partials/group_rule_view.tsx` — header, predicate editor (list of `Select(field) → Select(value)` rows), Save triggers `PATCH` then refetches members
        - _Requirements: 2.4, 5.1, 5.2_
    - [x] 12.6 `create.page.tsx` — kind selector (manual / rule_based) + matching form; on submit POSTs and navigates to detail
        - _Requirements: 4.1, 5.1_

- [x] 13. User list and user detail integration
    - [x] 13.1 `/admin/user/index.page.tsx` — add Groups multi-select bound to `?groups[]=`; load options from group list API
        - Hide when user lacks `GROUP_VIEW`
        - _Requirements: 6.1, 6.2, 6.5_
    - [x] 13.2 `/admin/user/[id].page.tsx` — add `partials/user_groups.tsx` listing memberships grouped by kind (system → manual → rule_based)
        - Each entry links to `/admin/group/[id]`
        - Empty state for locked users
        - _Requirements: 7.1, 7.2, 7.3, 7.4_

- [x] 14. Translations
    - [x] 14.1 Convert all new user-facing strings to `t(...)` and run `pnpm trans:prepare`
    - [x] 14.2 Fill `vi` and `ja` translations in `.translation-workflow/translation-batch.json`, preserving placeholders
    - [x] 14.3 Run `pnpm trans:apply`

- [ ] 15. Checkpoint — UI smoke
    - Walk every page in dev: index → create manual → add members → create rule_based → edit predicate → user-list filter → user-detail Groups section
    - Confirm Display_Name renders for every system kind in all three locales

### Phase 6: Testing

- [x] 16. Backend unit tests
    - [x] 16.1 `RecomputeUserMembershipsActionTest` — one test per strategy covering boundaries and locked-user short-circuit
        - _Requirements: 3.4–3.8, 5.4_
    - [x] 16.2 `RecomputeGroupMembershipActionTest` — predicate evaluation, NULL field exclusion
        - _Requirements: 5.4, 5.7_
    - [x] 16.3 `GroupServiceTest` — system-group write rejection, duplicate name rejection, predicate whitelist enforcement
        - _Requirements: 4.6, 5.2_

- [x] 17. Backend feature tests
    - [x] 17.1 `GroupControllerTest` — index filtering, show with members, manual CRUD, rule_based CRUD, member attach/detach, system-group write rejection, permission enforcement
        - _Requirements: 1.1–1.6, 2.1–2.7, 4.1–4.6, 5.1–5.7, 8.2, 8.3_
    - [x] 17.2 `UserListGroupFilterTest` — AND semantics across multiple group ids; locked users absent from results
        - _Requirements: 6.2, 6.4_
    - [x] 17.3 `GroupObserversTest` — observer wiring for each model event listed in Requirement 3
        - Cover: workplace create/delete, workplace_user attach/detach (through the relationship, NOT raw DB), user_info prefecture/japanese_level/gender/nationality dirty path, check-in create/update with is_absent=false, user lock (verify rows are PRESERVED), user unlock (verify system kinds recompute, manual/rule rows untouched).
        - Negative test: a manual group with the user as a member, lock the user, confirm the `user_groups` row still exists; unlock the user, confirm the row is still there and the API now surfaces the user again.
        - _Requirements: 3.2–3.8_
    - [x] 17.4 `GroupsBackfillCommandTest` — idempotency, locked users excluded, orphan rows removed
        - _Requirements: 3.1, NFR Reliability_

- [x] 18. Frontend tests
    - [x] 18.1 `group_display_name.test.ts` — exhaustive (kind × source) coverage producing the expected localized string
        - _Requirements: 1.1, 2.1_
    - [x] 18.2 `group_index.test.tsx` — kind filter chips, search, navigation
        - _Requirements: 1.1–1.5_
    - [x] 18.3 `group_manual_view.test.tsx` and `group_rule_view.test.tsx` — add/remove and predicate save flows
        - _Requirements: 2.3, 2.4, 5.4_

### Phase 7: Final Validation

- [ ] 19. Code Quality
    - [x] 19.1 Backend: `docker compose exec php npm run prettier` and `docker compose exec php npm run pint`
    - [x] 19.2 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`
    - [x] 19.3 Backend tests: `docker compose exec php ./vendor/bin/phpunit`
    - [ ] 19.4 Manual end-to-end walkthrough as in Checkpoint 15, on a freshly migrated + backfilled DB

## Notes

- **Ordering rationale**: schema → materializer → observers → API → UI → tests. Observers are deliberately built after strategies so each strategy is unit-testable in isolation.
- **Risk: cascading observer storms.** A bulk `WorkplaceUser` import could fire one observer per row. v1 accepts this — the existing data import paths are admin-rate operations. If profiling shows trouble, queue the materializer per `(user_id, kind)` outside the transaction in v2.
- **Risk: prefecture group leaks.** When the last user with a given prefecture is moved away, the prefecture group row remains (with zero members). Acceptable in v1; can be GC'd by the backfill command in a later pass.
- **Risk: rule-based recomputation cost.** Each `UserInfo` save scans every rule-based group whose predicate uses the changed field. With dozens of rule_based groups this is fine; if it grows, denormalize an `(rule_based_group_id, field)` index in v2.
- **Tier defaults are conventions, not invariants.** All tier boundaries live in `GroupTierConfig` so they can be tuned without touching strategies.
- **Pivot events depend on `using()`.** `WorkplaceUserObserver::created` / `deleted` only fire because `User::workplaces()` and `Workplace::users()` are declared `belongsToMany(...)->using(WorkplaceUser::class)`. Laravel's `BelongsToMany::attachUsingCustomClass` / `detachUsingCustomClass` save / delete the pivot model, which dispatches model events. Bulk `attach($ids)` calls this path per id, so events fire per row. Any future code path that writes to `workplace_users` via `DB::table(...)` or drops `using()` will silently break the materializer.
- **Bulk manual member add.** v1 endpoint `POST /api/admin/groups/{id}/members/{user_id}` is single-user only. Bulk-add (`POST /api/admin/groups/{id}/members` with `{ user_ids: [...] }`) is not in v1 scope; admins curate large pools by composing multiple add calls on the client. Track as a UX follow-up.
- **Manual group default sort.** Group_Catalog default sort: `kind ASC, source_workplace_id ASC NULLS LAST, source_tier ASC NULLS LAST, name ASC`. This keeps system groups grouped by workplace then tier, with manual / rule_based groups appearing at the end alphabetically.
- **User detail empty state.** A non-locked user with zero memberships SHALL render the standard empty-state copy (translation key `groups.empty_state.no_memberships`); the locked-user empty state from req 7.3 is a separate copy. Both are required.
- **Rule predicate value source.** Whitelisted fields split into enum-backed (`japanese_level` → `App\Enums\JapaneseLevel`, `gender` → `App\Enums\Gender`, `nationality` → `App\Enums\Nationality`) and free-text (`prefecture`). For enum-backed fields the predicate UI MUST be a `Select` populated from the enum; the FormRequest MUST validate `value` against `Rule::in(<Enum>::values())`. For `prefecture` the UI is a `Select` populated from the distinct list of `groups.source_prefecture` values currently in the database (after normalization), with the help text noting that no manual free-text entry is allowed in v1 — admins can only target prefectures that already have at least one user. Stale predicates whose value no longer exists in any user_info row are NOT auto-cleaned in v1; they will simply produce an empty group.
- **Concurrency.** Manual member add and rule-predicate recompute do not coordinate beyond the per-request transaction. v1 accepts last-write-wins semantics for concurrent admin edits; document this as out of scope.
- **Backfill production safety.** The `groups:backfill` command MUST accept a `--chunk` option (default 500 users per chunk). It SHOULD be safe to run against a live database with observers active: the `identity_key` unique index prevents duplicate group rows, and recompute-from-source semantics make double-writes idempotent. The README MUST nonetheless recommend running it during a low-traffic window the first time.
- **GROUP_EDIT does not unlock system kinds.** The "Create Group" form's kind selector MUST only offer `manual` and `rule_based`. Backend `StoreManualRequest` / `StoreRuleBasedRequest` are the only Store request classes; there is no Store request that accepts a system kind, so even a crafted POST is rejected at FormRequest level.
- **Audit trail.** v1 does NOT add audit-log entries for group create/delete or manual-membership changes. If the codebase has an existing audit convention discovered during implementation, plug into it; otherwise track as a follow-up.
