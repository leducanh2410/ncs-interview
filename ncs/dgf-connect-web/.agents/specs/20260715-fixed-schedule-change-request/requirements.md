# Requirements Document

## Introduction

This feature reorganizes the existing Fixed Schedule admin area into its own top-level menu, fixes a workplace-scoping gap in the current Fixed Schedule list, and adds a full request/approval workflow that lets employees ask to change their fixed weekly schedule directly from the mobile app, subject to a per-workplace toggle and deadline configured by admins. Approved requests are applied immediately to the employee's fixed schedule. This is not a breaking change to existing data (`fixed_schedules` / `fixed_schedule_details` keep their current shape), but it changes admin navigation (moves an existing page out from under the Workplace menu) and removes a display-only column.

### Feature Summary

Employees with a fixed weekly schedule can request to change a specific day's shift (or request the day off) from the mobile app when their workplace has the feature enabled and the request window is still open; admins configure this per workplace and review/approve or reject requests from a new dedicated "Fixed Schedule" section in the web admin.

### Business Value

Today, changing an employee's fixed schedule requires the employee to inform an admin out-of-band and the admin to manually edit the schedule in the admin panel. This feature lets employees initiate the change themselves from the app, gives admins visibility into pending changes without leaving the relevant screens, and lets admins control the risk/scope of this self-service capability per workplace (some workplaces may never want employees changing their own fixed schedule).

### Scope

**In scope:**

- Move the existing Fixed Schedule admin page out of the Workplace menu into its own top-level menu with three sub-pages: Danh sách (List), Cấu hình đổi lịch (Change Config), Yêu cầu đổi lịch (Change Requests).
- Fix workplace-scoping gap: all three Fixed Schedule pages (view, create, edit, delete, configure, approve/reject) are restricted to workplaces the logged-in admin manages, consistent with how Shift/Check-in/Work History/Workplace already scope by `workplace_access` (`GlobalAdmin` keeps unrestricted access).
- Remove the "Bắt đầu từ" column from the List page (it only ever displayed `created_at`, not a meaningful business date).
- New per-workplace configuration: enable/disable employee-initiated schedule-change requests, with an editable deadline date. Requests are automatically blocked once the deadline passes, without requiring the admin to manually toggle the switch off.
- Bulk enable/disable/set-deadline across multiple selected workplaces from the Change Config page.
- New employee-initiated Fixed Schedule Change Request: **one request covers the whole week**, the same way the admin's own "Sửa lịch làm" modal edits the whole week in one save — the employee edits the full Monday–Sunday grid and submits, and the request carries one line item per day that actually changed (a different existing `Workplace_Shift`, or the day off entirely). No reason/comment field. A request is approved or rejected as one atomic unit — an admin cannot approve some of its days and reject others.
- New admin Change Request list: filter by workplace (scoped) and search by employee name, approve/reject actions on the whole request, with a detail view showing every changed day (current → requested) before approving, and an explicit warning that approving applies all of it to the employee's fixed schedule immediately.
- Mobile: a "Lịch cố định" entry point next to the date-range picker on the existing Work Schedule tab, opening a read/edit view of the employee's weekly fixed schedule (mirroring the web admin's "Sửa lịch làm" grid), letting the employee change one or more days and submit them together as a single request when the workplace's change window is open, with a banner showing the deadline.
- Mobile: visual distinction (badge) between fixed-schedule-derived items and self-registered temporary items in the existing Approved/Pending/Rejected lists on the Work Schedule tab, and on the Check-in tab.
- Mobile: visibility of the employee's own change-request status (pending/approved/rejected) directly on the affected day in the Fixed Schedule view.
- Backend API additions/changes needed to support all of the above (admin CRUD for change config, admin CRUD for change requests, user-facing read of own fixed schedule, user-facing create of a change request, exposing `is_fixed` on relevant mobile-facing responses).

**Out of scope:**

- Email/push notifications when a request is submitted or processed (see Assumptions — flagged for confirmation).
- Partial approval/rejection within one request (e.g. approving Monday's change but rejecting Wednesday's, within the same submission) — a request is resolved as a whole.
- Choosing a shift outside the target workplace's existing `Workplace_Shift` catalog.
- Changing which employee/workplace a `Fixed_Schedule` belongs to (already locked in the existing edit modal; unaffected by this feature).
- Any change to how `Fixed_Schedule` is consumed as an eligibility hint by shift planning/forecast (`AssignShiftUsersAction`, `GetShiftPlanningAction`, `GenerateForecastAction`) — those integrations keep reading the same tables and are unaffected.

