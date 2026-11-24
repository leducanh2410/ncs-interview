# Requirements Document

## Introduction

This feature adds a mandatory document read-and-confirmation system to DGF Connect. Administrators can configure required reading documents at two levels: system-wide (applies to all employees upon first paper approval) and per-workplace (applies to employees upon their first approved shift at that workplace). The mobile app receives push notifications at the moment the condition is met, and upon login returns any outstanding confirmations as a non-dismissible popup. Confirmations are stored with audit details (timestamp, IP, device info) and persist as long as the parent config exists; deleting a config cascade-deletes its confirmations. A lightweight boolean Pending_Flag on the User record acts as a DB-level cache to minimize query overhead on the login path.

### Feature Summary

Employees are required to read and confirm designated documents at system-wide and per-workplace levels; confirmations are enforced via mobile popups and stored as audit records (cascade-deleted with their parent config).

### Business Value

- Provides legal proof that employees have read important company and workplace policies.
- Ensures no employee slips through without acknowledging required documents when they first join or first work at a new site.
- Reduces administrative burden by automating notification and tracking rather than relying on manual sign-off processes.

### Scope

**Included:**
- Admin UI for creating/editing/deactivating system-wide and per-workplace Document_Read_Configs.
- Admin UI for viewing confirmation audit records across both config types.
- Automatic push notification when the triggering event occurs (paper approval, first shift approval).
- Login API enhancement to return pending document list based on Pending_Flag.
- Confirmation API with audit fields (IP, device info).
- Pending_Flag on User as a DB-level cache to short-circuit login checks.
- Optional admin-triggered backfill job for existing eligible employees (opt-in, not automatic).

**Excluded:**
- Mobile popup UI (handled by mobile team based on API contract defined here).
- Web/admin UI for viewing a document (mobile-only flow; admins can view via existing document management).
- Automated reminders or escalations after initial notification.
- Versioning of documents (confirmation validity is not affected by document updates).
- Custom per-user exemptions from required documents.

---

## Glossary

| Term | Definition |
|------|------------|
| Document_Read_Config | A configuration record that designates a specific Document as required reading, scoped to either the entire system or a specific workplace |
| System_Wide_Config | A Document_Read_Config with scope = 'system', applied to all employees upon first paper approval |
| Workplace_Config | A Document_Read_Config with scope = 'workplace', applied to employees upon their first approved shift at a specific workplace |
| Read_Confirmation | An audit record proving a specific employee confirmed reading a required Document_Read_Config. Deleted if the parent config is deleted (cascade). |
| Pending_Documents | Active Document_Read_Configs applicable to an employee for which no Read_Confirmation exists |
| Pending_Flag | A boolean field (`has_pending_documents`) on the User record used as a DB-level cache; true means the system should query for Pending_Documents |
| Paper_Approval | The event when an employee's `paper_status` field transitions to "approved" for the first time, triggering System_Wide_Config requirements |
| Workplace_First_Shift | The event when an employee's first shift assignment at a particular workplace is approved (via `ShiftUserApproved` event), triggering Workplace_Config requirements |
| Push_Notification | An FCM push notification sent to the employee's registered mobile devices via the existing notification infrastructure |
| Popup | A mandatory, non-dismissible overlay displayed on the mobile app requiring employee confirmation before any other action |
| Multilingual_Title | A set of three title strings for a Document_Read_Config, one each in Vietnamese (`vi`), English (`en`), and Japanese (`ja`) |

---

## Requirements

### Requirement 1: System-Wide Document Read Configuration

**User Story:** As a Global Admin, I want to configure multiple required reading documents at the system level with multilingual titles and a link to an existing document, so that all new employees must confirm reading them upon first paper approval.

#### Acceptance Criteria

