# Requirements Document

## Introduction

Admins (workplace managers — users holding the `manager.auth` permission) currently have no way to request leave through the system; only general employees can submit absence notices tied to a specific shift. This feature introduces a standalone leave-request workflow for admins: an admin submits a request of one of four types (有休 / 休み / 長期休み / 経費申請) with a date range, a reason, and optional supporting files; a designated manager reviews the request from a new **Admin Management** menu on the web admin and approves or rejects it. Leave day counts are derived from a company-maintained holiday calendar, which the same manager edits through a new holiday-management screen seeded from the Yasumi Japanese holiday library. This spec covers the backend API and the web admin only — the mobile admin submission screens are specified separately. There are no breaking changes to existing endpoints.

### Feature Summary

Admins submit typed leave/expense requests with attachments; a privileged manager reviews them from the web admin, backed by an editable company holiday calendar that determines how many days each request consumes.

### Business Value

- Replaces off-system leave coordination (chat, verbal) with an auditable record of who requested what, when, and who decided.
- Gives management a single filterable view of admin absences across the company, so shift coverage gaps can be spotted before they happen.
- The company-owned holiday calendar makes day counts consistent and defensible, and is reusable by later features (payroll, shift planning).

### Scope

**Included:**

- A single new permission that gates both the request-review screen and the holiday-management screen.
- Backend API for an admin to create, list, and view their own leave requests.
- A day-count preview endpoint so a client can show the computed number of days while the user is still picking dates, without creating a request.
- Backend API for a manager to list, filter, view, approve, and reject leave requests.
- Backend API and web admin screen to manage the company holiday calendar per year, including generation from the Yasumi library and a configurable "weekends count as non-working days" switch.
- Web admin **Admin Management** menu containing the request list screen and the holiday screen.
- Attachment upload (max 3 files, 5 MB each, images and PDF) with in-browser viewing.
- Leave day calculation excluding calendar holidays and, when configured, weekends.

**Excluded:**

- Mobile admin submission UI (separate spec). This spec delivers the API the mobile app will consume.
- Paid-leave (有休) quota/balance tracking. Requests are recorded, not deducted from an entitlement.
- Push notifications and emails for request creation or decisions.
- Editing or cancelling a request after submission.
- Any linkage between an approved leave request and shift planning, attendance, or salary.
- CSV export of the request list.
- Monetary amount fields on 経費申請 requests; amounts are written into the reason text and evidenced by attachments.

## Glossary

| Term                    | Definition                                                                                                                                   |
| ----------------------- | -------------------------------------------------------------------------------------------------------------------------------------------- |
| Admin_User              | A user holding the `manager.auth` permission, i.e. someone who can sign in to the admin web/app. The only actor allowed to submit a request.  |
| Leave_Manager           | A user holding the `admin_leave.manage` permission. Reviews requests and maintains the holiday calendar.                                      |
| Admin_Leave_Request     | A single submitted request record: type, date range, reason, attachments, status, and decision metadata.                                      |
| Leave_Type              | One of `paid_leave` (有休), `day_off` (休み), `long_leave` (長期休み), `expense_claim` (経費申請).                                                |
| Request_Status          | One of `pending`, `approved`, `rejected`.                                                                                                     |
| Decision_Record         | The immutable trace of a review: the deciding Leave_Manager, the decision timestamp, and the rejection reason when the decision was a refusal. |
| Leave_Attachment        | An uploaded file (image or PDF) attached to an Admin_Leave_Request.                                                                            |
| Holiday_Calendar        | The set of Company_Holiday rows for one calendar year plus that year's Weekend_Setting.                                                        |
| Company_Holiday         | A single non-working date recorded in the Holiday_Calendar, with a name and a flag marking whether it came from Yasumi or was added by hand.  |
| Weekend_Setting         | A per-year boolean deciding whether Saturday and Sunday are excluded from leave day counts.                                                    |
| Leave_Day_Count         | The number of days an Admin_Leave_Request consumes, computed from its date range minus Company_Holiday dates and (optionally) weekends.       |
| Leave_Request_Service   | The backend component that computes Leave_Day_Count and enforces request state transitions.                                                   |
| Holiday_Service         | The backend component that reads, generates, and mutates the Holiday_Calendar.                                                                |
| Admin_Management_Menu   | The new web admin sidebar group containing the request list screen and the holiday screen.                                                    |
| Overlap_Warning         | A non-blocking badge shown on a request whose leave period intersects another `pending` or `approved` request from the same Admin_User.       |
| Employee_Picker         | The existing shared remote-select control (`select-remote` / `DUserSelectRemote`) that searches employees by name or staff number via `admin/users/search`. |

