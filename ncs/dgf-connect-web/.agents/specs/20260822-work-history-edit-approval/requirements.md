# Requirements Document

## Introduction

This feature introduces a request/approval workflow for manual edits to employee attendance records (`check_ins`) on the "Chấm công" (Work History) admin page. Today, any admin with edit access changes an employee's check-in/check-out time directly and the change applies immediately, with no record of why it was changed and no second-person review. This feature splits that single "edit" permission into two roles — who may **propose** an edit to attendance and who may **approve** it — requires a reason for every field changed, and holds the change as `pending` until an authorized approver resolves it. This is a breaking change to the existing edit flow: edits made through the Work History page no longer apply immediately; they apply only once approved. `check_ins` rows are otherwise unaffected (no column removed), and existing historical data is not migrated (no retroactive requests are created for past edits).

### Feature Summary

Admins/leaders with attendance-edit permission submit a check-in edit with a mandatory reason per changed field, which is held as a pending request until an admin/leader with attendance-approve permission (which may be the same person) approves or rejects it — reviewable both inline on the existing Work History list and from a new dedicated "Yêu cầu sửa công" review screen.

### Business Value

Attendance data feeds payroll (`work_minutes*`, salary calculation). Unreviewed, unexplained direct edits to check-in/check-out times are a payroll-integrity and audit risk — there is currently no record of who changed what value or why, and no second-person check on workshop-level (xưởng) leaders correcting their own team's time records. This feature adds accountability (mandatory per-field reason) and an optional approval gate (a leader without approve permission cannot make a correction take effect unilaterally), while still letting leaders who are trusted with both permissions resolve their own corrections in one fast step.

### Scope

**In scope:**

- Two independently-assignable permissions in Role Management: propose an attendance edit (the existing `work_history.edit` permission, kept as-is so current role assignments are unaffected) and a new permission to approve/reject a proposed attendance edit.
- A mandatory reason field per edited attribute (check-in time, check-out time, break minutes, etc.) captured at edit-submission time.
- Editing a `Check_In` via the Work History page creates a `Work_History_Edit_Request` with status `pending` instead of writing directly to `check_ins`. The underlying `Check_In` values, and downstream payroll calculations, are unaffected until the request is approved.
- Work History list page: new "Trạng thái" and "Lý do sửa đổi" columns showing the latest edit request's state and per-field reasons; an inline "Duyệt" action on rows with a pending request, visible only to users who also hold the approve permission.
- New dedicated "Yêu cầu sửa công" (Work History Edit Requests) admin screen, structured like the existing "Yêu cầu đổi lịch" (Fixed Schedule Change Requests) screen: filterable/searchable list, detail view of old → new values with reasons, single and bulk approve/reject.
- Approve applies the request's new values to the `Check_In` (same recalculation of `work_minutes*` and downstream jobs the current direct-edit flow already performs) and records who approved it and when.
- Reject requires the approver to enter a rejection reason, leaves the `Check_In` unchanged, and records who rejected it and when.
- A `Check_In` with an unresolved (`pending`) edit request cannot be edited again until that request is approved or rejected.
- Self-approval is allowed: a user holding both the propose and approve permissions may approve their own submitted request.
- Backend API additions to support all of the above (submit edit request, list/detail edit requests, approve, reject, bulk-resolve).

**Out of scope:**

- The separate admin/manager self-attendance system (`admin_attendance_sessions` / `AdminAttendanceSession`, under `/admin/attendance`) — this feature only covers the employee shift check-in system (`check_ins` / `/manage/work-history`). Confirmed by stakeholder.
- Retroactive request records for edits made before this feature ships.
- Notifications (email/push) on submission or resolution of a request.
- Changes to the "Add user to shift" flow (`Work_History_Add`) — only editing an existing `Check_In` is affected.
- Changes to export formats/logic — exports continue to reflect the committed (approved) `Check_In` values, since pending edits never touch `check_ins`.
- Export from Work_History_Edit_Request_List — unlike the Fixed Schedule Change Requests screen, no export button is requested for this iteration.
- The monthly Work History view (`/manage/work-history/monthly`) — remains a read-only aggregate and is not changed by this feature; edits and approvals only happen from Work_History_List and Work_History_Edit_Request_List.

## Glossary

| Term | Definition |
|------|------------|
| Check_In | An employee's attendance record for one shift (`check_ins` table): check-in/out time, break minutes, computed work minutes |
| Work_History_Edit_Request | A proposed edit to one Check_In, holding old and new values per field plus a reason per field, with status pending/approved/rejected |
| Work_History_Edit_Request_Item | One changed-field line item within a Work_History_Edit_Request: field name, old value, new value, reason |
| Managed_Workplace | A workplace within the logged-in admin's `workplace_access` scope for the Work_History permission group (unrestricted for GlobalAdmin) |
| Work_History_List | The existing "Chấm công" admin page (`/manage/work-history`) |
| Work_History_Edit_Request_List | The new dedicated "Yêu cầu sửa công" admin page reviewing pending/resolved edit requests |
| Propose_Permission | Permission to submit an attendance edit (creates a Work_History_Edit_Request) — the existing `work_history.edit` permission, unchanged |
| Approve_Permission | New permission to approve or reject a Work_History_Edit_Request |
| Admin_Web | The Next.js admin web application |
| Backend_API | The Laravel REST API |