1. WHEN the Global Admin navigates to the system configuration panel THEN Document_Read_Config_Manager SHALL display a dedicated section for managing system-wide Document_Read_Configs.
2. WHEN the Global Admin creates or edits a System_Wide_Config THEN Document_Read_Config_Manager SHALL require: a Multilingual_Title (Vietnamese, English, Japanese), a reference to an existing Document in the system, and an active/inactive status. The `document_id` SHALL be immutable once any Read_Confirmation exists for the config (to prevent old confirmations from being misattributed to a different document).
3. WHEN the Global Admin deactivates a System_Wide_Config THEN Document_Read_Config_Manager SHALL preserve all existing Read_Confirmations for that config. WHEN the Global Admin deletes a System_Wide_Config THEN the system SHALL cascade-delete all associated Read_Confirmations.
4. IF a referenced Document is updated THEN Document_Read_Config_Manager SHALL NOT invalidate existing Read_Confirmations for that config.
5. IF a referenced Document is deleted THEN the system SHALL set the config's `document_id` to NULL (SET NULL FK) and auto-deactivate the config (`is_active = false`), so that no employee is prompted to read a non-existent document.
6. WHERE System_Wide_Configs are listed THEN Document_Read_Config_Manager SHALL display each config's status, Multilingual_Title, and the linked Document's title.
7. WHILE a System_Wide_Config is marked inactive THEN Notification_Service SHALL NOT include that config when evaluating Pending_Documents for any employee.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Existing Document management system

---

### Requirement 2: Per-Workplace Document Read Configuration

**User Story:** As a WorkplaceAdmin or higher, I want to configure multiple required reading documents per workplace with multilingual titles, so that employees must confirm reading them the first time they work at that specific workplace.

#### Acceptance Criteria

1. WHEN a WorkplaceAdmin accesses the unified document read config management page THEN Document_Read_Config_Manager SHALL allow managing per-workplace Document_Read_Configs scoped to workplaces they administer.
2. WHEN the WorkplaceAdmin creates or edits a Workplace_Config THEN Document_Read_Config_Manager SHALL require: a Multilingual_Title (Vietnamese, English, Japanese), a reference to an existing Document, and an active/inactive status. The `document_id` SHALL be immutable once any Read_Confirmation exists for the config.
3. WHEN the WorkplaceAdmin deactivates a Workplace_Config THEN Document_Read_Config_Manager SHALL preserve existing Read_Confirmations for that config. WHEN the WorkplaceAdmin deletes a Workplace_Config THEN the system SHALL cascade-delete all associated Read_Confirmations.
4. WHERE Workplace_Configs are displayed THEN Document_Read_Config_Manager SHALL scope results to workplaces the admin manages. On the unified config page, a WorkplaceAdmin sees system-wide configs (read-only) plus configs for all workplaces they administer.
5. IF a WorkplaceAdmin attempts to access Document_Read_Configs for a workplace they do not manage THEN Document_Read_Config_Manager SHALL deny access with a 403 response.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Workplace management and permission system

---

### Requirement 3: System-Wide Confirmation Trigger on Paper Approval

**User Story:** As the system, I want to automatically notify employees and set their Pending_Flag when their paper status is first approved, so that they are immediately prompted to read all active system-wide required documents.

#### Acceptance Criteria

1. WHEN an employee's `paper_status` transitions to "approved" for the first time THEN Notification_Service SHALL query all active System_Wide_Configs and check which ones the employee has not yet confirmed.
2. IF unconfirmed active System_Wide_Configs exist THEN Notification_Service SHALL set the employee's Pending_Flag to `true` on the User record.
3. WHEN the Pending_Flag is set to `true` THEN Notification_Service SHALL send a Push_Notification to all of the employee's registered FCM devices indicating there are documents requiring confirmation.
4. IF no active System_Wide_Configs exist at the time of paper approval THEN Notification_Service SHALL NOT modify the Pending_Flag or send any Push_Notification.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Paper approval workflow, FCM notification infrastructure

---

### Requirement 4: Per-Workplace Confirmation Trigger on First Shift Approval

**User Story:** As the system, I want to automatically notify employees when their first shift at a workplace is approved, so that they are prompted to read all active workplace-specific required documents.

#### Acceptance Criteria

1. WHEN a `ShiftUserApproved` event fires for a given employee and workplace THEN Notification_Service SHALL check whether this is the employee's first-ever approved shift at that workplace.
2. IF this is the employee's first approved shift at that workplace AND active Workplace_Configs for that workplace exist with no existing Read_Confirmations from this employee THEN Notification_Service SHALL set the employee's Pending_Flag to `true`.
3. WHEN the Pending_Flag is set to `true` THEN Notification_Service SHALL send a Push_Notification to all of the employee's registered FCM devices.
4. IF the employee already has at least one prior approved shift at the same workplace THEN Notification_Service SHALL NOT treat this as a first-shift event and SHALL NOT send a Push_Notification for this workplace's configs.
5. IF no active Workplace_Configs exist for the workplace at the time of the event THEN Notification_Service SHALL NOT modify the Pending_Flag or send any Push_Notification.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 2, `ShiftUserApproved` event, FCM notification infrastructure