## Requirements

### Requirement 1: Admin Leave Management Permission

**User Story:** As a system administrator, I want a dedicated permission for managing admin leave, so that only designated managers can review requests and edit the company holiday calendar.

#### Acceptance Criteria

1. WHEN the role/permission seeder runs THEN the system SHALL ensure a permission named `admin_leave.manage` exists.
2. WHEN a user without `admin_leave.manage` calls any manager-side leave or holiday endpoint THEN the system SHALL respond `403 Forbidden` and SHALL NOT disclose any request data.
3. WHEN a user without `admin_leave.manage` loads the web admin THEN the Admin_Management_Menu SHALL NOT be rendered in the sidebar.
4. IF a user navigates directly to a URL under the Admin_Management_Menu without `admin_leave.manage` THEN the web admin SHALL render the standard no-permission state instead of the screen content.
5. WHERE the permission list screen is shown THEN `admin_leave.manage` SHALL be assignable to any role like every other permission.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None

### Requirement 2: Submit a Leave Request

**User Story:** As an Admin_User, I want to submit a leave or expense request with the relevant dates, a reason, and supporting files, so that my manager has everything needed to decide.

#### Acceptance Criteria

1. WHEN an Admin_User submits a request THEN Leave_Request_Service SHALL require a Leave_Type drawn from the four defined values and SHALL reject any other value with `422 Unprocessable Entity`.
2. IF Leave_Type is `paid_leave`, `day_off`, or `long_leave` THEN Leave_Request_Service SHALL require both a start date and an end date.
3. IF Leave_Type is `expense_claim` THEN Leave_Request_Service SHALL accept the request with start and end dates omitted, and SHALL accept them when supplied.
4. IF an end date is supplied that is earlier than the start date THEN Leave_Request_Service SHALL reject the request with `422 Unprocessable Entity`.
5. IF Leave_Type is `paid_leave`, `day_off`, or `long_leave` AND the start date is earlier than the current date THEN Leave_Request_Service SHALL reject the request with `422 Unprocessable Entity`; leave may be requested for today or any future date only.
6. IF Leave_Type is `expense_claim` AND dates are supplied THEN Leave_Request_Service SHALL accept dates in the past, because an expense claim documents costs already incurred.
7. IF the span from start date to end date exceeds 366 days THEN Leave_Request_Service SHALL reject the request with `422 Unprocessable Entity`.
8. IF Leave_Type is `expense_claim` AND a start date earlier than 366 days before the current date is supplied THEN Leave_Request_Service SHALL reject the request with `422 Unprocessable Entity`.
9. WHEN an Admin_User submits a request THEN Leave_Request_Service SHALL require a non-empty reason of at most 1000 characters.
10. WHEN a request is created THEN Leave_Request_Service SHALL persist it with Request_Status `pending`, with the authenticated user as the requester, and SHALL record the submission timestamp.
11. IF the caller does not hold `manager.auth` THEN Leave_Request_Service SHALL respond `403 Forbidden` and SHALL NOT create a request.
12. WHEN a request carrying start and end dates is created THEN Leave_Request_Service SHALL compute and persist its Leave_Day_Count per Requirement 5.
13. WHEN a request is created THEN Leave_Request_Service SHALL NOT reject it for overlapping an existing request from the same Admin_User; overlap is surfaced to the Leave_Manager per Requirement 7 instead.
14. WHEN date validation rejects a request THEN Leave_Request_Service SHALL evaluate all supplied dates against the application timezone (`Asia/Tokyo`) rather than UTC.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4 (attachments), Requirement 5 (day count), Requirement 6 (holiday calendar)

### Requirement 3: View Own Leave Requests

**User Story:** As an Admin_User, I want to see the requests I have submitted and their outcomes, so that I know whether my leave was granted.

#### Acceptance Criteria

