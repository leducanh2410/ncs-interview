# Requirements Document

## Introduction

The current shift user assignment interface is implemented as a modal dialog, which presents significant usability challenges: limited screen space for managing many users, no direct URL for navigation, and drag-and-drop that becomes impractical with large user counts. This feature replaces the modal with a dedicated full page at `/manage/shift/[id]`, using table-based assignment with menu-driven position switching and bulk actions. Fixed and temporary users are combined into a single view, grouped within each position table. No breaking changes to the backend API.

### Feature Summary

A dedicated shift assignment page that replaces the current modal, providing table-based user management with per-row position menus, bulk assignment actions, and a floating action bar for save/cancel/import/export operations.

### Business Value

- Reduces time spent assigning users to positions, especially for shifts with 30+ workers
- Provides direct URL access for bookmarking and sharing specific shift assignments
- Eliminates drag-and-drop friction, replacing it with menu-driven assignment that scales to any user count
- Combines fixed and temporary views to give managers a complete picture in one screen

### Scope

**Included:**
- New page route `/manage/shift/[id]` with full assignment UI
- Position overview cards (read-only)
- Pending, per-position, and rejected tables with row-level and bulk assignment actions
- Floating action bar with save, cancel, import CSV, and export Excel
- Combined fixed/temporary view with visual grouping
- Inline validation error display with row highlighting
- Config inputs (totals, break minutes) above position overview

**Excluded:**
- Changes to the backend API (reuse existing endpoints)
- Changes to the shift list page layout (only navigation target changes)
- Shift creation (remains a modal on the list page)
- Drag-and-drop functionality (replaced entirely by menus)

## Glossary

| Term | Definition |
|------|------------|
| Shift_Assignment_Page | The new dedicated page for managing user-to-position assignments for a shift |
| Position_Overview | Read-only card display showing each position's fill status and salary |
| Assignment_Table | A DataTable instance showing users belonging to a specific group (pending, position, or rejected) |
| Position_Menu | A dropdown menu on each table row listing available positions, pending, and rejected as move targets |
| Floating_Action_Bar | A non-scrolling bar at the top of the page containing save, cancel, import, and export actions |
| Config_Section | Input fields for fixed_total, temp_total, max_registered_temp, and break_minutes displayed above Position_Overview |
| Group_Users | The internal state mapping group IDs (pending, rejected, position IDs) to arrays of user IDs |
| Fixed_User | A user assigned to the shift via a fixed (regular) schedule, displayed with default background |
| Temp_User | A user assigned to the shift as temporary staff, displayed with a distinct background color |
| Bulk_Action | A UI pattern where multiple rows are selected via checkboxes and moved to a target group in one operation |

## Requirements

### Requirement 1: Page Route and Navigation

**User Story:** As a manager, I want to navigate directly to a shift's assignment page via URL, so that I can bookmark it and access it without going through the shift list modal flow.

#### Acceptance Criteria

1. WHEN a user navigates to `/manage/shift/[id]` THEN Shift_Assignment_Page SHALL load the shift detail data and render the assignment interface.
2. WHEN a user clicks on a shift item in the shift timeline list THEN the application SHALL navigate to `/manage/shift/[id]` instead of opening the update modal.
3. IF the user lacks the `SHIFT_EDIT` permission THEN Shift_Assignment_Page SHALL display the 403 error page.
4. IF the shift ID does not exist or the API returns an error THEN Shift_Assignment_Page SHALL display an appropriate error state.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Existing route configuration system, existing shift detail API

### Requirement 2: Floating Action Bar

**User Story:** As a manager, I want persistent save/cancel/import/export buttons visible at all times, so that I can take action without scrolling.

#### Acceptance Criteria

1. WHEN Shift_Assignment_Page renders THEN Floating_Action_Bar SHALL remain visible at the top of the content area while the rest of the page scrolls.
2. WHEN the user clicks "Save" THEN Floating_Action_Bar SHALL submit all assignment changes to the update API using the existing payload format.
3. WHEN the user clicks "Cancel" THEN Floating_Action_Bar SHALL discard local changes and reload the shift detail data from the API.
4. WHEN the user clicks "Import CSV" THEN Floating_Action_Bar SHALL open the CSV import modal.
5. WHEN the user clicks "Export Excel" THEN Floating_Action_Bar SHALL trigger the Excel export download.
6. IF the shift has already ended THEN Floating_Action_Bar SHALL disable the Save and Import buttons and display read-only mode.
7. IF the user lacks `SHIFT_IMPORT` permission THEN Floating_Action_Bar SHALL hide the Import CSV button.
8. IF the user lacks `SHIFT_EXPORT` permission THEN Floating_Action_Bar SHALL hide the Export Excel button.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1, existing import/export mutations

