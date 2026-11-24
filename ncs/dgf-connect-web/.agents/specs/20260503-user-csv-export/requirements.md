# Requirements Document

## Introduction

This feature adds a User CSV Export module that allows administrators to export user data into a single CSV file from the user list screen. Unlike PDF export — which produces one PDF per user from a visual template — CSV export produces one consolidated CSV file containing one row per user, with admin-configurable columns. The set of available columns mirrors the fields shown on the user detail "Information" tab. Before exporting, administrators choose which properties to include and reorder them via a modal; this configuration is persisted in localStorage so it is restored on the next export. The feature also extends the existing Export History module to surface both PDF and CSV batches in a single page filterable by export type, and relocates the Export History sidebar entry from the "PDF Template" group to the "User" group to reflect its new cross-format scope.

### Feature Summary

A user list CSV export that produces one consolidated file with admin-selected, reorderable columns persisted in localStorage, integrated into the existing async export batch system and the unified Export History view.

### Business Value

- Enables administrators to extract user records into a spreadsheet-friendly format for analysis, reporting, and downstream system imports
- Reduces ad-hoc data extraction requests by giving admins self-service column selection
- Consolidates export tracking under a single history page, simplifying operations and reducing navigation friction

### Scope

**Included:**
- New "Export CSV" button on the admin user list screen, alongside the existing "Export PDF" button
- Property selection modal with reorderable list, select/deselect, last-used persistence, and "Reset to default" button
- Single CSV file output (UTF-8 with BOM, CRLF, quoted cells) covering all selected users in one file
- Selected user resolution via the same `select_all + filters` model used by PDF export (no row cap)
- Async batch processing via the existing `ExportBatch` table with a new `type` column (`pdf` | `csv`)
- New `USER_EXPORT_CSV` permission gating both the trigger and the property modal
- Unified Export History page filterable by type (All / PDF / CSV); page moved from "PDF Template" sidebar group to "User" sidebar group with route `/admin/user/export-history`
- Translated display values for enums, formatted dates, "Yes"/"No" for booleans
- Japanese CSV header row (always Japanese, regardless of admin UI language)
- S3 storage of generated CSV with the same signed-download mechanism as PDFs

**Excluded:**
- Multiple named CSV configuration presets (only last-used is persisted)
- Server-side saved presets (localStorage only)
- Column language toggle in the modal (header is always Japanese)
- CSV import (this is export-only)
- Per-user batch items for CSV (the CSV is a single artifact)
- A column count limit or row count limit
- Excel-specific formats (.xlsx)

## Glossary

| Term | Definition |
|------|------------|
| User_Csv_Export | The end-to-end feature: selecting properties, generating a CSV containing matching users, and tracking the export in history |
| Property_Selection_Modal | The dialog shown when an administrator clicks "Export CSV" — lists all available properties with checkboxes and drag-to-reorder controls |
| Csv_Property | A single column candidate corresponding to one user/user_info field on the user detail Information tab |
| Csv_Export_Config | The persisted `{ selected: string[], order: string[] }` object describing which properties are included and in what order; saved per browser in localStorage |
| Default_Csv_Config | The hardcoded fallback config used when localStorage has no value or when the admin clicks "Reset to default" — all properties selected, in user_form.tsx display order |
| Export_Batch | Existing async batch record (one row per export run); now extended with a `type` column distinguishing PDF and CSV |
| Export_Type | Enum with values `pdf` and `csv`, stored on `Export_Batch.type` |
| Export_History_Page | The unified admin page listing all `Export_Batch` rows of both types, filterable by `Export_Type` |
| Csv_Export_Job | The Laravel queued job that resolves users, generates CSV rows, uploads to S3, and updates `Export_Batch` status |
| User_Information_Tab | The tab on `frontend/src/pages/admin/user/[id].page.tsx` whose `user_form.tsx` defines the canonical field list used as `Csv_Property` source |

## Requirements