## Requirements

### Requirement 1: Independent propose and approve permissions in Role Management

**User Story:** As a system administrator, I want to grant "propose attendance edit" and "approve attendance edit" as two independent permissions per role, so that I can configure a workshop leader as edit-only, approve-only, or both, matching each person's actual responsibility.

#### Acceptance Criteria

1. WHEN an administrator opens Role Management THEN Admin_Web SHALL display Propose_Permission ("Sửa công") and Approve_Permission ("Duyệt yêu cầu sửa công") as two separate, independently toggleable permission entries within the Work History permission group.
2. WHEN a role has only Propose_Permission THEN Backend_API SHALL allow that role's users to submit edit requests but SHALL reject any approve/reject action from them.
3. WHEN a role has only Approve_Permission THEN Backend_API SHALL allow that role's users to approve/reject edit requests but SHALL reject any edit-submission action from them.
4. WHEN a role has both permissions THEN Backend_API SHALL allow both actions, including approving a request that user themselves submitted.
5. WHERE a user has neither permission and opens Work_History_List or Work_History_Edit_Request_List THEN Admin_Web SHALL show the existing "no permission" state used elsewhere in the app.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None

### Requirement 2: Mandatory per-field reason when editing a Check_In

**User Story:** As an approver, I want every changed attribute of a Check_In edit to carry its own explanation, so that I can judge whether the correction is legitimate without asking the submitter separately.

#### Acceptance Criteria

1. WHEN a user with Propose_Permission opens the edit modal for a Check_In THEN Admin_Web SHALL display the existing editable fields (check-in date/time, check-out date/time, break minutes) plus a reason input next to each field.
2. IF the user changes a field's value from its current value THEN Admin_Web SHALL require a non-empty reason for that field before the form can be submitted.
3. IF a field's value is left unchanged THEN Admin_Web SHALL NOT require a reason for that field.
4. IF the user submits with no field changed at all THEN Admin_Web SHALL reject the submission (nothing to request), consistent with the existing modal requiring a change.
5. WHEN the user submits a valid edit THEN Backend_API SHALL persist one Work_History_Edit_Request_Item per changed field, each with its old value, new value, and reason.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 3: Editing creates a pending request instead of an immediate update

**User Story:** As a payroll-data owner, I want a proposed Check_In edit to be held for review rather than applied immediately, so that unreviewed corrections cannot silently affect payroll calculations.

#### Acceptance Criteria

1. WHEN a valid edit is submitted per Requirement 2 THEN Backend_API SHALL create a Work_History_Edit_Request with `status = pending`, `requested_by_id` set to the submitting user, and SHALL NOT modify the underlying `Check_In` row.
2. WHILE a Work_History_Edit_Request for a Check_In is `pending` THEN Backend_API SHALL NOT recalculate that Check_In's `work_minutes*` fields or trigger the downstream payroll/report jobs the current direct-edit flow triggers.
3. IF a Check_In already has a `pending` Work_History_Edit_Request THEN Admin_Web SHALL disable the edit action for that row, and Backend_API SHALL reject a new edit submission for that Check_In until the existing request is resolved.
4. WHEN a Work_History_Edit_Request is rejected THEN Backend_API SHALL allow a new edit to be submitted for that Check_In.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2

### Requirement 4: Work History list shows request status and supports inline approval

**User Story:** As a workshop leader who can both edit and approve, I want to see a record's pending status and approve it without leaving the Work History page, so that I can resolve my own corrections in one fast step.

#### Acceptance Criteria

1. WHEN a user views Work_History_List THEN Admin_Web SHALL display a "Trạng thái" column showing each row's latest Work_History_Edit_Request status (none/pending/approved/rejected) and a "Lý do sửa đổi" column/affordance showing the per-field reasons of that latest request; when the latest request is `rejected`, this SHALL include the approver's rejection reason (Requirement 7.2) alongside the submitter's original per-field reasons.
2. WHILE a Check_In has a `pending` Work_History_Edit_Request THEN Admin_Web SHALL continue to display the current committed values (Start time, End time, Work hours, etc.) in the existing value columns — the proposed new values are visible only via the "Lý do sửa đổi" affordance or the request detail, never overwriting the committed columns before approval.
3. IF a row has a `pending` Work_History_Edit_Request AND the viewing user holds Approve_Permission THEN Admin_Web SHALL show a "Duyệt" action directly on that row, opening a confirmation showing old → new values and reasons before applying.
4. WHERE the viewing user lacks Approve_Permission THEN Admin_Web SHALL show a row's status and reason as read-only, without an approve/reject action.
5. WHEN the inline "Duyệt" action is confirmed THEN Admin_Web SHALL invoke the same approve behavior defined in Requirement 6.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Requirement 3

