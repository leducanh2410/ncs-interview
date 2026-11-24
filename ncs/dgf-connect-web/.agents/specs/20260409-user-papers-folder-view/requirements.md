# Requirements Document

## Introduction

The admin user papers tab currently displays all processed papers (approved and rejected) in a single flat table, making it difficult for admins to distinguish current valid documents from outdated or rejected ones. This feature replaces the processed papers table with an accordion-style folder view organized into three categories: Current, Old, and Rejected. Admins can drag and drop files between Current and Old folders, and double-click any file to preview images and PDFs in a modal.

### Feature Summary

Reorganize the processed papers section into three collapsible folders (Current, Old, Rejected) with drag-and-drop reordering and in-modal file preview.

### Business Value

Admins managing user documents will immediately see which papers are currently valid, reducing errors when verifying employee documentation. The drag-and-drop capability allows quick reclassification without navigating away, and the in-modal preview eliminates the need to open files in separate browser tabs.

### Scope

**Included:**
- Accordion folder UI for processed papers (Current, Old, Rejected)
- Drag-and-drop between Current and Old folders
- New backend field to track folder assignment per paper item
- Backend API endpoint to move papers between folders
- Double-click file preview modal for images and PDFs
- Delete action on each file row in the folder view
- File row metadata: icon, file name, paper type, upload date

**Excluded:**
- Changes to the "Waiting for approval" pending papers section (remains as-is)
- Changes to the approval/rejection workflow modal
- Preview for non-image/non-PDF file types
- Bulk operations on folder contents

## Glossary

| Term | Definition |
|------|------------|
| Paper_Item | An individual uploaded document file (e.g., passport scan, card photo) belonging to a user's paper submission |
| Folder | A logical category (Current, Old, Rejected) that groups processed Paper_Items in the UI |
| Current_Folder | Contains approved Paper_Items that represent the user's active/valid documents |
| Old_Folder | Contains approved Paper_Items that are no longer current but kept for historical reference |
| Rejected_Folder | Contains Paper_Items that were rejected during the approval process; these are read-only and cannot be moved |
| Accordion | A vertically stacked, collapsible UI component where each folder is a section that can expand/collapse |
| Preview_Modal | A modal dialog that displays an image or PDF file inline when a user double-clicks a file row |

## Requirements

### Requirement 1: Accordion Folder Layout

**User Story:** As an admin, I want processed papers organized into collapsible folders by status, so that I can quickly find current, old, or rejected documents.

#### Acceptance Criteria

1. WHEN processed Paper_Items are loaded THEN Accordion SHALL display three collapsible sections: Current_Folder, Old_Folder, and Rejected_Folder
2. WHEN a Folder section header is clicked THEN Accordion SHALL toggle that section between expanded and collapsed states
3. WHEN Accordion is first rendered THEN Current_Folder SHALL be expanded by default and Old_Folder and Rejected_Folder SHALL be collapsed
4. WHERE a Folder contains zero Paper_Items THEN Accordion SHALL display the folder header with a count of "(0)" and show an empty state message when expanded

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None

### Requirement 2: File Row Display

**User Story:** As an admin, I want each file displayed as a row with an icon, name, type, date, and delete button, so that I can see key information at a glance.

#### Acceptance Criteria

1. WHEN a Paper_Item is rendered in a Folder THEN File_Row SHALL display: a file-type icon (image icon for jpg/png, document icon for pdf, generic icon for others), the file name, the paper type label (translated), and the upload date
2. WHEN the upload date is displayed THEN File_Row SHALL format it using the project's FORMAT_TIMESTAMP format
3. WHEN the admin has USER_PAPER_DELETE permission THEN File_Row SHALL display an enabled delete action button
4. IF the admin does not have USER_PAPER_DELETE permission THEN File_Row SHALL display the delete button as disabled

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1

### Requirement 3: Drag and Drop Between Folders

**User Story:** As an admin, I want to drag files between Current and Old folders, so that I can reclassify documents when they expire or become relevant again.

#### Acceptance Criteria

1. WHEN an admin drags a Paper_Item from Current_Folder to Old_Folder THEN Drag_Handler SHALL move the item to Old_Folder and persist the change via API
2. WHEN an admin drags a Paper_Item from Old_Folder to Current_Folder THEN Drag_Handler SHALL move the item to Current_Folder and persist the change via API
3. IF an admin attempts to drag a Paper_Item from or to Rejected_Folder THEN Drag_Handler SHALL prevent the drop and show no drop-target indicator
4. WHILE a Paper_Item is being dragged THEN Drag_Handler SHALL display a visual drag indicator on the item and highlight valid drop-target Folders
5. WHEN a drag-and-drop operation fails (API error) THEN Drag_Handler SHALL revert the item to its original Folder and display an error notification

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Requirement 1, Requirement 6

