# Requirements Document

## Introduction

This feature introduces a PDF Template Export module that allows administrators to create reusable PDF templates by uploading blank PDF files and defining data fields on them via a browser-based visual editor. Each field maps to a predefined data source (user attributes, dates, custom functions) and can render as text or a conditional shape. Administrators can then generate filled PDFs per user — either individually or in batch — from the user list screen, with generation handled asynchronously via background jobs. Completed documents are stored on S3 and export progress is tracked per batch and per item. This enables standardized document generation for employee records, residence cards, and other workforce management paperwork.

### Feature Summary

A visual PDF template editor and async export system that allows administrators to define data-bound fields on uploaded PDF documents and generate filled PDFs per user from the user list screen.

### Business Value

- Eliminates manual data entry on recurring PDF documents (residence cards, contracts, certificates)
- Ensures consistency and accuracy of generated documents across all employees
- Reduces administrative overhead by enabling batch generation and reusable templates

### Scope

**Included:**
- PDF template CRUD (upload, re-upload PDF, edit fields, soft-delete)
- Browser-based visual field editor with drag, resize, copy/paste, zoom
- Field data binding to predefined data sources (model fields, dates, custom functions)
- Text rendering with configurable styles (font weight, size, alignment, spacing, overflow)
- Shape rendering (rectangle/circle, filled/outlined, configurable color) for boolean fields
- Per-user PDF generation with preview (from template editor)
- Export initiated from the user list screen (select one, multiple, or all users across pages)
- Asynchronous batch export via background jobs (chunked processing)
- Batch and per-item status tracking (pending/processing/completed/failed)
- Export history tracking per template per batch
- S3 storage for templates and generated PDFs
- Permission-based access control (view/create/update/delete/export)
- Template locking during editing or active export
- Soft-delete for templates (preserves export history)

**Excluded:**
- Template versioning
- Font-family selection (single font for now)
- Custom expression editor (all data sources predefined in code)
- Fields spanning multiple pages

## Glossary

| Term | Definition |
|------|------------|
| PDF_Template | A reusable template consisting of an uploaded blank PDF and its associated field definitions |
| Field | A positioned region on a PDF page that maps to a data source and renders as text or shape |
| Data_Source | A predefined configuration item that resolves to a value for a given user (model field, fixed, or custom function) |
| Field_Editor | The browser-based visual interface for placing and configuring fields on a PDF template |
| Fill_Mode | The rendering strategy for a field: either text insertion or shape drawing |
| Export_Batch | A single generation run that produces filled PDFs for one or more users from a template, processed asynchronously |
| Export_Batch_Item | A single user's PDF within an export batch, with its own status tracking |
| Export_History | A record tracking which batches were generated for a template, including timestamps, user lists, and statuses |
| Template_Lock | A mechanism using Redis with 30-minute TTL that prevents concurrent modifications or deletion while a template is being edited or exported |

## Requirements

### Requirement 1: PDF Template Management

**User Story:** As an administrator, I want to upload, list, edit, re-upload, and delete PDF templates, so that I can manage reusable document layouts for generating user PDFs.

#### Acceptance Criteria

1. WHEN an administrator uploads a PDF file (< 5MB) with a template name THEN PDF_Template SHALL be created and the file SHALL be stored on S3
2. WHEN an administrator views the template list THEN PDF_Template SHALL display each template's name, page count, field count, created date, and last modified date
3. WHEN an administrator deletes a template THEN PDF_Template SHALL be soft-deleted (not permanently removed), preserving export history records
4. IF the uploaded file exceeds 5MB or is not a valid PDF THEN PDF_Template SHALL reject the upload with an appropriate error message
5. WHEN an administrator accesses the template module THEN PDF_Template SHALL enforce permission checks for view, create, update, delete, and export actions separately
6. WHEN an administrator re-uploads a PDF file for an existing template THEN PDF_Template SHALL replace the stored file on S3, update page_count, and preserve existing field definitions (with a warning that field positions may no longer align)
7. WHEN a template is soft-deleted THEN PDF_Template SHALL NOT appear in the template list but SHALL still be accessible from export history records
8. WHEN a soft-deleted template has active export batches THEN the generated PDFs on S3 SHALL remain accessible for download

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: S3 storage configuration, permission system (Laratrust)