1. WHEN an Admin_User requests their own request list THEN Leave_Request_Service SHALL return only requests whose requester is the authenticated user, newest submission first, paginated.
2. WHEN the own-request list is returned THEN each entry SHALL include Leave_Type, start date, end date, Leave_Day_Count, reason, attachment count, Request_Status, submission timestamp, and the Decision_Record fields: deciding Leave_Manager's name, decision timestamp, and rejection reason.
3. IF an Admin_User requests the detail of a request they did not submit THEN Leave_Request_Service SHALL respond `403 Forbidden`.
4. IF the caller does not hold `manager.auth` THEN Leave_Request_Service SHALL respond `403 Forbidden`.

#### Additional Details

- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 2

### Requirement 4: Attach Supporting Files

**User Story:** As an Admin_User, I want to attach photos or PDFs to my request, so that I can evidence a medical certificate or an expense receipt.

#### Acceptance Criteria

1. WHEN an Admin_User submits a request THEN Leave_Request_Service SHALL accept between zero and three Leave_Attachment files.
2. IF more than three files are supplied THEN Leave_Request_Service SHALL reject the whole request with `422 Unprocessable Entity` and SHALL NOT create the request.
3. IF a supplied file exceeds 5 MB THEN Leave_Request_Service SHALL reject the whole request with `422 Unprocessable Entity`.
4. IF a supplied file is not `jpg`, `jpeg`, `png`, `webp`, or `pdf` THEN Leave_Request_Service SHALL reject the whole request with `422 Unprocessable Entity`.
5. WHEN a Leave_Attachment is stored THEN Leave_Request_Service SHALL persist its storage disk, path, original file name, MIME type, and byte size.
6. IF request persistence fails after files have been written to storage THEN Leave_Request_Service SHALL delete every file it wrote for that request and SHALL leave no orphaned attachment rows.
7. WHEN a Leave_Manager or the owning requester opens a request detail THEN Leave_Request_Service SHALL return a time-limited URL for each Leave_Attachment.
8. IF a user who is neither the requester nor a Leave_Manager requests an attachment URL THEN Leave_Request_Service SHALL respond `403 Forbidden`.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2

### Requirement 5: Leave Day Calculation

**User Story:** As a Leave_Manager, I want the number of days a request consumes to be computed from the company calendar, so that day counts are consistent and I do not have to check the calendar myself.

#### Acceptance Criteria

1. WHEN a request has both a start date and an end date THEN Leave_Request_Service SHALL count every calendar date from the start date to the end date inclusive.
2. WHEN counting THEN Leave_Request_Service SHALL exclude every date that exists as a Company_Holiday in the Holiday_Calendar.
3. IF the Weekend_Setting for a date's year enables weekend exclusion THEN Leave_Request_Service SHALL also exclude Saturdays and Sundays from the count.
4. IF a date range spans two calendar years THEN Leave_Request_Service SHALL apply each date's own year's Company_Holiday rows and Weekend_Setting.
5. IF a year covered by the date range has no Company_Holiday rows at all THEN Holiday_Service SHALL generate that year from the Japanese holiday library before the count is computed, so a request is never counted against an empty calendar.
6. WHEN on-demand generation runs during a count THEN it SHALL follow the same rules as manual generation in Requirement 6 and SHALL leave the generated rows editable afterwards.
7. IF every date in the range is excluded THEN Leave_Request_Service SHALL persist a Leave_Day_Count of `0` and SHALL still create the request.
8. IF a request has no start and end date THEN Leave_Request_Service SHALL persist a null Leave_Day_Count and the web admin SHALL display `-`.
9. WHEN a Company_Holiday or Weekend_Setting is changed after a request exists THEN the system SHALL leave that request's stored Leave_Day_Count unchanged.
10. WHEN a client requests a day-count preview for a date range THEN Leave_Request_Service SHALL return the same Leave_Day_Count that submitting the request would produce, and SHALL NOT create a request.
11. WHEN a preview is requested THEN Leave_Request_Service SHALL apply the same holiday, weekend, and on-demand-generation rules used at submission, so the previewed number never differs from the stored one.
12. IF the caller does not hold `manager.auth` THEN the preview endpoint SHALL respond `403 Forbidden`.
13. IF the previewed range is reversed or longer than 366 days THEN the preview endpoint SHALL respond `422 Unprocessable Entity`.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 6

