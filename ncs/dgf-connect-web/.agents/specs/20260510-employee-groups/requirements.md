# Requirements Document

## Introduction

DGF Connect needs a flexible way to organize employees into named groups so that admins can browse, filter, and (in a future iteration) plan shifts against meaningful cohorts. This v1 introduces a single `Group` entity with three membership styles: **system-defined** groups auto-derived from existing data (current workplace, work history, prefecture, worktime preference, shift seniority, per-workplace seniority), **manual admin-defined** groups with curated member lists, and **rule-based admin-defined** groups computed from a simple AND of field=value predicates. Memberships are materialized in a `user_groups` join table maintained by event listeners on the relevant model events. The feature surfaces as a new admin CRUD page (`/admin/group`), a group filter on the existing user list, and a "Groups" section on the user detail page. Shift-planning headcount estimation is deferred to a follow-up spec (see `roadmap.md`). No breaking changes to existing APIs.

### Feature Summary

A single `Group` concept with system-derived, manually-curated, and rule-based membership; materialized for fast reads; surfaced via an admin CRUD page, a user-list filter, and a user-detail section.

### Business Value

- Admins gain a reusable cohort abstraction for browsing and filtering employees, replacing ad-hoc filter combinations.
- Groundwork for the shift-planning headcount view (v2): once memberships are queryable, planners can estimate eligible employees per shift in one glance.
- System groups make existing data (workplace assignment, prefecture, shift history) addressable as cohorts without admin maintenance work.
- Rule-based admin groups let operations teams define cohorts (e.g., "N3+ Tokyo female workers") without engineering involvement.

### Scope

**Included:**
- New `groups` table and `user_groups` join table.
- Six system group kinds: `workplace_current`, `workplace_history`, `prefecture`, `worktime`, `shift_seniority`, `workplace_seniority`.
- Two admin group kinds: `manual` and `rule_based`.
- Event listeners that maintain `user_groups` on relevant model changes (workplace user attach/detach, user info update, shift completion, user lock/unlock).
- Backend CRUD endpoints for admin groups; read endpoints for system groups.
- New admin page `/admin/group` with index (list + counts) and detail (`/admin/group/[id]`) showing members and edit form.
- Group filter chip on `/admin/user`.
- "Groups" section on the user detail page listing the user's memberships.
- Permissions: `GROUP_VIEW` and `GROUP_EDIT`.
- One-time backfill artisan command to populate `user_groups` from existing data.

**Excluded:**
- Shift-planning headcount view (v2 spec).
- Bulk actions targeted at groups (notifications, exports, document distribution).
- Ward criterion (no structured field today).
- Distance/proximity criterion.
- Advanced rule operators (`OR`, `IN`, `range`, `!=`).
- Editing or hiding system-defined groups.
- API changes to existing endpoints other than additive filter parameters.

## Glossary

| Term | Definition |
|------|------------|
| Group | A named collection of users with one of three membership kinds (system, manual, rule-based) |
| System_Group | A group whose membership is auto-derived from a fixed criterion; not editable by admins |
| Manual_Group | An admin-defined group whose members are explicitly added/removed by an admin |
| Rule_Based_Group | An admin-defined group whose members are computed from a simple AND-of-equality predicate |
| Group_Kind | The discriminator on `groups.kind`: one of `workplace_current`, `workplace_history`, `prefecture`, `worktime`, `shift_seniority`, `workplace_seniority`, `manual`, `rule_based` |
| Source_Tier | Enum value on `groups.source_tier` discriminating sub-buckets within a kind: `newcomer` / `regular` / `veteran` for `shift_seniority` and `workplace_seniority`; `morning` / `afternoon` / `night` for `worktime`; NULL for other kinds |
| Group_Identity | The composite `(kind, source_workplace_id, source_prefecture, source_tier)` that uniquely identifies a system group; `name` is used only for `manual` / `rule_based`. Persisted as a NOT NULL `identity_key` column (see Requirement 3.10) so MySQL's NULL-tolerant unique index does not allow duplicates |
| Display_Name | The localized human-readable string rendered on the frontend from a Group's identity (e.g., "Veteran employee at Musashino"); not stored in the database for system groups |
| Group_Member | A row in `user_groups` linking a user to a group |
| Group_Materializer | The backend service that recomputes `user_groups` rows for a given `(user_id, group_kind)` bucket in response to model events |
| Locked_User | A user with `users.is_disabled = true`; **soft-suspended** from group queries — `user_groups` rows are retained on lock so manual and rule-based memberships survive an unlock, but locked users are filtered out at the query layer in every list endpoint, member table, and user-list filter |