### Requirement 1: CSV Export Trigger from User List

**User Story:** As an administrator with `USER_EXPORT_CSV` permission, I want to trigger a CSV export from the user list screen, so that I can extract data for the users I need without leaving the screen.

#### Acceptance Criteria

1. WHEN an administrator with `USER_EXPORT_CSV` permission views the user list screen THEN the user list screen SHALL display an "Export CSV" button next to the existing "Export PDF" button
2. IF the administrator does not have `USER_EXPORT_CSV` permission THEN the user list screen SHALL NOT render the "Export CSV" button
3. WHEN the administrator clicks "Export CSV" THEN the system SHALL open the Property_Selection_Modal
4. WHEN the administrator has selected one or more users via the row checkboxes AND clicks "Export CSV" THEN Property_Selection_Modal SHALL display the count of selected users (e.g., "Exporting for 12 selected users")
5. WHEN the administrator has used "Select all matching filters" AND clicks "Export CSV" THEN Property_Selection_Modal SHALL display the total filtered count (e.g., "Exporting for all 540 users matching current filters")
6. IF no users are selected AND no select-all is active THEN the user list screen SHALL keep the "Export CSV" button enabled but Property_Selection_Modal SHALL disable the final "Export" button with a message explaining no users are selected

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Existing user list screen, existing select_all + filters selection model used by Export PDF

### Requirement 2: Property Selection Modal

**User Story:** As an administrator, I want to select which user properties to include in the CSV and arrange them in the order I want, so that the file matches the format I or my downstream consumers need.

#### Acceptance Criteria

1. WHEN Property_Selection_Modal opens THEN Property_Selection_Modal SHALL render a single flat reorderable list of all Csv_Property entries available
2. WHEN Property_Selection_Modal renders the list THEN each row SHALL display a checkbox (selected/deselected), the property's translated label, and a drag handle for reordering
3. WHEN the administrator toggles a row's checkbox THEN Property_Selection_Modal SHALL update the local in-modal state to include or exclude that property
4. WHEN the administrator drags a row THEN Property_Selection_Modal SHALL update the order of the property in the in-modal state, regardless of whether the row is currently selected or deselected
5. WHEN the administrator clicks "Reset to default" THEN Property_Selection_Modal SHALL replace the current in-modal state with Default_Csv_Config (all properties selected, in user_form.tsx display order)
6. IF zero properties are selected THEN Property_Selection_Modal SHALL disable the "Export" button and display a validation message
7. WHEN the administrator closes the modal without exporting THEN Property_Selection_Modal SHALL discard the in-modal state without persisting it
8. WHEN the administrator clicks "Export" THEN Property_Selection_Modal SHALL persist the current selection and order to localStorage as Csv_Export_Config (under a stable key) and submit the export
9. WHEN Property_Selection_Modal opens THEN Property_Selection_Modal SHALL load the in-modal state from localStorage if Csv_Export_Config exists, otherwise from Default_Csv_Config

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

### Requirement 3: Property List Source

**User Story:** As an administrator, I want the property list to match what I see on a user's Information tab, so that I have predictable, complete coverage of user data.

#### Acceptance Criteria

1. WHEN Property_Selection_Modal builds its list THEN the system SHALL include exactly the fields defined on User_Information_Tab in `frontend/src/pages/admin/user/partials/user_form.tsx` (initialValues), in their displayed order
2. WHERE the canonical list is defined THEN the system SHALL include the following 31 properties in this default order: `first_name`, `last_name`, `first_name_kana`, `last_name_kana`, `birthday`, `gender`, `nationality`, `phone`, `emergency_contact_phone`, `email`, `postal_code`, `prefecture`, `address`, `registered_location`, `staff_no`, `japanese_level`, `residence_number_1`, `residence_expiry_date_1`, `residence_status_1`, `residence_number_2`, `residence_expiry_date_2`, `residence_status_2`, `residence_number_3`, `residence_expiry_date_3`, `residence_status_3`, `passport_number`, `passport_expiry_date`, `student_card_expiry_date`, `is_household_head`, `facebook_link`, `line_link`
3. WHEN a new field is later added to User_Information_Tab THEN the system SHALL require a corresponding addition to the CSV property registry (single source of truth on the backend)
4. WHEN the frontend renders a property label THEN the system SHALL use the same translation keys used in `user_form.tsx` for that field

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 2, existing user_form.tsx