### Requirement 6: Manage the Company Holiday Calendar

**User Story:** As a Leave_Manager, I want to maintain the list of company holidays for a year, so that leave day counts reflect our actual non-working days rather than only statutory ones.

#### Acceptance Criteria

1. WHEN a Leave_Manager opens the holiday screen THEN Holiday_Service SHALL return every Company_Holiday for the selected year ordered by date, together with that year's Weekend_Setting.
2. IF no Company_Holiday exists for the selected year THEN the web admin SHALL present an empty state offering to generate the year from the Japanese holiday library.
3. WHEN a Leave_Manager triggers generation for a year THEN Holiday_Service SHALL create a Company_Holiday for each Japanese public holiday of that year, marked as library-sourced.
4. IF generation is triggered for a year that already has Company_Holiday rows THEN Holiday_Service SHALL insert only dates that do not yet exist and SHALL NOT modify or delete existing rows.
5. WHEN a Leave_Manager adds a Company_Holiday THEN Holiday_Service SHALL require a date and a name of at most 255 characters, and SHALL mark the row as manually added.
6. IF a Leave_Manager adds a Company_Holiday on a date that already exists THEN Holiday_Service SHALL reject it with `422 Unprocessable Entity`.
7. WHEN a Leave_Manager edits or deletes a Company_Holiday THEN Holiday_Service SHALL apply the change regardless of whether the row was library-sourced or manually added.
8. WHEN a Leave_Manager toggles the Weekend_Setting for a year THEN Holiday_Service SHALL persist the new value for that year only.
9. IF no Weekend_Setting row exists for a year THEN Holiday_Service SHALL inherit the value from the most recent earlier year that has one, so the setting does not silently reset each January.
10. IF no earlier Weekend_Setting exists at all THEN Holiday_Service SHALL treat weekend exclusion as disabled.
11. WHEN the holiday screen is displayed THEN it SHALL indicate for each Company_Holiday whether it came from the library or was added manually.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 7: Review the Leave Request List

**User Story:** As a Leave_Manager, I want a filterable list of every admin leave request, so that I can find pending items and audit past decisions.

#### Acceptance Criteria

1. WHEN a Leave_Manager opens the request list THEN Leave_Request_Service SHALL return requests from every Admin_User in the system, without scoping by workplace.
2. WHEN the list is returned THEN each row SHALL include requester name, Leave_Type, start date, end date, Leave_Day_Count, reason, submission timestamp, Request_Status, and the Decision_Record fields: deciding Leave_Manager's display name, decision timestamp, and rejection reason.
3. IF a request is still `pending` THEN the list SHALL return null Decision_Record fields and the web admin SHALL render them as `-`.
4. WHEN a date range filter is supplied THEN Leave_Request_Service SHALL return requests whose leave period overlaps that range.
5. IF a request has no leave period THEN Leave_Request_Service SHALL match it against the date range filter using its submission timestamp instead, so an `expense_claim` without dates is never hidden by the default filter.
6. WHEN a requester filter is supplied THEN Leave_Request_Service SHALL return only requests submitted by that Admin_User.
7. WHEN a status filter is supplied THEN Leave_Request_Service SHALL return only requests in that Request_Status.
8. WHEN a Leave_Type filter is supplied THEN Leave_Request_Service SHALL return only requests of that Leave_Type.
9. WHERE the search box is rendered THEN the web admin SHALL expose four filters — date range, requester, Leave_Type, and Request_Status — each with an "all" option except the date range.
10. WHEN no filter is supplied THEN the web admin SHALL default the date range to the current month, and the requester, Leave_Type, and status filters to all.
11. WHEN the list is returned THEN Leave_Request_Service SHALL paginate results and SHALL order them by submission timestamp descending.
12. WHEN a Leave_Manager opens the requester filter THEN the web admin SHALL use the shared remote employee picker backed by `admin/users/search`, matching the User filter on the Work History screen.
13. WHEN a Leave_Manager types into the requester filter THEN Employee_Picker SHALL search by employee name and by staff number, and SHALL label each option as `name (staff_no)`.
14. WHEN Employee_Picker queries the search endpoint THEN it SHALL constrain results to users holding `manager.auth`, so only Admin_Users are selectable.
15. WHEN the page is loaded from a URL that already carries a requester filter THEN Employee_Picker SHALL resolve that identifier back to the employee's name and staff number rather than showing a bare ID.
16. IF a returned request's leave period overlaps another `pending` or `approved` request from the same Admin_User THEN Leave_Request_Service SHALL flag that row as overlapping and the web admin SHALL render an Overlap_Warning badge on it.
17. WHEN the overlap flag is computed THEN Leave_Request_Service SHALL do so without issuing a query per returned row.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Requirement 2