## Requirements

### Requirement 1: Group Catalog (Admin Index)

**User Story:** As an admin, I want to see all groups in one list with their kind and current member count, so that I can find a group quickly and gauge cohort sizes.

#### Acceptance Criteria

1. WHEN an admin with `GROUP_VIEW` navigates to `/admin/group` THEN the Group_Catalog SHALL display every group with columns: Display_Name (composed on the frontend from kind + source + tier for system groups, or `groups.name` for admin groups), kind label, member count. v1 permission scope is global: any admin holding `GROUP_VIEW` sees every group regardless of which workplaces their other roles are scoped to (workplace-scoped group visibility is deferred to a future spec).
2. WHEN the Group_Catalog renders THEN it SHALL provide a kind filter (multi-select) and a name search input. The search SHALL match against admin-supplied `name` (for manual / rule_based groups) AND against the workplace name (for `workplace_*` kinds) AND against the prefecture string (for `prefecture` kind). v1 search SHALL NOT match localized tier strings (`veteran`, `morning`, etc.) — admins filter those via the kind filter instead. This v1 limitation MUST be documented in the search input's placeholder/help text.
3. WHEN the kind filter is changed or search text is entered THEN the Group_Catalog SHALL refetch and display matching groups.
4. WHEN the admin clicks a row THEN the application SHALL navigate to `/admin/group/[id]`.
5. IF the admin has `GROUP_EDIT` THEN the Group_Catalog SHALL show a "Create Group" button that opens a creation page for `manual` or `rule_based` kinds only.
6. IF the admin lacks `GROUP_VIEW` THEN the application SHALL display the 403 page.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirements 3, 4, 5 (data sources)

### Requirement 2: Group Detail and Membership View

**User Story:** As an admin, I want to open a group and see its members, so that I can verify who belongs and (for admin groups) edit the membership.

#### Acceptance Criteria

1. WHEN an admin navigates to `/admin/group/[id]` THEN the Group_Detail SHALL display the group's Display_Name, kind, source label (workplace name / prefecture / tier — empty for `manual` / `rule_based`), member count, and a paginated table of Group_Members with columns: name, staff number, current workplaces (the user's full set of currently-attached workplaces, joined with `, ` — matching how `/admin/user` already renders the column). The member count SHALL exclude Locked_Users.
2. WHEN the group is a System_Group THEN the Group_Detail SHALL render the name, source label, and member table as read-only.
3. WHEN the group is a Manual_Group AND the admin has `GROUP_EDIT` THEN the Group_Detail SHALL show "Add User" and per-row "Remove" actions for membership management.
4. WHEN the group is a Rule_Based_Group AND the admin has `GROUP_EDIT` THEN the Group_Detail SHALL show the predicate editor and a "Recompute" indicator that refreshes membership when the rule is saved.
5. WHEN the admin removes a Manual_Group member THEN the Group_Materializer SHALL delete the corresponding `user_groups` row and refresh the displayed count.
6. WHEN the admin saves a new rule on a Rule_Based_Group THEN the Group_Materializer SHALL recompute that group's membership synchronously and the displayed member list SHALL reflect the new set.
7. IF the group does not exist OR the admin lacks `GROUP_VIEW` THEN the Group_Detail SHALL display a 404 or 403 page respectively.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 3: System-Defined Group Materialization

**User Story:** As an admin, I want system groups (workplace, prefecture, worktime, seniority) to stay in sync with the underlying data automatically, so that I never need to "rebuild" them manually.

#### Acceptance Criteria