### Requirement 4: Cell Value Formatting

**User Story:** As an administrator, I want CSV values to be human-readable, so that I can open the file in a spreadsheet without further translation or formatting work.

#### Acceptance Criteria

1. WHEN a Csv_Property is an enum (gender, nationality, japanese_level, residence_status_1/2/3) THEN Csv_Export_Job SHALL render the translated display text from the corresponding Enum (using the Japanese translation, since headers are always Japanese)
2. WHEN a Csv_Property is a date (birthday, residence_expiry_date_1/2/3, passport_expiry_date, student_card_expiry_date) THEN Csv_Export_Job SHALL render the value formatted as `YYYY/MM/DD`
3. WHEN a Csv_Property is `is_household_head` THEN Csv_Export_Job SHALL render `はい` if true, `いいえ` if false, and an empty string if null
4. WHEN a Csv_Property is a plain string field THEN Csv_Export_Job SHALL render the raw stored value as-is
5. IF the resolved value is null or missing THEN Csv_Export_Job SHALL render an empty cell (not the string "null" or a dash)
6. WHEN any cell contains a comma, double quote, or newline THEN Csv_Export_Job SHALL escape the cell per RFC 4180 (wrap in double quotes, double any embedded quote)

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 3, existing Enum classes

### Requirement 5: CSV File Format

**User Story:** As an administrator, I want the generated CSV to open correctly in Japanese-locale Excel, so that I do not need to manually convert encodings.

#### Acceptance Criteria

1. WHEN Csv_Export_Job writes the file THEN the file SHALL be encoded as UTF-8 with a leading BOM (`EF BB BF`)
2. WHEN Csv_Export_Job writes the file THEN line endings SHALL be CRLF (`\r\n`)
3. WHEN Csv_Export_Job writes the header row THEN headers SHALL always be in Japanese, regardless of the administrator's UI locale
4. WHEN Csv_Export_Job writes a row THEN the exported columns SHALL be the submitted selected properties, ordered according to the submitted Csv_Export_Config order
5. WHEN Csv_Export_Job writes the file THEN cells SHALL conform to RFC 4180 (field delimiter `,`, quote character `"`)
6. WHEN the file name is generated THEN it SHALL follow the pattern `users_export_{YYYYMMDD_HHmmss}.csv`

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 4

### Requirement 6: Async Batch Processing (Reusing Export_Batch)

**User Story:** As an administrator, I want CSV export to run in the background like PDF export, so that I do not have to keep my browser open and so the system can handle large user sets.

#### Acceptance Criteria

1. WHEN the administrator submits the Property_Selection_Modal THEN the system SHALL create one Export_Batch row with `type = csv`, `status = pending`, and store the submitted Csv_Export_Config (selected properties + order) on the batch
2. WHEN Export_Batch is created THEN the system SHALL also store the resolved set of target user IDs OR the `select_all + filters` payload, sufficient for the job to resolve users at run time
3. WHEN the system creates the Export_Batch THEN the system SHALL dispatch Csv_Export_Job onto the existing queue
4. WHEN Csv_Export_Job starts THEN it SHALL transition the Export_Batch status to `processing`
5. WHEN Csv_Export_Job completes successfully THEN it SHALL upload the file to S3, store the path on the Export_Batch, and set status to `completed`
6. IF Csv_Export_Job fails for any reason THEN the Export_Batch SHALL be marked `failed` with an error message, and no partial CSV file SHALL be persisted to S3
7. WHEN S3 upload fails THEN Csv_Export_Job SHALL retry up to 3 times before marking the batch as failed (consistent with existing PDF reliability rules)
8. WHEN the administrator submits a CSV export THEN the system SHALL respond immediately with the new batch ID and a confirmation toast linking to the batch detail page, matching the existing PDF export response-time behavior
9. WHEN Export_Batch.type = csv THEN per-user Export_Batch_Item rows SHALL NOT be created (the CSV is a single artifact, no per-user item tracking)
10. WHEN Export_Batch.type = csv THEN the `total_count` SHALL reflect the number of users included in the CSV; `completed_count` SHALL equal `total_count` on success; `failed_count` SHALL be 0 on success

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Existing Export_Batch table, existing queue infrastructure

