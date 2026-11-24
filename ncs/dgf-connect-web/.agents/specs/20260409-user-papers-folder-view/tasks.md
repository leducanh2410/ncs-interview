# Implementation Plan: User Papers Folder View

## Overview

This plan follows a foundation-first strategy: backend changes (migration, enum, action updates, new endpoint) are completed first, then frontend components are built bottom-up (FileRow → PreviewModal → PaperFolders → integration into UserPapers). This ordering ensures the API contract is stable before the UI consumes it.

### Implementation Strategy
- Backend first: migration → enum → action update → new endpoint → tests
- Frontend bottom-up: utility → atoms (FileRow, PreviewModal) → container (PaperFolders) → integration
- Reuse existing patterns: `@hello-pangea/dnd` usage from `workplace_position.tsx`, mutation hooks from `api.ts`
- Translations last, after all UI text is finalized

## Implementation Plan

### Phase 1: Backend — Database & Enum

- [x] 1. Add `folder` column to `user_paper_items`
    - [x] 1.1 Create migration to add `folder` varchar(10) nullable column to `user_paper_items` table
        - `$table->string('folder', 10)->nullable()->after('file_path')`
        - _Requirements: 6.1, 6.2_

    - [x] 1.2 Create `UserPaperItemFolder` enum
        - Create `backend/app/Enums/UserPaperItemFolder.php` with cases: `Current = 'current'`, `Old = 'old'`, `Rejected = 'rejected'`
        - _Requirements: 6.1, 6.2_

    - [x] 1.3 Backfill existing data in migration
        - Set `folder = 'current'` where `approved_at IS NOT NULL AND rejected_at IS NULL`
        - Set `folder = 'rejected'` where `rejected_at IS NOT NULL`
        - Leave `folder = NULL` where both are null (pending items)
        - _Requirements: 6.1, 6.2_

- [x] 2. Checkpoint — Verify migration runs cleanly
    - Run migration in Docker container, verify column exists and backfill is correct.

### Phase 2: Backend — Update Approval & Add Move Endpoint

- [x] 3. Update `ApprovePapersAction` to set folder
    - [x] 3.1 Set `folder = 'current'` when batch-updating approved items
        - In the approved items batch update, add `'folder' => UserPaperItemFolder::Current->value`
        - _Requirements: 6.1_

    - [x] 3.2 Set `folder = 'rejected'` when batch-updating rejected items
        - In the rejected items batch update, add `'folder' => UserPaperItemFolder::Rejected->value`
        - Also handle the face-approval-failed portrait rejection path
        - _Requirements: 6.2_

- [x] 4. Create Move Paper endpoint
    - [x] 4.1 Create `MovePaperItemAction` in `backend/app/Actions/Admin/User/`
        - Validate item belongs to the user
        - Validate item is not pending (`approved_at` or `rejected_at` must be set)
        - Validate current folder is not `rejected`
        - Validate target folder is `current` or `old` (not `rejected`)
        - Update `folder` column and return updated item
        - _Requirements: 6.3, 6.4_

    - [x] 4.2 Create `MovePaperItemRequest` form request
        - Validate `folder` field: required, string, in `current,old`
        - _Requirements: 6.3, 6.4_

    - [x] 4.3 Add controller method `movePaper` in `UserController`
        - Accept `User`, `UserPaperItem`, `MovePaperItemRequest`
        - Call `MovePaperItemAction` and return result
        - _Requirements: 6.3_

    - [x] 4.4 Register route in `routes/api.php`
        - `PATCH /admin/users/{user}/papers/{userPaperItem}/move`
        - Protect with `USER_PAPER_APPROVE` permission middleware
        - _Requirements: 6.3_

- [x] 5. Checkpoint — Verify backend endpoints
    - Test approve sets folder correctly. Test move endpoint with valid and invalid transitions. Run `npm run prettier` and `npm run pint` in `./backend`.

### Phase 3: Frontend — Type Updates & API Hook

- [x] 6. Update frontend types and API
    - [x] 6.1 Add `folder` field to `UserPaperItem` interface
        - Add `folder: 'current' | 'old' | 'rejected' | null` to `frontend/src/types/models/user_paper.d.ts`
        - _Requirements: 6.1_

    - [x] 6.2 Add API config entry for move endpoint
        - Add `ADMIN_USER_PAPERS_MOVE: 'admin/users/:userId/papers/:id/move'` to `frontend/src/configs/api.config.ts`
        - _Requirements: 6.3_

    - [x] 6.3 Create `useMoveUserPaperMutation` hook in `api.ts`
        - PATCH request with `{ folder: string }` body
        - Invalidate `user-papers` query on success
        - _Requirements: 3.1, 3.2_

### Phase 4: Frontend — File Utility & FileRow Component

- [x] 7. Create file type utility
    - [x] 7.1 Create `getFileCategory` helper function
        - Input: `fileName: string`, Output: `'image' | 'pdf' | 'other'`
        - Image extensions: `jpg`, `jpeg`, `png`, `gif`, `webp`
        - PDF extension: `pdf`
        - Add inline in the `user_papers` partials directory (no separate utility file needed for one function)
        - _Requirements: 2.1, 4.1, 4.2_