### Requirement 4: File Preview Modal

**User Story:** As an admin, I want to double-click a file to preview it in a modal, so that I can quickly verify document contents without leaving the page.

#### Acceptance Criteria

1. WHEN an admin double-clicks a Paper_Item that is an image file (jpg, jpeg, png, gif, webp) THEN Preview_Modal SHALL open and display the image scaled to fit the modal
2. WHEN an admin double-clicks a Paper_Item that is a PDF file THEN Preview_Modal SHALL open and display the PDF rendered inline using an embedded viewer
3. IF an admin double-clicks a Paper_Item that is neither an image nor a PDF THEN Preview_Modal SHALL open and display the file name with a download link
4. WHEN Preview_Modal is open THEN Preview_Modal SHALL display the file name and paper type in the modal header
5. WHEN the admin clicks outside the modal or the close button THEN Preview_Modal SHALL close

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium
- **Dependencies**: Requirement 2

### Requirement 5: Delete Paper from Folder View

**User Story:** As an admin, I want to delete a paper directly from the folder view, so that I can remove incorrect or unnecessary documents.

#### Acceptance Criteria

1. WHEN the admin clicks the delete button on a File_Row THEN Delete_Handler SHALL show a confirmation dialog
2. WHEN the admin confirms deletion THEN Delete_Handler SHALL call the existing delete API and remove the Paper_Item from the Folder
3. IF deletion fails THEN Delete_Handler SHALL display an error notification and keep the Paper_Item in the Folder
4. WHEN deletion succeeds THEN Delete_Handler SHALL display a success notification and update the Folder item count

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 2 (existing delete API)

### Requirement 6: Backend Folder Assignment

**User Story:** As an admin, I want paper folder assignments to persist, so that the categorization is consistent across sessions and for all admins.

#### Acceptance Criteria

1. WHEN a Paper_Item is approved during the approval process THEN Backend SHALL assign it to Current_Folder by default
2. WHEN a Paper_Item is rejected during the approval process THEN Backend SHALL assign it to Rejected_Folder
3. WHEN an admin moves a Paper_Item between folders via drag-and-drop THEN Backend SHALL update the Paper_Item's folder assignment and return the updated item
4. IF an admin attempts to move a Paper_Item to or from Rejected_Folder via API THEN Backend SHALL return a 422 validation error

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: None

## Non-Functional Requirements

### Performance Requirements
- WHEN the accordion renders with up to 50 Paper_Items across all folders THEN the UI SHALL render within 200ms without visible jank
- WHEN a drag-and-drop operation completes THEN the API call SHALL respond within 500ms under normal conditions

### Security Requirements
- WHEN a folder move API request is received THEN Backend SHALL verify the admin has USER_PAPER_APPROVE permission before allowing the operation
- WHEN a delete request is received THEN Backend SHALL verify the admin has USER_PAPER_DELETE permission

### Usability Requirements
- WHEN an admin interacts with the accordion THEN the UI SHALL provide smooth expand/collapse animations
- WHEN an admin drags a Paper_Item THEN the UI SHALL clearly indicate valid and invalid drop targets with visual cues (highlight for valid, no-entry cursor for invalid)

### Reliability Requirements
- WHEN a drag-and-drop API call fails THEN the UI SHALL optimistically revert the item position and show an error notification
- WHEN the papers API returns an empty list THEN the Accordion SHALL render all three folders with zero counts

## Constraints and Assumptions

### Technical Constraints
- Must use `@hello-pangea/dnd` library for drag-and-drop (already in project dependencies)
- Must use Mantine UI Accordion component for the folder layout
- Backend must add a new column to `user_paper_items` table for folder assignment
- PDF preview must work without additional server-side processing (client-side rendering)

### Assumptions
- All approved papers default to Current_Folder; admins manually move them to Old_Folder when they expire
- Rejected papers are locked in the Rejected_Folder and cannot be moved
- The existing pending papers section and approval modal remain unchanged
- File types can be determined from file extensions

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] Accordion correctly displays papers in 3 folders with counts
- [ ] Drag-and-drop works between Current and Old folders only
- [ ] Double-click preview works for images and PDFs
- [ ] Delete works from folder file rows with confirmation
- [ ] Backend persists folder assignments
- [ ] All translations are provided (en, vi, ja)
- [ ] TypeScript type checks pass
- [ ] Code is formatted with Prettier