### Requirement 7: Unified Export History Page

**User Story:** As an administrator, I want a single Export History page that shows both PDF and CSV exports filterable by type, so that I do not have to look in two places to track my exports.

#### Acceptance Criteria

1. WHEN the administrator opens the Export_History_Page THEN the page SHALL display all Export_Batch rows (both `type = pdf` and `type = csv`) in a single table, ordered by created_at descending
2. WHEN the table renders THEN it SHALL display a "Type" column showing a badge with `PDF` or `CSV`
3. WHEN the page renders the search box THEN it SHALL include a "Type" select filter with options: All / PDF / CSV
4. IF the administrator selects "PDF" or "CSV" in the Type filter THEN Export_History_Page SHALL show only batches matching the selected type
5. WHEN the administrator selects "PDF" THEN the existing template_id filter SHALL remain available; WHEN the administrator selects "CSV" THEN the template_id filter SHALL be hidden or disabled (CSV has no template)
6. WHEN the table renders a row of `type = csv` THEN the "Template" column SHALL display `-` (CSV has no associated template)
7. WHEN the administrator clicks "View" on a `type = csv` row THEN the system SHALL navigate to a CSV-specific batch detail page (or a unified detail page that adapts its rendering by type)
8. WHEN the CSV batch detail page renders THEN it SHALL display the batch metadata (status, exported_by, total_count, created_at) and a "Download CSV" button (enabled only if status = completed)
9. WHEN the administrator clicks "Download CSV" THEN the system SHALL provide a signed S3 URL with expiration to download the file
10. WHEN a CSV batch is in progress (pending/processing) THEN Export_History_Page SHALL refresh its row to reflect updated status (consistent with existing polling/refresh pattern)

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 6, existing Export_History page

### Requirement 8: Sidebar Reorganization

**User Story:** As an administrator, I want Export History to live under the "User" sidebar group, so that the menu structure reflects that exports are now user-data-centric, not PDF-template-centric.

#### Acceptance Criteria

1. WHEN the sidebar renders THEN the "Export history" entry SHALL appear inside the "User" sidebar group, after "User list" and "Face registration"
2. WHEN the sidebar renders THEN the "PDF Template" sidebar group SHALL no longer contain the "Export history" entry; only "Template list" SHALL remain
3. WHEN the administrator clicks the moved "Export history" entry THEN the system SHALL navigate to the unified Export_History_Page (Requirement 7)
4. WHEN the administrator visits the breadcrumb of Export_History_Page or its detail pages THEN breadcrumbs SHALL reflect the new location (Home → Users → Export history)

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 7

### Requirement 9: Permissions

**User Story:** As a system administrator, I want a dedicated CSV export permission so I can grant CSV access to roles that should not have PDF export access.

#### Acceptance Criteria