### Requirement 2: Visual Field Editor

**User Story:** As an administrator, I want to visually place, move, resize, and configure fields on a PDF template in the browser, so that I can define exactly where and how data appears on the generated document.

#### Acceptance Criteria

1. WHEN an administrator opens a template for editing THEN Field_Editor SHALL render all PDF pages with existing fields overlaid
2. WHEN an administrator draws a rectangle or circle on a page THEN Field_Editor SHALL create a new Field at that position and size, bound to that page
3. WHEN an administrator selects a field THEN Field_Editor SHALL display a configuration panel to choose a Data_Source from a searchable dropdown
4. WHEN an administrator configures a field THEN Field_Editor SHALL allow setting a field-level placeholder (nullable) that overrides the data source default placeholder
5. WHEN an administrator drags a field THEN Field_Editor SHALL update the field's position on the page
6. WHEN an administrator drags a field's resize handles THEN Field_Editor SHALL update the field's dimensions
7. WHEN an administrator copies a field and pastes it THEN Field_Editor SHALL create a duplicate field with the same configuration at an offset position
8. WHEN an administrator deletes a field THEN Field_Editor SHALL remove it from the page
9. WHEN an administrator uses zoom controls THEN Field_Editor SHALL scale the PDF view and fields proportionally
10. WHEN an administrator saves THEN Field_Editor SHALL persist all field definitions (position, size, page, shape, data source, placeholder, fill mode, styles) to the database
11. WHILE the editor is open THEN Field_Editor SHALL allow fields to overlap each other on the same page

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 1 (template must exist)

### Requirement 3: Data Source Configuration

**User Story:** As an administrator, I want to select from a predefined list of data sources for each field, so that the system knows which user data to fill into each field position.

#### Acceptance Criteria

1. WHEN the Data_Source dropdown is opened THEN Field_Editor SHALL display all predefined data sources showing only the name, grouped by `group`
2. WHEN the administrator types in the Data_Source dropdown THEN Field_Editor SHALL filter the list by matching against name or key
3. IF a Data_Source is of type "model_field" THEN Data_Source SHALL resolve to the corresponding database column value for the given user (e.g., `user.email`, `user_info.address`)
4. IF a Data_Source is of type "fixed" THEN Data_Source SHALL return a fixed string value configured in the data source definition
5. IF a Data_Source is of type "custom_function" THEN Data_Source SHALL execute the registered PHP function with User and nullable UserInfo as inputs and return a string or boolean

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: User and UserInfo models

### Requirement 4: Field Fill Mode — Text

**User Story:** As an administrator, I want to configure how text is rendered in a field, so that the generated PDF matches the required document format precisely.

#### Acceptance Criteria

1. WHEN Fill_Mode is set to "text" THEN Field SHALL render the resolved Data_Source value as text within the field bounds
2. WHEN text fill mode is configured THEN Field SHALL support font weight (normal/bold), font size, horizontal alignment (left/center/right), and vertical alignment (top/middle/bottom)
3. WHEN letter spacing is set to "auto" THEN Field SHALL calculate letter spacing to distribute characters evenly across the field width
4. WHEN letter spacing is set to a fixed value THEN Field SHALL apply that exact spacing between characters
5. IF the resolved text exceeds the field bounds and overflow is set to "wrap" THEN Field SHALL insert line breaks to fit the text within the field width
6. IF the resolved text exceeds the field bounds and overflow is set to "trim" THEN Field SHALL truncate the text at the field boundary
7. IF the resolved text exceeds the field bounds and overflow is set to "shrink" THEN Field SHALL reduce the font size until the text fits within the field bounds

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2, Requirement 3

### Requirement 5: Field Fill Mode — Shape

**User Story:** As an administrator, I want a field to draw its shape (rectangle or circle) when the bound value is truthy, so that I can create conditional visual markers on the document (e.g., checkmarks via circling an option).

#### Acceptance Criteria