- [x] 8. Create FileRow component
    - [x] 8.1 Create `frontend/src/pages/admin/user/partials/paper_file_row.tsx`
        - Props: `item: UserPaperItem`, `index: number`, `isDraggable: boolean`, `canDelete: boolean`, `onPreview: (item) => void`, `onDelete: (id) => void`
        - Render: drag handle (LuGripVertical, conditional), file icon (LuImage/LuFileText/LuFile), file name, paper type (EnumHelper.getText), upload date (DateHelper.parseAndFormat), delete button (ActionIcon with LuTrash2)
        - Wrap in `Draggable` from `@hello-pangea/dnd` when `isDraggable` is true, plain div otherwise
        - Attach `onDoubleClick` handler
        - _Requirements: 1.1, 2.1, 2.2, 2.3, 2.4, 3.3, 3.4_

- [x] 9. Checkpoint — Verify FileRow renders correctly in isolation
    - Visually confirm file row with different file types and draggable/non-draggable states.

### Phase 5: Frontend — PreviewModal Component

- [x] 10. Create PreviewModal component
    - [x] 10.1 Create `frontend/src/pages/admin/user/partials/paper_preview_modal.tsx`
        - Props: `opened: boolean`, `onClose: () => void`, `item: UserPaperItem | null`
        - Modal header: file name + paper type (translated)
        - Image files: `<img>` with `maxWidth: 100%`, `maxHeight: 80vh`, `objectFit: contain`
        - PDF files: `<iframe>` with `width: 100%`, `height: 80vh`, `border: none`
        - Other files: file name + `<Anchor>` download link to static URL
        - Modal size: `xl`
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

### Phase 6: Frontend — PaperFolders Accordion Component

- [x] 11. Create PaperFolders component
    - [x] 11.1 Create `frontend/src/pages/admin/user/partials/paper_folders.tsx`
        - Props: `items: UserPaperItem[]`, `userId: string`, `canDelete: boolean`
        - Group items by `folder` field into `current`, `old`, `rejected` arrays
        - Render `DragDropContext` wrapping Mantine `Accordion` with `multiple` and `defaultValue={['current']}`
        - Each `Accordion.Item` contains a `Droppable` with matching `droppableId`
        - Accordion header shows folder name (translated) + item count
        - Rejected droppable has `isDropDisabled={true}`
        - Empty folders show empty state text
        - _Requirements: 1.1, 1.2, 1.3, 1.4_

    - [x] 11.2 Implement `onDragEnd` handler with optimistic updates
        - On drag end: validate source/destination are valid (both must be `current` or `old`)
        - Optimistically update local grouped state
        - Call `useMoveUserPaperMutation` with item ID and target folder
        - On error: revert local state, show error notification
        - _Requirements: 3.1, 3.2, 3.5_

    - [x] 11.3 Wire delete and preview handlers
        - Delete: reuse existing `DConfirmModal` pattern and `useDeleteUserPaperMutation`
        - Preview: manage `previewItem` state, pass to `PreviewModal`
        - _Requirements: 5.1, 5.2, 5.3, 5.4_

### Phase 7: Frontend — Integration into UserPapers

- [x] 12. Replace processed papers table with PaperFolders
    - [x] 12.1 Update `user_papers.tsx` to use PaperFolders for processed items
        - Remove the "Processed papers" `<Paper>` section with its `DDataTable`
        - Replace with `<PaperFolders items={processedItems} userId={id} canDelete={perms.USER_PAPER_DELETE} />`
        - Keep the "Paper status" section and "Waiting for approval" section unchanged
        - Move `DConfirmModal` for delete into `PaperFolders` or keep shared — decide based on whether pending table also needs it (it does, so keep the shared confirm modal in `UserPapers` and pass `onDelete` callback down)
        - _Requirements: 1.1, 2.1, 3.1, 4.1, 5.1_

- [x] 13. Checkpoint — Full integration test
    - Verify: accordion renders with correct items per folder, drag-and-drop between Current↔Old works, rejected folder is locked, double-click preview works for images and PDFs, delete works from both pending table and folder view.

### Phase 8: Translations

- [x] 14. Add translations
    - [x] 14.1 Add translation keys using `t(...)` in all new components
        - Keys needed: `'Current papers'`, `'Old papers'`, `'Rejected papers'`, `'No papers in this folder'`, `'Download file'`, `'File preview'`, `'Could not move paper'`
        - _Requirements: 1.1, 1.4_

    - [x] 14.2 Run `pnpm trans:prepare` and fill translations
        - Fill `vi` and `ja` translations in the generated batch file
        - Run `pnpm trans:apply`
        - _Requirements: 1.1_

### Phase 9: Final Validation

- [x] 15. Code Quality
    - [x] 15.1 Run `pnpm prettier` and `pnpm typecheck` in `./frontend`
    - [x] 15.2 Run `npm run prettier` and `npm run pint` in `./backend`

## Notes

- **Ordering rationale**: Backend-first ensures the `folder` field and move API are available before the frontend consumes them. Frontend is built bottom-up so each component can be visually verified before composition.
- **Shared delete confirm modal**: The `DConfirmModal` for deletion is used by both the pending table and folder view. It should remain in the parent `UserPapers` component with `currentPaperId` state, and both sections call the same `openDelete` handler.
- **Risk — Drag-and-drop across accordions**: `@hello-pangea/dnd` requires all `Droppable` areas to be rendered (not conditionally hidden). Since collapsed accordion items are still in the DOM with Mantine's Accordion, this should work. If collapsed sections don't render children, we may need to use `display: none` instead of conditional rendering.
- **Risk — PDF iframe on mobile**: Some mobile browsers may not render PDFs in iframes well. This is acceptable since this is an admin-only feature primarily used on desktop.
- **Migration backfill**: The migration must handle existing data by setting `folder` based on `approved_at`/`rejected_at` timestamps. This is a one-time operation and safe to run in production.