1. WHEN the system seeds permissions THEN it SHALL register a new backend permission enum case `PermissionName::UserExportCsv` with value `user.csv.export` alongside existing user-related permissions
2. IF a user does not have `USER_EXPORT_CSV` THEN the system SHALL hide the "Export CSV" button on the user list and SHALL return 403 if the CSV export endpoint is invoked directly
3. WHEN a user has `USER_EXPORT_CSV` but lacks `PDF_TEMPLATE_LIST` THEN the user SHALL be able to open Export_History_Page and SHALL see CSV rows only
4. WHEN a user has `PDF_TEMPLATE_LIST` but lacks `USER_EXPORT_CSV` THEN the user SHALL be able to open Export_History_Page and SHALL see PDF rows only
5. WHEN a user has both `PDF_TEMPLATE_LIST` and `USER_EXPORT_CSV` THEN the user SHALL see both PDF and CSV rows by default and SHALL be able to filter by type
6. WHEN a user has neither `PDF_TEMPLATE_LIST` nor `USER_EXPORT_CSV` THEN the system SHALL hide the "Export history" sidebar entry and SHALL return 403 from the Export_History_Page API
7. WHEN a user lacks `PDF_TEMPLATE_EXPORT` THEN PDF download links SHALL be hidden or disabled and direct PDF download endpoint calls SHALL return 403
8. WHEN a user lacks `USER_EXPORT_CSV` THEN CSV download links SHALL be hidden or disabled and direct CSV download endpoint calls SHALL return 403
9. WHEN a user opens a PDF batch detail page THEN access SHALL require `PDF_TEMPLATE_LIST`; WHEN a user opens a CSV batch detail page THEN access SHALL require `USER_EXPORT_CSV`
10. WHEN the role permission seeder runs THEN it SHALL preserve any custom Admin role assignments per the existing "Preserve custom Admin role permissions on reseed" pattern
11. WHEN the frontend permission enum is updated THEN `frontend/src/enums/permission_name.enum.ts` SHALL include `USER_EXPORT_CSV` in the `PermissionName` union and `PermissionNameEnum.USER_EXPORT_CSV = { value: 'user.csv.export', text: 'User CSV Export' }`
12. WHEN the role-permission translation scanner is updated THEN it SHALL include `t('role|user.csv.export')`

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1, Requirement 7, existing Laratrust permission system

### Requirement 10: localStorage Persistence

**User Story:** As an administrator, I want my last property selection to be remembered between sessions, so that I do not have to reconfigure the modal every time.

#### Acceptance Criteria

1. WHEN the administrator successfully submits an export THEN the system SHALL save Csv_Export_Config to localStorage under a single, well-known key (e.g., `user_csv_export_config`)
2. WHEN the administrator opens Property_Selection_Modal THEN the system SHALL read the stored Csv_Export_Config and populate the in-modal state from it
3. IF the stored config references a property key that no longer exists in the canonical list (e.g., field removed in a release) THEN the system SHALL silently drop the unknown key and continue
4. IF the stored config omits a property key that exists in the canonical list (e.g., field added in a release) THEN the system SHALL append the new property to the end of the order, deselected by default
5. IF localStorage has no stored config OR the stored value is invalid JSON THEN the system SHALL fall back to Default_Csv_Config without raising a user-facing error
6. WHEN the administrator clicks "Reset to default" THEN the system SHALL NOT clear localStorage immediately; the localStorage value SHALL only be overwritten when the next export is submitted

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 2

## Non-Functional Requirements

### Performance Requirements
- WHEN a CSV export is submitted for up to 10,000 users THEN Csv_Export_Job SHALL complete within 60 seconds under normal queue load
- WHEN Property_Selection_Modal opens THEN it SHALL render within 200ms (no remote calls; property list is static)
- WHEN Export_History_Page loads with a default page size THEN it SHALL respond within 500ms under normal database load

### Security Requirements
- WHEN a user without `USER_EXPORT_CSV` invokes any CSV export endpoint THEN the system SHALL return 403 Forbidden
- WHEN a CSV file is stored on S3 THEN downloads SHALL use signed URLs with expiration
- WHEN Property_Selection_Modal submits a list of properties THEN the backend SHALL whitelist-validate each key against the canonical property registry and reject unknown keys with 422
- WHEN Csv_Export_Job runs THEN it SHALL only read user fields exposed by the canonical registry; arbitrary column injection SHALL NOT be possible