### Requirement 5: Dedicated Work History Edit Request review screen

**User Story:** As a leader who only has approve permission, I want a dedicated screen listing attendance edit requests for my workplaces, so that I can review and resolve them without needing access to the full Work History list.

#### Acceptance Criteria

1. WHEN a user with Approve_Permission opens Work_History_Edit_Request_List THEN Admin_Web SHALL list Work_History_Edit_Requests scoped to the user's Managed_Workplaces, defaulting to `status = pending`.
2. WHEN the user opens Work_History_Edit_Request_List THEN Admin_Web SHALL provide, at minimum, the following filters, consistent with the filtering pattern of the existing Fixed Schedule Change Requests screen: workplace ("Xưởng", restricted to Managed_Workplaces), employee name search ("Nhân viên"), status ("Trạng thái": Chờ duyệt/Đã duyệt/Từ chối/Tất cả, default Chờ duyệt), and submission date range ("Thời gian").
3. WHEN the user applies any combination of these filters THEN Admin_Web SHALL narrow the list to requests matching all selected criteria.
4. WHEN the user opens a request's detail THEN Admin_Web SHALL show every Work_History_Edit_Request_Item (field, old value, new value, reason) before any approve/reject action is available.
5. WHEN the user selects multiple pending requests and applies bulk approve or bulk reject THEN Admin_Web SHALL process each selected request per Requirement 6/7, atomically per request.
6. IF a user without Approve_Permission navigates to Work_History_Edit_Request_List THEN Admin_Web SHALL show the existing "no permission" state.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 3

### Requirement 6: Approving a request applies it atomically

**User Story:** As an approver, I want approving a request to immediately and safely apply the corrected values, so that the payroll data reflects the reviewed correction right away.

#### Acceptance Criteria

1. WHEN an approver confirms approval of a `pending` Work_History_Edit_Request THEN Backend_API SHALL, in one transaction, apply every Work_History_Edit_Request_Item's new value to the corresponding Check_In field, recalculate `work_minutes*` and trigger the same downstream jobs the current direct-edit flow triggers, and set the request's `status = approved`, `processed_by_id`, `processed_at`.
2. IF the Work_History_Edit_Request is not in `pending` status at the time of confirmation THEN Backend_API SHALL reject the approval attempt (409).
3. IF the approving user lacks Approve_Permission for the request's workplace THEN Backend_API SHALL reject the request with an authorization error.
4. WHEN a user holds both Propose_Permission and Approve_Permission THEN Backend_API SHALL permit them to approve a request they submitted themselves.
5. IF any step of the approval transaction fails THEN Backend_API SHALL roll back all changes, leaving the Check_In and the request's status unchanged.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 3

### Requirement 7: Rejecting a request requires a reason and leaves data unchanged

**User Story:** As an approver, I want to reject a request with an explanation, so that the submitter understands why it was declined and can resubmit correctly.

#### Acceptance Criteria

1. WHEN an approver rejects a `pending` Work_History_Edit_Request THEN Admin_Web SHALL require a non-empty rejection reason before the reject action can be confirmed.
2. WHEN the rejection is confirmed THEN Backend_API SHALL set the request's `status = rejected`, record `processed_by_id`, `processed_at`, and the rejection reason, and SHALL NOT modify the underlying Check_In.
3. IF the Work_History_Edit_Request is not in `pending` status at the time of confirmation THEN Backend_API SHALL reject the rejection attempt (409).
4. IF the rejecting user lacks Approve_Permission for the request's workplace THEN Backend_API SHALL reject the request with an authorization error.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 3

### Requirement 8: Supporting API additions

**User Story:** As a developer, I want the backend to expose the endpoints the propose/review/approve flows need, so that the admin web has a complete, consistent API surface to build against.

#### Acceptance Criteria

1. WHEN Admin_Web submits a Check_In edit THEN Backend_API SHALL expose an endpoint that creates a Work_History_Edit_Request per Requirement 2/3 instead of writing directly to `check_ins` (the exact route shape — reusing or replacing the current update-check-in endpoint — is a design decision).
2. WHEN Admin_Web requests Work_History_Edit_Requests THEN Backend_API SHALL provide a scoped, filterable (workplace, employee name, status, submission date range) list endpoint returning each request's items, plus approve/reject endpoints (single and bulk); a separate detail endpoint is not required since the list response already carries everything Requirement 5.4's detail view needs.
3. WHEN Work_History_List loads THEN Backend_API SHALL include each Check_In's latest Work_History_Edit_Request status and reasons in the response, so Requirement 4's columns can be rendered without an extra request per row.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 2, 3, 5, 6, 7