---

### Requirement 5: New Config Backfill for Existing Eligible Employees

**User Story:** As a Global Admin or WorkplaceAdmin, I want to optionally notify existing eligible employees when I create a new required document configuration, so that employees who joined or worked at a site before the config was created are not excluded.

#### Acceptance Criteria

1. WHEN a new Document_Read_Config is created or activated THEN the system SHALL NOT automatically backfill existing employees. Backfill is disabled by default.
2. WHEN the admin requests to enable backfill for a config THEN Config_Service SHALL first return the count of affected employees (paper-approved users for system-wide, users with approved shifts for workplace-scoped) so the admin can make an informed decision.
3. WHEN the admin confirms backfill THEN the Admin_Panel SHALL display a confirmation modal clearly stating: "This will require X employees to read and confirm this document."
4. IF the admin attempts to trigger backfill on an inactive config THEN Config_Service SHALL reject the request with a 422 error. Backfill is only allowed on active configs.
5. WHEN the admin confirms the modal THEN Config_Service SHALL enqueue an asynchronous job to set the Pending_Flag to `true` for all eligible employees who have no existing Read_Confirmation for that config.
6. WHEN the Pending_Flag is set by the backfill job THEN Notification_Service SHALL send Push_Notifications (containing full pending document data) to affected employees' FCM devices.
7. WHILE the backfill job is running THEN Config_Service SHALL process user updates in configurable batches (default: 100 users per batch) to avoid locking the database or exhausting memory.

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirements 1, 2, 3, 4, Laravel Queue system

---

### Requirement 6: Login-Time Pending Document Check

**User Story:** As a mobile app user, I want any outstanding required document confirmations to be surfaced immediately upon login, so that I am always prompted to fulfill reading requirements even if I missed the push notification.

#### Acceptance Criteria

1. WHEN an employee successfully authenticates via the mobile login API THEN Auth_Service SHALL read the employee's Pending_Flag.
2. IF the Pending_Flag is `false` THEN Auth_Service SHALL return an empty `pending_documents` array in the login response with no additional queries.
3. IF the Pending_Flag is `true` THEN Auth_Service SHALL query all active Document_Read_Configs (system-wide and applicable workplace) for which the employee has no Read_Confirmation, and return them in the `pending_documents` field of the login response.
4. WHEN the query finds zero pending documents (e.g., all configs were deactivated after the flag was set) THEN Auth_Service SHALL set the Pending_Flag to `false` and return an empty `pending_documents` array.
5. WHERE pending documents are returned THEN Auth_Service SHALL include for each entry: `id`, `workplace_id` (nullable; null = system-wide), `title_vi`, `title_en`, `title_ja`, and the linked Document's URL, ordered by config `id` ascending.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 3, 4, 5; Mobile login API

---

### Requirement 7: Confirmation Recording and Audit

**User Story:** As a compliance administrator, I want every employee confirmation to be stored with full audit details, so that there is proof that the employee acknowledged required documents. Confirmations persist as long as the parent config exists.

#### Acceptance Criteria

1. WHEN an employee taps the confirm button on a required document Popup THEN Confirmation_Service SHALL call the confirmation API with the `document_required_config_id`.
2. WHEN the API receives a confirmation request THEN Confirmation_Service SHALL create a Read_Confirmation record containing: `user_id`, `document_required_config_id`, `ip_address`, and `device_info` (JSON with device model, OS, app version). The `created_at` timestamp serves as the confirmation timestamp.
3. IF the Document_Read_Config is inactive at the time of confirmation THEN Confirmation_Service SHALL return success without creating a record (no-op).
4. IF a Read_Confirmation already exists for the same (`user_id`, `document_required_config_id`) pair THEN Confirmation_Service SHALL return success without creating a duplicate record.
5. WHEN all Pending_Documents for an employee are confirmed THEN Confirmation_Service SHALL set the employee's Pending_Flag to `false`.
6. WHERE Read_Confirmations are stored THEN Admin_Panel SHALL provide a single paginated view for all configs (both system-wide and per-workplace), showing which employees confirmed which configs, with timestamp and device info visible. WorkplaceAdmins SHALL see confirmations for system-wide configs and for configs belonging to workplaces they manage; GlobalAdmins SHALL see all confirmations.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirements 3, 4, 5