### Requirement 8: Approve or Reject a Request

**User Story:** As a Leave_Manager, I want to approve or reject a pending request with a reason when I refuse it, so that the requester understands the decision and the record shows who decided.

#### Acceptance Criteria

1. WHEN a Leave_Manager approves a `pending` request THEN Leave_Request_Service SHALL set Request_Status to `approved` and SHALL record the deciding user and the decision timestamp.
2. WHEN a Leave_Manager rejects a `pending` request THEN Leave_Request_Service SHALL require a rejection reason of at most 1000 characters, SHALL set Request_Status to `rejected`, and SHALL record the deciding user, the decision timestamp, and the reason.
3. IF a rejection is submitted without a reason THEN Leave_Request_Service SHALL respond `422 Unprocessable Entity` and SHALL leave the request `pending`.
4. IF a decision is submitted for a request that is no longer `pending` THEN Leave_Request_Service SHALL respond `409 Conflict` and SHALL leave the existing decision intact, matching how the fixed-schedule change request flow already signals this case.
5. IF the deciding user is the requester THEN Leave_Request_Service SHALL respond `403 Forbidden` and SHALL leave the request `pending`.
6. WHEN a Leave_Manager clicks reject in the web admin THEN the web admin SHALL open a modal requiring a reason before the decision is sent.
7. WHEN a decision succeeds THEN the web admin SHALL refresh the list so the row shows the new Request_Status, decider, and decision timestamp.
8. WHEN a Decision_Record is written THEN Leave_Request_Service SHALL persist the deciding user by foreign key so the decider remains resolvable after that user's display name changes.
9. IF the deciding user is later disabled or soft-deleted THEN the system SHALL still return that user's name on the Decision_Record rather than an empty value.
10. WHEN a Decision_Record exists THEN the system SHALL NOT permit any endpoint to overwrite or clear it.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 7

### Requirement 9: Inspect Request Details and Attachments

**User Story:** As a Leave_Manager, I want to read the full reason and open the attached files from the list, so that I can decide without leaving the screen.

#### Acceptance Criteria

1. WHEN a Leave_Manager opens a request from the list THEN the web admin SHALL display the full reason text, every Leave_Attachment, and the decision fields in a detail view.
2. WHEN a Leave_Attachment is an image THEN the web admin SHALL render a thumbnail and SHALL open a full-size preview when the thumbnail is clicked.
3. WHEN a Leave_Attachment is a PDF THEN the web admin SHALL offer a link that opens the file in a new browser tab.
4. IF a request has no Leave_Attachment THEN the web admin SHALL show an empty-attachment state rather than an empty area.
5. WHERE the request is `pending` THEN the detail view SHALL expose the approve and reject actions; otherwise it SHALL show the Decision_Record read-only.
6. WHERE the request is `approved` or `rejected` THEN the detail view SHALL display the deciding Leave_Manager's name and the decision timestamp, and SHALL additionally display the rejection reason when the request was rejected.
7. IF the request carries an Overlap_Warning THEN the detail view SHALL name the conflicting request's dates and status, so the Leave_Manager can judge the conflict without leaving the screen.
8. IF the viewing Leave_Manager is the requester THEN the detail view SHALL disable the approve and reject actions and SHALL explain that a request cannot be decided by its own author.

#### Additional Details

- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 4, Requirement 8

## Non-Functional Requirements

### Performance Requirements

- WHEN the request list is loaded with the default filters THEN the API SHALL respond within 1 second for up to 10,000 stored requests.
- WHEN the list is rendered THEN the API SHALL load requester, decider, and attachment counts without issuing per-row queries.
- WHEN the holiday screen is loaded for a year THEN the API SHALL respond within 500 ms.

### Security Requirements