1. WHEN Fill_Mode is set to "shape" and the resolved Data_Source value is truthy THEN Field SHALL draw the field's shape (rectangle or circle) on the PDF
2. WHEN Fill_Mode is set to "shape" and the resolved Data_Source value is falsy THEN Field SHALL render nothing
3. WHEN shape fill mode is configured THEN Field SHALL support fill style (filled or outlined), color selection, and stroke width (for outlined mode)

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 2, Requirement 3

### Requirement 6: PDF Preview

**User Story:** As an administrator, I want to preview a filled PDF in the browser before exporting, so that I can verify the template produces correct output.

#### Acceptance Criteria

1. WHEN an administrator requests a preview from the template editor THEN PDF_Template SHALL generate a filled PDF using placeholder values (field-level placeholder or data source default placeholder) and display it in the browser
2. WHEN a preview is displayed THEN PDF_Template SHALL provide a download button for the previewed PDF

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4, Requirement 5

### Requirement 7: PDF Export from User List

**User Story:** As an administrator, I want to select users from the user list screen and generate filled PDFs using a chosen template, so that I can produce official documents starting from the employees I need them for.

#### Acceptance Criteria

1. WHEN an administrator is on the user list screen THEN the system SHALL provide a way to select one or multiple users on the current page for PDF export
2. WHEN an administrator wants to export for all users (across all pages) THEN the system SHALL provide a "select all" option that includes users from all pages matching the current filters
3. WHEN users are selected THEN the system SHALL allow the administrator to choose a PDF template from a dropdown/modal
4. WHEN a template is selected and export is triggered THEN the system SHALL create an Export_Batch record and dispatch an asynchronous background job
5. WHEN export is triggered THEN the system SHALL immediately show the administrator a confirmation with a link to view the batch progress
6. WHEN a single user is selected for export THEN the system SHALL still use the async batch flow (batch of size 1) for consistency

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4, Requirement 5, existing user list page

### Requirement 8: Asynchronous Batch Export

**User Story:** As an administrator, I want batch PDF generation to happen in the background so that I don't have to wait for all PDFs to be generated before continuing my work.

#### Acceptance Criteria

1. WHEN a batch export is triggered THEN the system SHALL create an Export_Batch with status "pending" and one Export_Batch_Item per user with status "pending"
2. WHEN the background job starts processing THEN the batch status SHALL change to "processing"
3. WHEN the background job processes users THEN it SHALL process them in chunks (e.g., 10 users per job iteration) and dispatch itself recursively for the next chunk
4. WHEN a single user's PDF is generated successfully THEN the Export_Batch_Item status SHALL change to "completed" and the file SHALL be stored on S3
5. WHEN a single user's PDF generation fails THEN the Export_Batch_Item status SHALL change to "failed" with an error message, and the job SHALL continue with remaining users
6. WHEN all items in a batch are processed (completed or failed) THEN the Export_Batch status SHALL change to "completed" (or "failed" if all items failed)
7. WHEN an administrator views a batch in progress THEN the system SHALL show the current progress (e.g., "12/50 completed, 1 failed")
8. WHEN all items in a batch are completed THEN the system SHALL package the PDFs into a zip file on S3 for bulk download
9. WHEN a batch has some failed items THEN the system SHALL still allow downloading the successfully generated PDFs

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 7, Laravel Queue system

### Requirement 9: Export History & Status Tracking

**User Story:** As an administrator, I want to view the export history for a template with real-time status, so that I can track progress and download completed documents.

#### Acceptance Criteria

1. WHEN an administrator views a template's export history THEN Export_History SHALL display a list of batches with: status (pending/processing/completed/failed), generation timestamp, user count, completed count, failed count
2. WHEN an administrator views a batch detail THEN Export_History SHALL show the batch info AND a paginated list of items with: user name, status (pending/completed/failed), error message (if failed)
3. WHEN viewing export history THEN Export_History SHALL allow downloading individual PDFs (for completed items) or the zip file (for completed batches)
4. WHEN a batch is in progress THEN the frontend SHALL poll or refresh to show updated progress

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 8

### Requirement 10: Template Locking

**User Story:** As an administrator, I want templates to be locked when someone is editing them or when an export is in progress, so that concurrent modifications don't cause data corruption or incorrect exports.

#### Acceptance Criteria