## Non-Functional Requirements

### Performance Requirements
- WHEN Work_History_Edit_Request_List loads with typical data volumes THEN Admin_Web SHALL paginate results rather than loading the full dataset at once, consistent with existing list pages.
- WHEN Work_History_List loads THEN Backend_API SHALL avoid N+1 queries when attaching each row's latest edit-request status (Requirement 8.3).

### Security Requirements
- WHEN any attendance-edit-request endpoint is called THEN Backend_API SHALL enforce the relevant permission (Propose_Permission or Approve_Permission) and Managed_Workplace scoping server-side, not only in the UI.
- WHEN a Work_History_Edit_Request is approved or rejected THEN Backend_API SHALL re-verify the acting user's authorization at that moment (not only when the request was first listed), preventing a stale client state from bypassing a permission change.

### Usability Requirements
- WHEN an approver approves a request THEN Admin_Web SHALL show the old → new values and reasons before the action can be confirmed, so approval is never a blind click.
- WHEN a user without Approve_Permission views a pending row THEN Admin_Web SHALL make clear the record is awaiting another person's review, not stuck or broken.

### Reliability Requirements
- IF the approval transaction (status update + Check_In update + payroll recalculation) partially fails THEN Backend_API SHALL roll back all of it, so a request never ends up `approved` while the Check_In remains unchanged, or vice versa (Requirement 6.5).
- IF two edit submissions for the same Check_In are sent concurrently THEN Backend_API SHALL guarantee at most one succeeds in creating a `pending` Work_History_Edit_Request (e.g. a database-level uniqueness guard or row lock evaluated inside the same transaction as the insert), not a check-then-act read followed by a separate write.
- IF two approve/reject actions for the same Work_History_Edit_Request are sent concurrently THEN Backend_API SHALL guarantee at most one succeeds, via a status check performed inside the same locked transaction as the update (Requirement 6.2/7.3), not a separate read followed by a write.

## Constraints and Assumptions

### Technical Constraints
- The existing `check_ins` schema and computed `work_minutes*` recalculation logic (`WorkHistoryService`) are reused as-is for the approve step; only new tables (`work_history_edit_requests`, `work_history_edit_request_items`) are added.
- Workplace scoping reuses the existing `workplace_access` mechanism already used by Work History (`CheckIn::scopeApplyWorkplaceFilter`), consistent with how Fixed Schedule Change Requests scope by `workplace_id`.

### Assumptions
- **Scope is limited to the `check_ins` / Work History system.** The separate admin self-attendance system (`admin_attendance_sessions`) is not covered by this iteration. Confirmed by stakeholder.
- **One edit submission = one request.** All fields changed in a single edit-modal submission belong to one Work_History_Edit_Request with one item per changed field, not one request per field.
- **Self-approval is intentional**, unlike the existing `AdminLeaveRequest` pattern which forbids it — here the approver is reviewing a correction to someone else's (the employee's) attendance record, not their own leave, so a dual-permission leader approving their own submitted correction is acceptable.
- **No reason is required from the submitter for unchanged fields**, and no reason is required at all if a field is not touched.
- **Exports are unaffected**: since pending edits never modify `check_ins`, existing export logic (recently fixed for overnight-shift formatting) continues to read only committed, approved values.
- Bulk approve/reject on Work_History_Edit_Request_List is all-or-nothing per request (a request's items are never partially approved), matching the Fixed Schedule Change Request precedent.
- **On approval, `checked_in_by`/`checked_out_by` are set to the original requester** (the person who proposed the value), not the approver — preserving the existing field's meaning of "who set this value" rather than "who authorized it." Confirmed by stakeholder.
- Work_History_Edit_Request_List, filtered to a specific employee/date with `status = Tất cả`, serves as the audit trail of all past requests (including rejected ones) for a given Check_In — no separate history view is needed.

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria in Requirements 1–8 are met
- [ ] Non-functional requirements (performance, security, usability, reliability) are satisfied
- [ ] A user with only Propose_Permission can submit edits but cannot approve/reject, verified by test
- [ ] A user with only Approve_Permission can approve/reject but cannot submit edits, verified by test
- [ ] A user with both permissions can approve their own submitted request
- [ ] An approved request's new values are reflected in `check_ins`, `work_minutes*`, and downstream payroll/report jobs; a rejected request leaves `check_ins` untouched
- [ ] A Check_In with a pending request cannot be edited again until resolved
- [ ] Testing criteria are passed (backend feature/unit tests per workspace conventions; frontend typecheck/lint/prettier clean)