## Glossary

| Term | Definition |
|------|------------|
| Fixed_Schedule | A user's recurring weekly work schedule at one workplace (`fixed_schedules` + `fixed_schedule_details`) |
| Fixed_Schedule_Detail | One day-of-week cell within a Fixed_Schedule, linking to a Workplace_Shift (or absent if the user has no shift that day) |
| Workplace_Shift | An existing shift time-range definition (start/end time) belonging to a workplace |
| Managed_Workplace | A workplace within the logged-in admin's `workplace_access` scope for the Fixed_Schedule permission group (unrestricted for GlobalAdmin) |
| Fixed_Schedule_Change_Config | Per-workplace settings controlling whether employees may submit Fixed_Schedule_Change_Requests, and the deadline after which submissions are blocked |
| Change_Window | The period during which Fixed_Schedule_Change_Config is enabled and the current date has not passed its deadline |
| Fixed_Schedule_Change_Request | An employee-submitted, whole-week request to change their Fixed_Schedule — one request groups every changed day from a single edit-and-submit action, and is approved or rejected as one atomic unit |
| Fixed_Schedule_Change_Request_Day | One changed-day line item within a Fixed_Schedule_Change_Request, recording the day, the shift it currently has, and the shift (or none, for day-off) being requested |
| Fixed_Schedule_Menu | The new top-level admin navigation menu containing the List, Change Config, and Change Request pages |
| Admin_Web | The Next.js admin web application |
| Mobile_App | The Flutter employee-facing mobile application |
| Backend_API | The Laravel REST API |

## Requirements

> **Note on mobile scope**: Requirements 9–13 describe mobile app behavior (`dgf_connect_app` repo, a separate codebase from this one). They are kept here for full-feature traceability, but the canonical, implementation-ready requirements/design/tasks for the mobile side live in that repo's own spec: `dgf_connect_app/.agents/specs/20260715-fixed-schedule-change-request/`. If the two ever diverge, the mobile repo's spec is authoritative for mobile behavior; this document is authoritative for backend API behavior (Requirement 14) and admin web behavior (Requirements 1–8).

### Requirement 1: Dedicated Fixed Schedule menu with three sub-pages

**User Story:** As an admin, I want a dedicated "Fixed Schedule" menu separate from the Workplace menu, so that I can find schedule management, change-window configuration, and change requests in one place instead of buried under Workplace.

#### Acceptance Criteria

1. WHEN an admin with any of the `fixed_schedule.*` permissions views the sidebar THEN Admin_Web SHALL display a top-level "Lịch làm cố định" menu, no longer nested under the Workplace menu.
2. WHEN the admin expands the Fixed_Schedule_Menu THEN Admin_Web SHALL display exactly three sub-items: "Danh sách", "Cấu hình đổi lịch", "Yêu cầu đổi lịch".
3. WHERE an admin lacks the permission for a given sub-page THEN Admin_Web SHALL hide that sub-item from the menu.
4. WHEN an admin navigates directly to a sub-page URL without the required permission THEN Admin_Web SHALL show the existing "no permission" state used elsewhere in the app.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: None

### Requirement 2: Workplace scoping across all Fixed Schedule pages

**User Story:** As an admin who manages a subset of workplaces, I want the Fixed Schedule pages to only show and let me act on my Managed_Workplaces, so that I cannot view or modify schedule data for workplaces outside my responsibility.

#### Acceptance Criteria

1. WHEN a non-GlobalAdmin admin opens the List page THEN Admin_Web SHALL populate the "Xưởng" filter with only that admin's Managed_Workplaces.
2. WHEN a non-GlobalAdmin admin opens the create/edit Fixed_Schedule modal THEN Admin_Web SHALL populate the "Xưởng" select with only that admin's Managed_Workplaces.
3. IF a non-GlobalAdmin admin submits a create, update, or delete request for a Fixed_Schedule whose `workplace_id` is not in their Managed_Workplaces THEN Backend_API SHALL reject the request with an authorization error, regardless of what the client sent.
4. WHEN a GlobalAdmin opens any Fixed_Schedule page THEN Admin_Web SHALL show all workplaces, unchanged from current behavior.
5. WHEN a non-GlobalAdmin admin opens the Change Config or Change Request pages THEN Admin_Web SHALL apply the same Managed_Workplace scoping as the List page (filters, row visibility, and the underlying write/approve actions).

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 3: Remove the "Bắt đầu từ" column from the List page