1. WHEN an administrator makes their first change in the field editor (not just opening it) THEN the template SHALL be locked for editing by that administrator using Redis with a 30-minute TTL
2. WHEN a template is locked for editing THEN other administrators SHALL NOT be able to update fields, delete the template, or export from it, but CAN still view it
3. WHEN the editing administrator saves or navigates away THEN the edit lock SHALL be released
4. WHEN the editing administrator makes any subsequent change THEN the lock TTL SHALL be refreshed (extend to 30 minutes from the time of change)
5. WHEN a batch export is in progress (status = pending or processing) THEN the template SHALL be locked for updates and deletion using Redis with a 30-minute TTL
6. WHEN a template is locked THEN the system SHALL display who locked it and why (editing vs export in progress)
7. IF a lock TTL expires (30 minutes without refresh) THEN the system SHALL automatically release it
8. WHEN an administrator attempts to update, delete, or export a locked template THEN the system SHALL return an error explaining the lock reason

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 2, Requirement 8, Redis

## Non-Functional Requirements

### Security Requirements
- WHEN a user without the appropriate permission accesses any PDF template endpoint THEN the system SHALL return 403 Forbidden
- WHEN generated PDFs are stored on S3 THEN the system SHALL use signed URLs with expiration for downloads
- WHEN a PDF is uploaded THEN the system SHALL validate the file is a legitimate PDF document

### Usability Requirements
- WHEN using the field editor THEN the system SHALL provide visual feedback for all interactions (hover states, selection indicators, drag previews)
- WHEN searching data sources THEN the system SHALL display results within 200ms of input
- WHEN a batch export is in progress THEN the system SHALL show a progress indicator accessible from the user list screen

### Reliability Requirements
- WHEN a background job fails unexpectedly (e.g., worker crash) THEN the system SHALL allow the job to be retried by the queue system (max 3 attempts per chunk)
- WHEN S3 upload fails THEN the system SHALL retry up to 3 times before marking the item as failed
- WHEN a chunk job fails after max retries THEN remaining unprocessed items SHALL be marked as failed and the batch status set to "completed" (with partial failures)

## Constraints and Assumptions

### Technical Constraints
- Uploaded PDF files must be < 5MB
- PDF generation uses overlay approach (FPDI/TCPDF) on the original uploaded PDF
- Japanese font support is required; font files are provided by the team
- All data sources are predefined in backend PHP configuration (no runtime expression evaluation)
- Frontend must work within the existing Next.js 15 + Mantine UI stack
- Backend must work within the existing Laravel 11 stack
- Background jobs use Laravel's queue system (existing queue worker infrastructure)
- Chunk size for batch export: 50 users per job dispatch

### Assumptions
- Administrators have access to blank PDF documents to upload as templates
- The predefined data source list (20–50 items) covers all required document fields
- Generated PDFs do not need to be editable after generation
- One template maps to one uploaded PDF file (no merging of multiple PDFs)
- UserInfo records may be null for some users; the system handles this gracefully
- Laravel queue workers are already running in the production environment

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met for Requirements 1–10
- [ ] Permission checks enforced for all 5 actions (view/create/update/delete/export)
- [ ] Field editor supports create, move, resize, copy/paste, delete, zoom
- [ ] Text fill mode supports all style options (weight, size, alignment, spacing, overflow)
- [ ] Shape fill mode renders conditionally with fill/outline and color
- [ ] Preview works with user data and custom values
- [ ] Export is triggered from user list screen (single, multi, all users)
- [ ] Batch export runs asynchronously with chunked background jobs
- [ ] Batch and item status tracking works (pending → processing → completed/failed) with batch-level failed status when all items fail
- [ ] Export history shows real-time progress for in-progress batches
- [ ] Template locking prevents concurrent edits and protects during export
- [ ] Soft-delete preserves export history and generated PDFs
- [ ] Re-upload PDF replaces file while preserving field definitions
- [ ] Japanese text renders correctly in generated PDFs
- [ ] Non-functional requirements (performance, security) are satisfied
- [ ] Frontend passes `pnpm typecheck` and `pnpm prettier`
- [ ] Backend passes `npm run prettier` and `npm run pint`