1. AFTER deploying this feature an operator SHALL run `php artisan groups:backfill` exactly once as a documented deploy step. The command SHALL create one Group row per Group_Identity (one `workplace_current` and one `workplace_history` per workplace; three `workplace_seniority` rows per workplace, one per Source_Tier; one `prefecture` row per distinct non-null prefecture; three `worktime` rows; three `shift_seniority` rows) and SHALL populate `user_groups` from existing data. The command MUST be idempotent (re-running produces no duplicate rows) so it can be safely retried. There is no automatic boot-time backfill — the application MUST function correctly even if the command has not yet been run (empty group catalog, empty user-list filter options).
2. WHEN a workplace is created THEN the Group_Materializer SHALL create one `workplace_current` group, one `workplace_history` group, and three `workplace_seniority` groups (`newcomer`, `regular`, `veteran`) all carrying `source_workplace_id = the new workplace id`.
3. WHEN a workplace is deleted THEN the Group_Materializer SHALL delete every group whose `source_workplace_id` references that workplace, cascading to their `user_groups` rows.
4. WHEN a workplace user is attached or detached THEN the Group_Materializer SHALL update the affected user's `workplace_current`, `workplace_history`, and `workplace_seniority` memberships for that workplace.
5. WHEN a user_info row is created or its `prefecture` field changes THEN the Group_Materializer SHALL update that user's `prefecture` group membership AND create the prefecture's group row (with `source_prefecture` set) if it does not yet exist.
6. WHEN a check-out is recorded for a user against a shift (a `CheckIn` row representing the shift's check-out is created or updated such that the shift is now considered worked, AND `shift_users.is_absent=false`) THEN the Group_Materializer SHALL recompute that user's `worktime`, `shift_seniority`, `workplace_history`, and `workplace_seniority` (for that workplace) memberships. Check-out is the canonical "shift completed" event in v1: shifts without a check-out do NOT contribute to seniority or worktime tiers, and there is no scheduled job that retro-completes shifts based on `end_time`. The `CheckInObserver` (or equivalent hook in the existing check-out write path) MUST be the only trigger.
7. WHEN a user is locked (`is_disabled=true`) THEN the Group_Materializer SHALL **NOT** delete any `user_groups` rows. The user's manual and rule-based memberships MUST be preserved so they are restored on unlock without an admin re-curating the list. Locked users SHALL instead be filtered out at every read site (Group_Catalog member counts, Group_Detail member tables, `/admin/user` Groups filter, and the user-detail Groups section).
8. WHEN a user is unlocked (`is_disabled=false`) THEN the Group_Materializer SHALL recompute every system group membership for that user from current data. Manual and rule-based memberships are unaffected (rows were never deleted on lock).
9. WHILE materialization runs THEN the Group_Materializer SHALL operate within the same database transaction as the triggering event so that membership and source state stay consistent.
10. WHEN any system group is created THEN the Group_Materializer SHALL leave `groups.name` NULL and SHALL set the appropriate combination of `kind`, `source_workplace_id`, `source_prefecture`, and `source_tier`. Because MySQL/MariaDB unique indexes treat NULL values as distinct (so a naive unique index over `(kind, source_workplace_id, source_prefecture, source_tier)` would permit duplicates for any kind that leaves source columns NULL, e.g. `worktime` or `shift_seniority`), uniqueness SHALL be enforced via a NOT NULL `identity_key` column unique-indexed on its own. `identity_key` is computed deterministically as `kind|source_workplace_id|source_prefecture|source_tier` with NULLs replaced by the literal string `null` (e.g., `worktime|null|null|morning`). The Group_Materializer MUST set `identity_key` on every insert.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Existing Workplace, WorkplaceUser, UserInfo, ShiftUser, User models

### Requirement 4: Manual Admin Groups

**User Story:** As an admin, I want to create a named group and add or remove specific employees, so that I can curate cohorts that don't fit any rule.

#### Acceptance Criteria

1. WHEN an admin with `GROUP_EDIT` creates a Manual_Group THEN the system SHALL persist a `groups` row with `kind='manual'`, an admin-supplied `name` (unique among admin groups), NULL `source_workplace_id` / `source_prefecture` / `source_tier`, and an empty `user_groups` set.
2. WHEN the admin adds a user to a Manual_Group THEN the system SHALL insert a `user_groups` row IF the user is not Locked_User, ELSE the system SHALL reject the action with a validation message.
3. WHEN the admin removes a user from a Manual_Group THEN the system SHALL delete the `user_groups` row.
4. WHEN the admin renames a Manual_Group THEN the system SHALL update the `name` field after validating uniqueness among admin groups.
5. WHEN the admin deletes a Manual_Group THEN the system SHALL delete the group row and cascade-delete its `user_groups` rows.
6. IF an admin attempts to mutate a System_Group via the manual group endpoints THEN the API SHALL return a 422 with a "system group is read-only" error.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1

### Requirement 5: Rule-Based Admin Groups

**User Story:** As an admin, I want to define a group by saying "users where prefecture = Tokyo AND japanese_level = N3", so that I can target a cohort without picking each user manually.

#### Acceptance Criteria

1. WHEN an admin with `GROUP_EDIT` creates a Rule_Based_Group THEN the system SHALL persist a `groups` row with `kind='rule_based'`, an admin-supplied `name`, NULL source columns, and a `predicate` JSON column storing an array of `{field, value}` pairs.
2. WHEN the predicate is validated THEN the system SHALL accept only the whitelisted fields: `prefecture`, `japanese_level`, `gender`, `nationality`, AND SHALL reject any other field with a 422.
3. WHEN the predicate has at least one pair THEN the system SHALL accept the rule, ELSE the system SHALL reject with "at least one condition required".
4. WHEN the predicate is saved THEN the Group_Materializer SHALL recompute membership: insert one `user_groups` row per non-Locked_User whose `user_infos` matches every `{field, value}` pair (AND semantics).
5. WHEN a user_info row changes any field referenced in any rule predicate THEN the Group_Materializer SHALL re-evaluate that user's membership in every Rule_Based_Group whose predicate uses any of the changed fields.
6. WHEN the admin deletes a Rule_Based_Group THEN the system SHALL delete the group row and cascade-delete its `user_groups` rows.
7. IF the predicate references a field whose value is NULL on a user_info row THEN the user SHALL NOT be included in the membership.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4 (shares admin group endpoints)

### Requirement 6: Group Filter on User List

**User Story:** As an admin, I want to filter the `/admin/user` list by one or more groups, so that I can quickly inspect or export a cohort.

#### Acceptance Criteria

1. WHEN the admin opens `/admin/user` THEN the user list SHALL display a "Groups" multi-select filter populated from the Group_Catalog.
2. WHEN the admin selects one or more groups THEN the user list SHALL refetch and display only users who are a Group_Member of every selected group (AND semantics across selected groups).
3. WHEN the admin clears the filter THEN the user list SHALL revert to its unfiltered state.
4. WHEN the user list query returns THEN the result count SHALL reflect the filter and SHALL exclude Locked_Users that are not displayed under the existing list rules.
5. WHERE the user has the existing user-list view permission BUT lacks `GROUP_VIEW` THEN the Groups filter SHALL not be rendered.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirements 1, 3, 4, 5

### Requirement 7: User Detail Group Display

**User Story:** As an admin viewing an employee, I want to see every group the employee belongs to, so that I have context about their cohorts at a glance.

#### Acceptance Criteria

1. WHEN the admin opens `/admin/user/[id]` THEN the user detail page SHALL display a "Groups" section listing every group the user is a Group_Member of, grouped visually by Group_Kind (system kinds first, then manual, then rule-based).
2. WHEN the section renders THEN each group entry SHALL be a link to `/admin/group/[id]`.
3. IF the user is a Locked_User THEN the Groups section SHALL display an empty state with the message "Locked users are not part of any group".
4. WHERE the admin lacks `GROUP_VIEW` THEN the Groups section SHALL not render.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirements 1, 3, 4, 5

### Requirement 8: Permissions and Access Control

**User Story:** As a system owner, I want clear permissions for who can view and edit groups, so that group management can be delegated without exposing other admin surfaces.

#### Acceptance Criteria

1. WHEN `php artisan db:seed --class=RolePermissionSeeder` runs (the existing project seeder, called from `DatabaseSeeder`) THEN the system SHALL ensure two new permissions exist: `GROUP_VIEW` and `GROUP_EDIT`. Permission rows are NOT created by the migration itself — migrations only build schema in this project. The deploy step that runs `php artisan groups:backfill` (Requirement 3.1) MUST also run the seeder so the permissions exist before the new admin pages are accessed. Additionally, the seeder MUST be idempotent (re-running does not duplicate permission rows or role grants).
2. IF a request hits any group read endpoint without `GROUP_VIEW` THEN the API SHALL return 403.
3. IF a request hits any group write endpoint (create, update, delete, add/remove member) without `GROUP_EDIT` THEN the API SHALL return 403.
4. WHEN the admin role is updated by the seeder THEN it SHALL be granted both `GROUP_VIEW` and `GROUP_EDIT` by default.
5. WHEN evaluating `GROUP_VIEW` / `GROUP_EDIT` THEN the permission check SHALL be **global only** in v1 — `team_id` / workplace scoping is intentionally not honored for these two permissions. An admin granted `GROUP_VIEW` for any team sees every group in the catalog (including `workplace_current` / `workplace_history` / `workplace_seniority` rows for workplaces they cannot otherwise access), and `GROUP_EDIT` allows mutating any manual / rule-based group regardless of which workplaces are visible elsewhere in the admin UI. This is a deliberate v1 simplification; workplace-scoped group visibility is a follow-up concern tracked in the roadmap.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Existing Laratrust permission system

## Non-Functional Requirements

### Performance Requirements
- WHEN the Group_Catalog is fetched with up to 500 groups THEN the API SHALL return within 500ms p95.
- WHEN a workplace user attach/detach event triggers materialization THEN the listener SHALL complete within 200ms p95 for an affected user count of 1.
- WHEN the user-list query applies a single Group filter THEN the response SHALL not regress the existing user-list p95 by more than 100ms.

### Reliability Requirements
- WHEN a Group_Materializer listener throws THEN the triggering database transaction SHALL roll back so that source data and `user_groups` cannot diverge.
- WHEN the one-time backfill artisan command is re-run THEN it SHALL be idempotent (no duplicate group rows; no duplicate `user_groups` rows).

## Constraints and Assumptions

### Technical Constraints
- Backend: Laravel 11; new business logic must follow the Action pattern (per `CLAUDE.md`).
- Frontend: Next.js 15 + React 19 + Mantine UI; new pages use the file-based routing pattern (`.page.tsx`).
- All user-facing strings on the frontend must use the `t(...)` translation function.
- Cannot break existing user-list, user-detail, or workplace APIs; group filter is an additive query parameter.

### Assumptions
- Tier boundaries for shift_seniority (0–10 / 11–50 / 51+) and workplace_seniority (0–4 / 5–29 / 30+) are acceptable defaults for v1; can be tuned in design review.
- "Completed shift" for seniority and worktime calculations means a `shift_users` row whose shift `end_time` is in the past AND `is_absent=false`.
- Synchronous in-transaction materialization is acceptable performance-wise given typical admin operation rates.
- The `prefecture` field on `user_infos` is currently free-text (`UpdateRequest.php` accepts `nullable|string|max:255` and `UserInfo` only normalizes the `address` attribute). To prevent prefecture groups from fragmenting on case/whitespace differences, a `setPrefectureAttribute` mutator MUST be added to `UserInfo` that trims and lowercases the value before persistence; the backfill command MUST run the same normalization across existing rows before creating prefecture groups. Mapping free-text variants to a controlled prefecture vocabulary is out of scope for v1 and is tracked as a follow-up in the roadmap.
- A new `predicate` JSON column on `groups` is acceptable for storing rule-based predicates.
- Laravel's `belongsToMany(...)->using(WorkplaceUser::class)` configuration on `User::workplaces()` and `Workplace::users()` (already present in the codebase) DOES dispatch `created` / `deleted` events on the `WorkplaceUser` pivot model when `attach()` / `detach()` are called. The Group_Materializer's observer on this pivot relies on that behavior — every belongsToMany relationship that writes to `workplace_users` MUST go through `->using(WorkplaceUser::class)` so events fire. New code paths that bypass the pivot model (raw `DB::table('workplace_users')->insert()`, etc.) MUST NOT be added; if any are discovered they MUST be refactored to go through the relationship.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria for Requirements 1–8 are met.
- [ ] Backfill artisan command populates groups and `user_groups` for an existing dataset without errors and is idempotent.
- [ ] Event listeners keep `user_groups` consistent under create/update/delete on workplace, workplace_users, user_infos, shift_users, and users.
- [ ] User-list group filter and user-detail Groups section display correctly.
- [ ] PHPUnit tests cover the Group_Materializer for each system kind and for manual + rule-based groups.
- [ ] Frontend type checking (`pnpm typecheck`), lint (`pnpm lint:fix`), and formatting (`pnpm prettier`) all pass.
- [ ] Backend formatting (`npm run prettier`, `npm run pint`) passes.
- [ ] New translation keys are added via `pnpm trans:prepare` / `pnpm trans:apply`.