### Requirement 3: Config Section

**User Story:** As a manager, I want to configure shift capacity and break time on the same page as assignments, so that I can adjust settings without switching contexts.

#### Acceptance Criteria

1. WHEN Shift_Assignment_Page loads THEN Config_Section SHALL display editable inputs for fixed_total, temp_total, max_registered_temp, and break_minutes populated with current shift values.
2. WHEN the user modifies any config value THEN Config_Section SHALL update the local form state without submitting to the API.
3. IF the shift has ended THEN Config_Section SHALL render all inputs as read-only.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1

### Requirement 4: Position Overview Cards

**User Story:** As a manager, I want a visual overview of all positions showing fill status at a glance, so that I can quickly identify which positions need more workers.

#### Acceptance Criteria

1. WHEN Shift_Assignment_Page loads THEN Position_Overview SHALL display one card per workplace position showing: position name, assigned count vs. required total (combining fixed and temp), and salary per hour.
2. WHEN a position is fully staffed (assigned count >= total) THEN Position_Overview SHALL display a success indicator on that card.
3. WHEN a position has zero assignments THEN Position_Overview SHALL display a warning indicator on that card.
4. WHILE the user moves users between groups THEN Position_Overview SHALL update counts in real-time to reflect local state changes.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 1, Requirement 3

### Requirement 5: Combined Fixed/Temporary User Display

**User Story:** As a manager, I want to see both fixed and temporary users in a single view grouped within each table, so that I have a complete picture of each position's staffing.

#### Acceptance Criteria

1. WHEN Assignment_Table renders users for a position THEN it SHALL group Fixed_User rows at the top and Temp_User rows at the bottom.
2. WHEN Assignment_Table renders a Fixed_User row THEN it SHALL use the default table row background color.
3. WHEN Assignment_Table renders a Temp_User row THEN it SHALL use a visually distinct background color to differentiate from fixed users.
4. WHEN Position_Overview displays counts THEN it SHALL show both the combined total and a breakdown of fixed vs. temporary counts (e.g., "3F + 2T / 5").

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 4

### Requirement 6: Pending Table

**User Story:** As a manager, I want to see all unassigned (pending) users in a table immediately after the position overview, so that I can quickly assign them to positions.

#### Acceptance Criteria

1. WHEN there are users with pending status THEN Assignment_Table for pending SHALL render below Position_Overview showing all pending users with columns: checkbox, row number, name, staff number, type (fixed/temp), and action.
2. WHEN there are no pending users THEN Assignment_Table for pending SHALL display an empty state message.
3. WHEN the pending table header renders THEN it SHALL display the count of pending users in the title (e.g., "登録リスト (12)").

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 5

### Requirement 7: Position Tables

**User Story:** As a manager, I want each position to have its own table with an add-user button, so that I can manage position assignments clearly and add new users directly to a position.

#### Acceptance Criteria

1. WHEN Shift_Assignment_Page renders THEN it SHALL display one Assignment_Table per workplace position, ordered by position sort_order, between the pending table and the rejected table.
2. WHEN a position table header renders THEN it SHALL display the position name and fill count (e.g., "組立A (3/5)").
3. WHEN the user clicks "Add Users" on a position table THEN Assignment_Table SHALL open the existing AddUserModal scoped to that position.
4. WHEN users are added via AddUserModal THEN they SHALL appear in the corresponding position table immediately in local state.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 5, Requirement 6

### Requirement 8: Rejected Table

**User Story:** As a manager, I want to see rejected users at the bottom of the page, so that I can review and optionally re-assign them.

#### Acceptance Criteria

1. WHEN there are rejected users THEN Assignment_Table for rejected SHALL render at the bottom of the page showing rejected users with columns: checkbox, row number, name, staff number, type (fixed/temp), and action.
2. WHEN there are no rejected users THEN Assignment_Table for rejected SHALL display an empty state message.

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 5

### Requirement 9: Row-Level Position Menu

**User Story:** As a manager, I want to reassign a user to a different position via a dropdown menu on each row, so that I can quickly move individuals without drag-and-drop.

#### Acceptance Criteria

1. WHEN each Assignment_Table row renders THEN it SHALL display an "Assign" button in the action column.
2. WHEN the user hovers over the Assign button THEN Position_Menu SHALL open showing all available positions, plus "Pending" and "Rejected" options.
3. IF the user is already assigned to a position THEN Position_Menu SHALL exclude that position from the list and include "登録リスト" (Pending) as an option.
4. IF the user is in the pending list THEN Position_Menu SHALL exclude "登録リスト" from the options.
5. WHEN the user selects a target from Position_Menu THEN the user SHALL be moved to the target group in local state and the source and target tables SHALL re-render.
6. IF the shift has ended THEN Position_Menu SHALL not render (no action column in read-only mode).

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 6, Requirement 7, Requirement 8