### Usability Requirements
- WHEN the administrator drags a property in Property_Selection_Modal THEN visual feedback (drop indicator, drag preview) SHALL be visible
- WHEN a CSV batch is in progress THEN Export_History_Page SHALL show a progress indicator (status badge + auto-refresh) consistent with PDF batches
- WHEN an export completes THEN the administrator SHALL receive a toast or in-app notification linking to the batch detail page (consistent with existing PDF behavior)

### Reliability Requirements
- WHEN the queue worker crashes mid-job THEN the queue SHALL retry the job up to 3 times before marking the batch failed
- WHEN S3 upload fails transiently THEN Csv_Export_Job SHALL retry the upload up to 3 times before marking the batch failed
- WHEN the database write of Export_Batch fails after the file has been uploaded THEN the system SHALL log the orphaned S3 object for manual cleanup (do not silently lose the artifact)

## Constraints and Assumptions

### Technical Constraints
- CSV generation uses the existing `league/csv` PHP library already present in `composer.json`
- File output is UTF-8 with BOM, CRLF, RFC 4180 quoting; no Shift_JIS support
- One CSV file per export run (no chunking, no multi-file output)
- Export_Batch is reused; a `type` column (`pdf` | `csv`) is added via migration with default `'pdf'` for existing rows
- For `type = csv`, no Export_Batch_Item rows are created; the existing per-item progress columns reflect aggregate status
- localStorage persistence is browser-local; users on multiple devices will not see synchronized configs
- Property list is whitelisted on backend; client cannot pass arbitrary keys
- Frontend stays within Next.js 15 + Mantine UI; the reorderable list SHALL use the existing `@hello-pangea/dnd` dependency already present in the project

### Assumptions
- The existing `select_all + filters` payload format used by PDF export is sufficient to resolve users for CSV export at job run time
- 10,000 users is a reasonable upper bound for typical exports; no explicit hard cap is enforced for the first release
- All enums used in the property list have Japanese translations (`getText` in `EnumHelper` resolves Japanese)
- The existing queue worker has sufficient memory to load 10,000 users in chunks for CSV row generation
- Admins exporting CSVs trust their own browser; a CSV with sensitive PII can be downloaded freely once permission is granted

## Success Criteria

### Definition of Done

- [ ] All acceptance criteria for Requirements 1–10 are met
- [ ] `USER_EXPORT_CSV` / `user.csv.export` permission is seeded and enforced on UI button, modal, frontend enum, and backend endpoints
- [ ] "Export CSV" button appears on user list and triggers Property_Selection_Modal
- [ ] Property_Selection_Modal supports select/deselect, drag-to-reorder, "Reset to default", localStorage save/restore
- [ ] Csv_Export_Job produces a UTF-8-with-BOM, CRLF, RFC-4180 CSV file with Japanese headers and translated values
- [ ] Export_Batch table has a `type` column with values `pdf` and `csv`; existing PDF rows backfilled to `pdf`
- [ ] Export History page lists both types in one table, filterable by Type; CSV rows show `-` for Template and link to a CSV-aware detail page
- [ ] Export History sidebar entry is moved from "PDF Template" group to "User" group
- [ ] Breadcrumbs on Export_History_Page reflect the new location
- [ ] CSV batch detail page shows status, metadata, and a working "Download CSV" button (signed URL)
- [ ] Backend rejects unknown property keys with 422
- [ ] Polling/auto-refresh works for in-progress CSV batches the same as for PDF
- [ ] Frontend passes `pnpm typecheck` and `pnpm prettier`
- [ ] Backend passes `npm run prettier` and `npm run pint`
- [ ] PHPUnit tests cover: permission gating, type validation, CSV content correctness (BOM, headers, encoding, escaping), `select_all + filters` resolution, retry/failure paths