- WHEN any leave or holiday endpoint is called without a valid session THEN the system SHALL respond `401 Unauthorized`.
- WHEN an attachment is requested THEN the system SHALL serve it only through a time-limited URL and SHALL NOT expose a permanently public path.
- WHEN a file is uploaded THEN the system SHALL validate MIME type and extension server-side and SHALL store the file outside the public web root.
- WHEN a request is decided THEN the system SHALL record the deciding user's identity immutably.

### Usability Requirements

- WHEN any user-facing text is rendered in the web admin THEN it SHALL come from the translation layer with English, Vietnamese, and Japanese entries.
- WHEN a Leave_Type is displayed THEN the web admin SHALL show the translated name for the viewer's locale followed by the Japanese term in parentheses (e.g. `Nghỉ phép năm (有休)`), so the project translation rules are respected while the label still matches what admins see in the mobile app.
- WHEN a filter is applied THEN the web admin SHALL reflect it in the URL query string so the filtered view can be bookmarked and shared, matching the existing absent-notice screen.

### Reliability Requirements

- WHEN request creation fails part-way THEN the system SHALL roll back the database transaction and SHALL delete any files already written to storage.
- WHEN two Leave_Managers decide the same request concurrently THEN the system SHALL apply exactly one decision by conditioning the status update on the request still being `pending`.
- WHEN holiday generation fails part-way THEN the system SHALL roll back so the year is left either fully generated or untouched.

## Constraints and Assumptions

### Technical Constraints

- The backend is Laravel 13 on PHP 8.5; all business logic must follow the project's Action pattern with classes imported via `use` statements.
- The web admin is Next.js 15 with Mantine; screens must reuse `CommonSearchBox`, `DDataTable`, and `usePermissions` as the existing absent-notice screen does.
- The requester filter must reuse the existing `select-remote` element and `admin/users/search` endpoint rather than introducing a new picker; that endpoint already accepts `q`, `staff_no`, `key`, and `permission`, so no new search API is required.
- Japanese holiday generation requires adding `azuyalabs/yasumi` to the backend Composer dependencies; it is not currently installed.
- Attachment storage must use the existing Laravel filesystem configuration rather than a new storage mechanism.
- Permission checks must go through the existing `permission:` route middleware.

### Assumptions

- The set of Admin_Users is defined solely by holding `manager.auth`; no separate leader/manager hierarchy exists or is introduced.
- A Leave_Manager may act on any admin's request regardless of workplace, because the company operates a single approval group.
- At least two users hold `admin_leave.manage`. Because self-approval is forbidden, a single holder would be unable to have their own requests decided; this is an operational constraint on role assignment, not something the system enforces.
- `long_leave` (長期休み) and `day_off` (休み) differ only in label. They share the same fields, validation, and day counting; no minimum duration distinguishes them.
- The application timezone is `Asia/Tokyo`, so "today" in date validation and holiday matching means the Japanese calendar day.
- Requests and their attachments are retained indefinitely; no archival or purge policy is defined at this stage.
- 経費申請 amounts are conveyed in the reason text and receipts; no structured monetary field is needed at this stage.
- Requests are immutable after submission; a mistaken request is rejected and re-submitted rather than edited.
- The Yasumi library's Japan provider is an acceptable statutory baseline that managers will adjust for company-specific closures.

## Success Criteria

### Definition of Done

- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] `admin_leave.manage` is seeded and gates both new screens and every manager-side endpoint
- [ ] An Admin_User can create, list, and view their own requests through the API, including attachments
- [ ] A Leave_Manager can filter, inspect, approve, and reject requests from the web admin
- [ ] Holiday generation from Yasumi, manual holiday CRUD, and the weekend toggle all work per year
- [ ] Leave day counts exclude holidays and, when enabled, weekends — verified across a year boundary and against a year with no calendar generated yet
- [ ] An `expense_claim` submitted without dates is visible under the default current-month filter
- [ ] Backend feature and unit tests cover permissions, validation, day calculation, decision transitions, and upload rollback
- [ ] Frontend tests cover the list screen, filters, reject modal, and the no-permission state
- [ ] All user-facing strings are translated to Vietnamese and Japanese via the project translation flow
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` pass in `frontend/`; `npm run prettier`, `npm run pint`, and `php artisan test --parallel` pass in `backend/`