### Requirement 10: Bulk Assignment Action

**User Story:** As a manager, I want to select multiple users and assign them to a position in one action, so that I can handle large groups efficiently.

#### Acceptance Criteria

1. WHEN Assignment_Table renders THEN each row SHALL have a checkbox for selection and the header SHALL have a "Select All" checkbox.
2. WHEN one or more rows are selected THEN Bulk_Action controls SHALL appear in the table header showing a target dropdown and an execute button.
3. WHEN the user selects a target and clicks execute THEN all selected users SHALL be moved to the target group in local state.
4. WHEN bulk move completes THEN all checkboxes SHALL be deselected and Position_Overview counts SHALL update.

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 9

### Requirement 11: Validation Error Display

**User Story:** As a manager, I want to see validation errors inline on the affected rows, so that I can identify and fix issues without cross-referencing separate error messages.

#### Acceptance Criteria

1. WHEN the save API returns validation errors for specific users THEN Assignment_Table SHALL highlight the affected rows with an error background color.
2. WHEN validation errors exist for a position table THEN Assignment_Table SHALL display error messages below the table listing each error with the affected user name.
3. WHEN the user moves a highlighted user to a different group THEN the error highlight SHALL be cleared for that user.
4. IF CSV import returns errors THEN Shift_Assignment_Page SHALL display the import errors in an alert area and highlight any affected rows in their respective tables.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 7, existing error handling logic in helper.ts

### Requirement 12: Remove Drag-and-Drop Dependencies

**User Story:** As a developer, I want unused drag-and-drop code and libraries removed, so that the codebase stays clean and bundle size is reduced.

#### Acceptance Criteria

1. WHEN the new Shift_Assignment_Page fully replaces the modal THEN the `@dnd-kit/core` package SHALL be removed from `package.json` if no other features use it.
2. WHEN the modal is replaced THEN the following components SHALL be deleted: `draggable_user_pill.tsx`, `droppable_fieldset.tsx`, and `modal_assign.tsx`.
3. WHEN the modal is replaced THEN `modal.tsx` (the shift update modal) and its CSS module SHALL be deleted.
4. IF `@dnd-kit/core` is used by other parts of the application THEN only the shift-specific drag-and-drop components SHALL be removed while keeping the package.

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirements 1-11 (must be fully implemented and verified before cleanup)

## Non-Functional Requirements

### Performance Requirements
- WHEN the page loads with up to 200 users THEN Shift_Assignment_Page SHALL render the complete UI within 2 seconds.
- WHEN a user is moved between groups THEN Position_Overview and affected tables SHALL re-render within 100ms.

### Security Requirements
- WHEN a user without `SHIFT_EDIT` permission accesses the page THEN the system SHALL display the 403 page.
- WHEN a user without `SHIFT_IMPORT` or `SHIFT_EXPORT` permissions accesses the page THEN the respective buttons SHALL be hidden.

### Usability Requirements
- WHEN the page content exceeds viewport height THEN the Floating_Action_Bar SHALL remain visible without scrolling.
- WHEN the user has unsaved changes and navigates away THEN the browser SHALL not prompt a confirmation dialog (cancel refreshes data, save persists it).

### Reliability Requirements
- WHEN the save API call fails THEN the system SHALL display an error notification and preserve the current local state.
- WHEN the cancel action is triggered THEN the system SHALL refetch data from the API to ensure consistency.

## Constraints and Assumptions

### Technical Constraints
- Must reuse existing backend API endpoints without modification
- Must use Mantine UI components and mantine-datatable for consistency with the rest of the application
- Must follow the existing file-based routing pattern (`.page.tsx` extension)
- The payload format for the update API must remain unchanged

### Assumptions
- A typical shift has 5-50 users; the page should handle up to 200 users comfortably
- Position count per workplace is typically 3-10
- The existing `categorizeUsers` helper can be reused with minimal changes to support the combined fixed/temp view
- Managers access this page from desktop browsers (responsive mobile layout is not required)

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] Page loads shift data and displays all sections correctly
- [ ] Users can be moved between groups via row menu and bulk action
- [ ] Save persists changes, Cancel refreshes data
- [ ] Import CSV and Export Excel work from the floating bar
- [ ] Validation errors display inline with row highlighting
- [ ] Fixed and temporary users are visually distinguished in all tables
- [ ] Position overview cards update in real-time
- [ ] `pnpm typecheck` and `pnpm prettier` pass