**User Story:** As an admin, I want the List page to stop showing a "Bắt đầu từ" date, so that I am not misled into thinking it is an editable, meaningful effective date when it is only the record's creation timestamp.

#### Acceptance Criteria

1. WHEN an admin views the List page THEN Admin_Web SHALL NOT render a "Bắt đầu từ" column.
2. WHEN Backend_API returns a Fixed_Schedule list THEN Backend_API SHALL stop computing/exposing the `started_at` accessor to this endpoint's response (or Admin_Web SHALL simply stop consuming it, per the chosen design).

#### Additional Details
- **Priority**: Low
- **Complexity**: Low
- **Dependencies**: None

### Requirement 4: Per-workplace change-window configuration

**User Story:** As an admin, I want to enable or disable employee-initiated fixed-schedule change requests per workplace, with an editable deadline, so that I control which workplaces allow this and until when.

#### Acceptance Criteria

1. WHEN an admin opens the Change Config page THEN Admin_Web SHALL list Managed_Workplaces with the current enabled/disabled state and deadline (if any) of their Fixed_Schedule_Change_Config.
2. WHEN an admin toggles the inline switch for one workplace THEN Backend_API SHALL persist the `is_enabled` value for that workplace's Fixed_Schedule_Change_Config immediately.
3. WHEN an admin edits a workplace's deadline THEN Backend_API SHALL persist the new deadline for that workplace's Fixed_Schedule_Change_Config.
4. WHEN an admin selects multiple workplaces and applies a bulk enable/disable/deadline action THEN Backend_API SHALL update the Fixed_Schedule_Change_Config for every selected workplace in one operation.
5. IF a workplace has no Fixed_Schedule_Change_Config record yet AND an admin enables it or sets a deadline THEN Backend_API SHALL create the record.
6. IF an admin without permission for a given workplace attempts to change its config THEN Backend_API SHALL reject the request with an authorization error.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2

### Requirement 5: Automatic closure of the change window at the deadline

**User Story:** As an admin, I want the change window to close automatically once the deadline passes, so that I do not have to remember to manually turn it off.

#### Acceptance Criteria

1. IF a workplace's Fixed_Schedule_Change_Config has `is_enabled = true` AND the current date is on or before the deadline THEN Backend_API SHALL consider the Change_Window open for that workplace.
2. IF the current date is after the deadline THEN Backend_API SHALL consider the Change_Window closed for that workplace, regardless of the stored `is_enabled` value.
3. WHEN the Change_Window is closed for a workplace THEN Backend_API SHALL reject any new Fixed_Schedule_Change_Request submission for employees of that workplace.
4. WHEN the Change_Window is closed for a workplace THEN Mobile_App SHALL hide or disable the change-request submission action for that workplace's Fixed Schedule view.
5. WHEN an admin views the Change Config page for a workplace whose deadline has passed THEN Admin_Web SHALL visually indicate "Đã hết hạn" distinctly from a manually disabled ("Tắt") state.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 4

### Requirement 6: Employee submits a whole-week fixed-schedule change request

**User Story:** As an employee with a fixed schedule, I want to edit my whole week the same way an admin does and submit it as one request, so that I can change one or more days in a single action instead of one request per day.

#### Acceptance Criteria