---

## Non-Functional Requirements

### Performance Requirements
- WHEN the mobile login API processes authentication THEN Auth_Service SHALL complete the Pending_Flag check in under 50ms by reading a single indexed boolean column before issuing any further queries.
- WHEN the admin triggers a backfill THEN Config_Service SHALL dispatch it as an asynchronous queued job and SHALL NOT block the HTTP request.
- WHEN the backfill job sets Pending_Flags for many users THEN Config_Service SHALL use batch DB updates (chunked queries) and throttled FCM sends to avoid memory spikes or rate-limit errors.

### Security Requirements
- WHEN an employee submits a confirmation THEN Confirmation_Service SHALL validate that the authenticated user matches the `user_id` being confirmed (no proxy confirmation allowed).
- WHEN confirmation records are persisted THEN Database SHALL store IP address and device info immutably as audit evidence.
- WHEN Document_Read_Config management endpoints are called THEN API SHALL enforce role-based access: system-wide endpoints require GlobalAdmin role; workplace endpoints require WorkplaceAdmin role or higher scoped to that workplace.

### Usability Requirements
- WHEN the admin triggers backfill THEN Admin_Panel SHALL display a confirmation modal with the exact count of affected employees before proceeding.

### Reliability Requirements
- WHEN a Push_Notification fails to deliver to an employee's device THEN Notification_Service SHALL leave the Pending_Flag as `true` so the login-time check acts as a fallback.
- WHEN the Pending_Flag is `true` but the query finds zero actual Pending_Documents THEN Auth_Service SHALL self-heal by resetting the Pending_Flag to `false` and returning an empty list.
- WHEN the Pending_Flag is `false` but unconfirmed active configs exist (stale-false), the admin-triggered backfill serves as the repair mechanism. Admins can re-trigger backfill for any config at any time to catch employees whose flag was not set due to a failed job or listener.

---

## Constraints and Assumptions

### Technical Constraints
- The notification system uses FCM (Firebase Cloud Messaging) via the existing `user_devices` table with `fcm_token` fields.
- Documents referenced in configs must already exist in the system's Document management; this feature does not create new document storage.
- The three supported locales are Vietnamese (`vi`), English (`en`), and Japanese (`ja`), matching the existing `Locale` enum.
- The backend is Laravel 11 with MySQL; the frontend is Next.js 15 + Mantine UI (admin config UI only; mobile popup is handled by the mobile team).

### Assumptions
- The mobile app team will implement the popup UI based on the API contract defined here.
- An employee may have multiple registered devices; Push_Notifications are sent to all of them.
- The Pending_Flag does not need to distinguish between system-wide vs. workplace pending documents — a single boolean is sufficient as a cache hint.
- Confirmations persist as long as the parent config exists; deleting a config cascade-deletes its confirmations.
- The number of active Document_Read_Configs per level (system-wide or per-workplace) is expected to be small (< 20), so the query when Pending_Flag is true is lightweight.

---

## Success Criteria

### Definition of Done
- [ ] Admin UI provides a single screen to manage both system-wide and per-workplace Document_Read_Configs with multilingual titles.
- [ ] Admin UI provides a single screen to view confirmation audit records across both config types.
- [ ] Paper approval event correctly triggers Pending_Flag + Push_Notification for active System_Wide_Configs.
- [ ] First shift approval event correctly triggers Pending_Flag + Push_Notification for active Workplace_Configs.
- [ ] Admin can optionally trigger backfill for a config, with a confirmation modal showing the affected employee count.
- [ ] Login API returns pending document list (ordered by ID) when Pending_Flag is true; returns empty list (and resets flag) when no actual pending docs exist.
- [ ] Confirmation API persists Read_Confirmation with audit fields (IP, device info) and resets Pending_Flag when all docs are confirmed.
- [ ] Confirmation of inactive config returns success without creating a record.
- [ ] All backend endpoints are covered by unit and integration tests.
- [ ] Code passes `npm run prettier`, `npm run pint` (backend) and `pnpm prettier`, `pnpm typecheck` (frontend).