1. WHEN an employee opens the edit view of their Fixed Schedule on Mobile_App AND the Change_Window is open for that workplace THEN Mobile_App SHALL present the full Monday–Sunday grid, pre-filled with the employee's current schedule, editable per day — mirroring the admin web's "Sửa lịch làm" modal.
2. WHEN the employee changes one or more days (different Workplace_Shift, adds a shift to a day that had none, or removes a day's shift/day-off) and submits THEN Backend_API SHALL create one Fixed_Schedule_Change_Request with `status = pending` containing one Fixed_Schedule_Change_Request_Day per **changed** day only — unchanged days are not included — with no reason/comment field.
3. IF the employee submits without changing any day from the current schedule THEN Backend_API SHALL reject the submission (nothing to request).
4. IF the Change_Window is closed for the employee's workplace THEN Backend_API SHALL reject the submission.
5. IF the employee already has a pending Fixed_Schedule_Change_Request for that Fixed_Schedule THEN Backend_API SHALL reject a new submission until the existing one is resolved (approved or rejected) — at most one pending request per Fixed_Schedule at a time, not per day.
6. IF any requested Workplace_Shift in the submission does not belong to the employee's workplace THEN Backend_API SHALL reject the entire submission.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 5

### Requirement 7: Admin reviews and resolves change requests, applied immediately on approval

**User Story:** As an admin, I want to approve or reject an employee's whole change request, with approval taking effect immediately, so that I have a fast, low-friction way to action requests without a separate manual schedule edit.

#### Acceptance Criteria

1. WHEN an admin opens a Fixed_Schedule_Change_Request THEN Admin_Web SHALL show every Fixed_Schedule_Change_Request_Day it contains (current shift → requested shift, or "Xin nghỉ") before any approve/reject action is available.
2. WHEN an admin clicks Approve THEN Admin_Web SHALL show a warning that all changed days shown will be applied to the employee's Fixed_Schedule immediately upon confirmation.
3. WHEN the admin confirms approval THEN Backend_API SHALL, in one transaction, set the request's `status = approved`, record `processed_by_id`/`processed_at`, and apply every Fixed_Schedule_Change_Request_Day's change (update, create, or delete, as appropriate) to the corresponding Fixed_Schedule_Detail — all days in the request are applied together, or none are (Requirement rollback on failure, see Reliability NFR).
4. WHEN an admin clicks Reject on a Fixed_Schedule_Change_Request THEN Backend_API SHALL set `status = rejected`, record `processed_by_id`/`processed_at`, and leave every day of the employee's Fixed_Schedule unchanged — rejection is all-or-nothing, matching approval.
5. IF a Fixed_Schedule_Change_Request is not in `pending` status THEN Backend_API SHALL reject further approve/reject actions on it.
6. IF an admin without permission for the request's workplace attempts to approve or reject it THEN Backend_API SHALL reject the request with an authorization error.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 6

### Requirement 8: Change Request list with filtering and search

**User Story:** As an admin, I want to see all fixed-schedule change requests filtered by workplace, submission date, and searchable by employee name, so that I can find and process the ones relevant to me without old, already-expired rounds cluttering the view.

#### Acceptance Criteria

1. WHEN an admin opens the Yêu cầu đổi lịch page THEN Admin_Web SHALL list Fixed_Schedule_Change_Requests (one row per request, i.e. per whole-week submission, not per day) scoped to the admin's Managed_Workplaces, defaulting to `status = pending`.
2. WHEN an admin selects a workplace filter THEN Admin_Web SHALL show only requests for that workplace (still constrained to Managed_Workplaces).
3. WHEN an admin types into the employee search box THEN Admin_Web SHALL filter requests to those whose employee name matches the query.
4. WHEN an admin selects a status filter (Chờ duyệt/Đã duyệt/Từ chối/Tất cả) THEN Admin_Web SHALL show only requests matching that status.
5. WHEN an admin views a row THEN Admin_Web SHALL show how many days it changes (e.g. "3 ngày") in the list, with the full per-day breakdown available in the detail view (Requirement 7.1).
6. WHEN an admin selects multiple pending requests and applies a bulk approve or bulk reject action THEN Admin_Web SHALL show the same immediate-effect warning as the single-row approve action before confirming, then process each selected request per Requirement 7 (each request still resolved as a whole).
7. WHEN an admin selects exactly one workplace in the workplace filter THEN Admin_Web SHALL default the "Khoảng ngày gửi" (submission date range) filter to `[that workplace's Fixed_Schedule_Change_Config.updated_at date → deadline]` — i.e. the current round only. Example: an admin reconfigures a workplace's window on 2026-02-01 with `deadline = 2026-02-15` (having previously configured it on 2026-01-01 with `deadline = 2026-01-10`); opening the list for that workplace SHALL default to showing only requests submitted between 2026-02-01 and 2026-02-15 — the January round's requests are excluded from the default view (still reachable by widening the date filter manually).
8. IF the selected workplace has no Fixed_Schedule_Change_Config yet (never configured) THEN Admin_Web SHALL NOT apply a default date restriction (the computed default has nothing to anchor to).
9. WHEN no single workplace is selected (all Managed_Workplaces, or the filter is cleared) THEN Admin_Web SHALL NOT apply a default date restriction, since each workplace may have a different current round.
10. WHEN an admin manually changes the date-range filter THEN Admin_Web SHALL use the admin's chosen range instead of the computed default, including widening it to see requests from a previous round.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 7

### Requirement 9: Mobile entry point to view Fixed Schedule from the Work Schedule tab

**User Story:** As an employee, I want to open my fixed weekly schedule from the existing Work Schedule tab, so that I don't need to hunt for a separate menu.

#### Acceptance Criteria

1. WHEN an employee opens the Work Schedule tab THEN Mobile_App SHALL display a "Lịch cố định" button positioned alongside (same row as) the existing date-range picker, which is resized to accommodate it.
2. WHEN the employee taps "Lịch cố định" THEN Mobile_App SHALL open a view of their weekly Fixed Schedule, showing each day of the week and its assigned Workplace_Shift (or "no shift") — mirroring the layout of the admin web's edit grid.
3. IF the employee has no Fixed_Schedule at any workplace THEN Mobile_App SHALL show an empty state instead of the grid.
4. IF the employee has Fixed_Schedules at more than one workplace THEN Mobile_App SHALL let the employee pick which workplace's schedule to view.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None

### Requirement 10: Mobile change-request submission and deadline visibility

**User Story:** As an employee, I want to see the deadline for requesting schedule changes and submit a request while it's still open, so that I know how much time I have and can act in time.

#### Acceptance Criteria

1. WHEN the employee opens the Fixed Schedule view for a workplace with an open Change_Window THEN Mobile_App SHALL display a banner at the top showing the deadline date.
2. WHILE the Change_Window is open THEN Mobile_App SHALL let the employee edit the whole week's grid and submit all changed days together as one Fixed_Schedule_Change_Request, per Requirement 6 (not a separate submission per day).
3. IF the Change_Window is closed for that workplace THEN Mobile_App SHALL render the Fixed Schedule view as read-only, without the deadline banner's call-to-action.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 9, Requirement 5

### Requirement 11: Mobile visibility of the employee's own request status

**User Story:** As an employee, I want to see whether my change request is pending, approved, or rejected directly on the affected day, so that I know its outcome without asking an admin.

#### Acceptance Criteria

1. WHILE a day cell in the Fixed Schedule view has an associated unresolved or recently-resolved Fixed_Schedule_Change_Request THEN Mobile_App SHALL display a status indicator on that cell: "Chờ duyệt", "Đã duyệt", or "Từ chối".
2. WHEN a Fixed_Schedule_Change_Request is approved THEN Mobile_App SHALL reflect the updated Workplace_Shift (or day-off state) for that cell on next load.
3. WHEN a day cell already has a pending Fixed_Schedule_Change_Request THEN Mobile_App SHALL prevent submitting another request for that same day until it is resolved, matching Requirement 6.5.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 10

### Requirement 12: Distinguish fixed vs. temporary shifts in the Work Schedule status tabs

**User Story:** As an employee, I want to tell at a glance which items in Đã duyệt/Chờ duyệt/Từ chối are from my fixed schedule versus shifts I registered for myself, so that I understand why they appear there.

#### Acceptance Criteria

1. WHEN an item in the Đã duyệt, Chờ duyệt, or Từ chối list corresponds to a fixed shift (`is_fixed = true`) THEN Mobile_App SHALL display a distinguishing badge/label on that item.
2. WHEN an item corresponds to a self-registered temporary shift (`is_fixed = false`) THEN Mobile_App SHALL NOT display the fixed-schedule badge.
3. WHERE the `WorkSchedule` entity is used to render these lists THEN Mobile_App SHALL parse and retain the `is_fixed` field from the API response (currently dropped).

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: None

### Requirement 13: Distinguish fixed vs. temporary shifts on the Check-in tab

**User Story:** As an employee, I want to see whether today's shift on the check-in screen is my fixed shift or a shift I registered for myself, so that I have the same context there as on the Work Schedule tab.

#### Acceptance Criteria

1. WHEN the Check-in tab displays today's shift THEN Mobile_App SHALL show a label/badge indicating whether it is a fixed shift or a self-registered shift, using the same visual treatment as Requirement 12.
2. WHERE the check-in shift response does not currently include `is_fixed` THEN Backend_API SHALL add it to the `GET /user/check-in/shift` response.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 12

### Requirement 14: Supporting API additions

**User Story:** As a developer, I want the backend to expose the endpoints the above admin and mobile flows need, so that the frontend and mobile clients have a complete, consistent API surface to build against.

#### Acceptance Criteria

1. WHEN Admin_Web requests Fixed_Schedule_Change_Config data THEN Backend_API SHALL provide list (scoped, per Requirement 2), single-update, and bulk-update endpoints under the existing Admin route group with `fixed_schedule.*`-equivalent permission checks.
2. WHEN Admin_Web requests Fixed_Schedule_Change_Requests THEN Backend_API SHALL provide a scoped, filterable (workplace, employee name, status) list endpoint plus approve/reject (single and bulk) endpoints.
3. WHEN Mobile_App requests the employee's own fixed schedule THEN Backend_API SHALL provide a `GET /user/fixed-schedule` (or equivalent) endpoint scoped to the authenticated user, optionally by workplace, including per-day change-window state and any existing change-request status.
4. WHEN Mobile_App submits a change request THEN Backend_API SHALL provide a `POST` endpoint that enforces Requirement 6's validation rules.
5. WHEN Backend_API returns shift/schedule data consumed by the Work Schedule and Check-in tabs THEN Backend_API SHALL include the `is_fixed` field so Requirements 12 and 13 can be implemented.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 4, 6, 7, 8, 12, 13

## Non-Functional Requirements

### Performance Requirements
- WHEN the List, Change Config, or Change Request pages load with typical data volumes (hundreds of employees per workplace) THEN Admin_Web SHALL paginate results rather than loading the full dataset at once, consistent with existing list pages.
- WHEN the mobile Fixed Schedule view loads THEN Mobile_App SHALL render within the same latency budget as the existing Work Schedule tab (single API call per workplace).

### Security Requirements
- WHEN any Fixed Schedule admin endpoint is called THEN Backend_API SHALL enforce both the relevant permission (`fixed_schedule.*`) and Managed_Workplace scoping server-side, not only in the UI.
- WHEN a change request is created, approved, or rejected THEN Backend_API SHALL verify the acting user's authorization (employee owns the target Fixed_Schedule; admin manages the target workplace) before mutating data.

### Usability Requirements
- WHEN an admin performs an approval that immediately changes an employee's schedule THEN Admin_Web SHALL require an explicit confirmation step (per Requirement 7.1) to prevent accidental application.
- WHEN the Change_Window is closed THEN Mobile_App SHALL clearly communicate why the change action is unavailable (e.g., "Đã hết hạn đổi lịch") rather than simply hiding it without explanation.

### Reliability Requirements
- IF the approval transaction (status update + Fixed_Schedule_Detail update) partially fails THEN Backend_API SHALL roll back both changes so the request never ends up `approved` while the schedule remains unchanged, or vice versa.

## Constraints and Assumptions

### Technical Constraints
- Employees can only be offered Workplace_Shifts that already exist in the target workplace's shift catalog (no free-form time entry), consistent with the existing Fixed Schedule create/edit modal.
- The existing `fixed_schedules` / `fixed_schedule_details` schema is reused as-is; only new tables/columns are added for config and change requests (no breaking migration of existing data).

### Assumptions
- **Notifications are out of scope for this iteration.** No email/push notification is sent on request submission or resolution; employees and admins discover status by opening the relevant screens. Flag for confirmation — if notifications are actually wanted, this needs to be added as a requirement before design.
- **"Day off" requests are allowed** (an employee may request removal of a day's shift entirely, not only a swap to another shift), based on it not being explicitly excluded and being consistent with the data model discussed. Flag for confirmation.
- Rejecting a request does not require the admin to enter a reason (symmetric with the request itself having no reason field).
- Deleting a Fixed_Schedule from the List page follows the same Managed_Workplace scoping as view/create/edit (not explicitly stated by the user but assumed for consistency).
- "Áp dụng ngay khi duyệt" means the `fixed_schedule_details` row is updated synchronously within the approval request/transaction, not via an async job.
- The three-way admin scoping fix (Requirement 2) reuses the existing `workplace_access` / Laratrust-team mechanism already used by Shift/Check-in/Work History/Workplace, adding `fixed_schedule` as a new scoped permission group — this is a design-level decision to be confirmed in design.md rather than an alternative access-control system.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria in Requirements 1–14 are met
- [ ] Non-functional requirements (performance, security, usability, reliability) are satisfied
- [ ] Admin cannot view, create, edit, delete, configure, or approve/reject anything outside their Managed_Workplaces (verified by test with a scoped admin account)
- [ ] Approving a change request updates the employee's fixed schedule and is reflected immediately on both Admin_Web and Mobile_App
- [ ] Change_Window auto-closes at the deadline without manual admin intervention
- [ ] Mobile Work Schedule and Check-in tabs visibly distinguish fixed vs. temporary shifts
- [ ] Testing criteria are passed (backend feature/unit tests per workspace conventions; frontend typecheck/lint/prettier clean)
