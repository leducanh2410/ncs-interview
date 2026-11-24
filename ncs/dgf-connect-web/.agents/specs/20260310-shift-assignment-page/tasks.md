# Implementation Plan: Shift Assignment Page

## Overview

Build the shift assignment page using a foundation-first approach: route and page skeleton first, then state management, then UI components top-to-bottom (floating bar → config → overview → tables), then integration (save/cancel/import/export), and finally cleanup of the old modal code. Each phase produces a testable increment.

### Implementation Strategy
- Reuse existing API hooks and helpers — no backend changes
- Build the page shell with real data loading before adding interactive components
- AssignmentTable is the most complex component — build it incrementally (static render → row menu → bulk action → error display)
- Defer old code cleanup to the final phase after the new page is fully verified

## Implementation Plan

### Phase 1: Route and Page Skeleton

- [ ] 1. Create page route and skeleton
    - [ ] 1.1 Add `MANAGE_SHIFT_DETAIL` to `RouteConfig` in `frontend/src/configs/route.config.ts` with path `/manage/shift/[id]` and `SHIFT_EDIT` permission
        - _Requirements: 1.1, 1.3_

    - [ ] 1.2 Create `frontend/src/pages/manage/shift/[id].page.tsx` with basic layout: load shift data via `useGetShiftDetailQuery`, show loading/error states, render page title with workplace name, shift time, and date
        - Use `useRouter` to extract `id` from route params
        - Check `SHIFT_EDIT` permission, show 403 if missing
        - _Requirements: 1.1, 1.4_

    - [ ] 1.3 Create `frontend/src/pages/manage/shift/[id].module.css` with flexbox layout: panel container with `height: calc(100dvh - offset)`, `overflow: hidden`, `display: flex`, `flex-direction: column`; scrollContent with `flex: 1`, `overflow-y: auto`
        - Match the pattern from `frontend/src/pages/admin/user/[id].module.css`
        - _Requirements: 2.1_

    - [ ] 1.4 Update shift timeline row item click handler to navigate to `/manage/shift/[id]` instead of opening the update modal
        - Modify `frontend/src/pages/manage/shift/partials/shift_timeline_row_item.tsx`
        - _Requirements: 1.2_

- [ ] 2. Checkpoint — Page loads and displays shift info
    - Navigate to `/manage/shift/{id}`, verify data loads, title renders, and permission check works.

### Phase 2: State Management and Floating Action Bar

- [ ] 3. Initialize assignment state
    - [ ] 3.1 In `[id].page.tsx`, call `categorizeUsers` from `shift.helper.ts` to derive `groupUsers`, `userGroups`, `users` from API response combining both fixed and temp shift_users
        - Initialize on data load, re-initialize on cancel/refetch
        - _Requirements: 5.1_

    - [ ] 3.2 Implement `moveUsers(userIds: string[], targetGroup: string)` function that atomically updates both `groupUsers` and `userGroups` state
        - Remove each userId from its current group in `groupUsers`
        - Add each userId to the target group in `groupUsers`
        - Update each userId's entry in `userGroups`
        - Wrap in `useCallback`
        - _Requirements: 9.5, 10.3_

    - [ ] 3.3 Implement save handler: split combined `groupUsers` into `fixed` and `temp` payloads by checking `users[id].is_fixed`, construct `ShiftUpdatePayload`, call `useUpdateShiftMutation`
        - Reuse payload construction logic from `modal.tsx` `onFormSubmit`
        - On success: refetch shift detail
        - On 422: parse errors via `setFormErrors` from `helper.ts`, store in error state
        - _Requirements: 2.2, 11.1, 11.2_

- [ ] 4. Build FloatingActionBar
    - [ ] 4.1 Create the floating bar as a `Paper` component with `flexShrink: 0` at the top of the page layout, containing: Import CSV button (left), Export Excel button (left), Cancel button (right), Save button (right, primary)
        - Hide Import if no `SHIFT_IMPORT` permission
        - Hide Export if no `SHIFT_EXPORT` permission
        - Disable Save and Import if shift has ended
        - Show loading state on Save button during mutation
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8_

    - [ ] 4.2 Wire Cancel to refetch shift detail data and re-initialize state from fresh response
        - _Requirements: 2.3_

    - [ ] 4.3 Wire Import CSV button to open existing `ImportCsvModal` (import from current path), wire Export button to call existing `exportTimesheet` API util
        - _Requirements: 2.4, 2.5_

- [ ] 5. Checkpoint — Floating bar renders, save/cancel/import/export wired
    - Verify buttons appear/hide based on permissions, Cancel refetches data, Export downloads file.

### Phase 3: Config Section and Position Overview

- [ ] 6. Build ConfigSection
    - [ ] 6.1 Add config inputs (fixed_total, temp_total, max_registered_temp, break_minutes) as a horizontal row of `NumberInput` fields above the scroll content area, bound to Mantine `useForm`
        - Use `SimpleGrid` or `Group` for horizontal layout
        - Set read-only if shift has ended
        - Populate from shift detail API response
        - _Requirements: 3.1, 3.2, 3.3_

- [ ] 7. Build PositionOverview
    - [ ] 7.1 Create `frontend/src/pages/manage/shift/partials/position_overview.tsx` — render a `SimpleGrid` of `Paper`/`Card` components, one per position
        - Each card: position name, count display `{fixedCount}F + {tempCount}T / {total}`, salary `¥{salary}/h`
        - Derive counts from `groupUsers[positionId]` filtered by `users[id].is_fixed`
        - _Requirements: 4.1, 5.4_

    - [ ] 7.2 Add success indicator (green) when position is fully staffed, warning indicator (orange) when zero assignments
        - Use Mantine `ThemeIcon` or colored border/badge
        - _Requirements: 4.2, 4.3_

    - [ ] 7.3 Verify real-time count updates when `groupUsers` state changes
        - Counts are derived via `useMemo` from `groupUsers` — no extra wiring needed
        - _Requirements: 4.4_

- [ ] 8. Checkpoint — Config inputs and position cards render with correct data
    - Verify config values populate from API, position cards show correct counts.

### Phase 4: Assignment Tables

- [ ] 9. Build AssignmentTable component (static rendering)
    - [ ] 9.1 Create `frontend/src/pages/manage/shift/partials/assignment_table.tsx` — a reusable component accepting `groupId`, `title`, `userIds`, `users`, `positions`, `onMove`, `isReadOnly`, optional `onAddUsers` and `errors` props
        - Use `DataTable` from `mantine-datatable`
        - Columns: `#` (row index), Name (with avatar if available), Staff No, Type badge (固定/派遣), Action
        - Sort rows: fixed users first (`is_fixed` descending), then temp users
        - _Requirements: 6.1, 7.1, 8.1_

    - [ ] 9.2 Apply distinct row background for temp users via `rowStyle` callback — default for `is_fixed === 1`, `var(--mantine-color-blue-0)` for temp
        - _Requirements: 5.2, 5.3_

    - [ ] 9.3 Show table title with count in header (e.g., "登録リスト (12)", "組立A (3/5)")
        - For position tables, show fill count vs total
        - _Requirements: 6.3, 7.2_

    - [ ] 9.4 Show empty state message when `userIds` is empty
        - _Requirements: 6.2, 8.2_

    - [ ] 9.5 Render "Add Users" button in position table headers, wire to `onAddUsers` callback
        - Only shown when `onAddUsers` prop is provided (position tables only)
        - _Requirements: 7.3_

- [ ] 10. Add PositionMenu (row-level action)
    - [ ] 10.1 Create `frontend/src/pages/manage/shift/partials/position_menu.tsx` — Mantine `Menu` with `trigger="hover"`, `openDelay={100}`, `closeDelay={200}`
        - Menu.Target: Button with text "配置"
        - Menu.Dropdown: one Menu.Item per position (excluding current group), divider, "登録リスト" (if current is position/rejected), "不採用" (if current is not rejected)
        - Calls `onSelect(targetGroup)` when item clicked
        - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5_

    - [ ] 10.2 Hide action column entirely when `isReadOnly` is true
        - _Requirements: 9.6_

- [ ] 11. Add bulk action
    - [ ] 11.1 Add checkbox selection to AssignmentTable using `selectedRecords` prop of DataTable
        - Header "Select All" checkbox, per-row checkboxes
        - _Requirements: 10.1_

    - [ ] 11.2 When rows are selected, show bulk action controls in table header: a Select dropdown listing all target groups (positions + pending + rejected, excluding current) and an execute button
        - _Requirements: 10.2_

    - [ ] 11.3 Wire execute button to call `onMove(selectedIds, targetGroup)`, then clear selection
        - _Requirements: 10.3, 10.4_

- [ ] 12. Add error display
    - [ ] 12.1 When `errors` prop contains entries for users in this table, highlight those rows with `var(--mantine-color-red-0)` background via `rowStyle`
        - _Requirements: 11.1_

    - [ ] 12.2 Render error messages below the table as a list, each showing user name and error description
        - Reuse error code → message mapping from `helper.ts`
        - _Requirements: 11.2_

    - [ ] 12.3 Clear error highlight for a user when they are moved to a different group
        - Remove userId from error state in `moveUsers` handler
        - _Requirements: 11.3_

- [ ] 13. Checkpoint — All tables render with correct data, row menu and bulk action work
    - Test: move user via menu, bulk move, verify position overview updates, verify error display.

### Phase 5: Integration and Polish

- [ ] 14. Wire page sections together
    - [ ] 14.1 In `[id].page.tsx`, render all sections in order: FloatingActionBar, ScrollableContent containing ConfigSection, PositionOverview, PendingTable (AssignmentTable with groupId="pending"), PositionTables (one AssignmentTable per position sorted by sort_order), RejectedTable (AssignmentTable with groupId="rejected")
        - _Requirements: 6.1, 7.1, 8.1_

    - [ ] 14.2 Wire AddUserModal to position tables — open modal scoped to clicked position, on user selection add to `groupUsers[positionId]` and `users` state
        - Reuse existing `add_user_modal.tsx`
        - _Requirements: 7.3, 7.4_

    - [ ] 14.3 Wire CSV import flow — on successful import, merge `valid_data` into `groupUsers` by position, add `users_in_workplace` to `users` state, display import errors in alert
        - Reuse logic from `modal.tsx` import handler
        - _Requirements: 11.4_

    - [ ] 14.4 Handle save validation errors — on 422 response, parse errors and set per-user error state, pass to affected AssignmentTable instances via `errors` prop
        - _Requirements: 11.1, 11.2_

- [ ] 15. Checkpoint — Full page integration works end-to-end
    - Test full workflow: load page, modify assignments, save, verify API call, handle errors, import CSV, export Excel.

### Phase 6: Cleanup

- [ ] 16. Remove old modal code and unused dependencies
    - [ ] 16.1 Delete modal-specific files: `modal.tsx`, `modal_assign.tsx`, `draggable_user_pill.tsx`, `droppable_fieldset.tsx`, `modal.module.css` from `frontend/src/pages/manage/shift/partials/update/`
        - Keep `helper.ts`, `add_user_modal.tsx`, `modal_import_csv.tsx` (still used)
        - _Requirements: 12.2, 12.3_

    - [ ] 16.2 Remove the `ShiftUpdateModal` usage from `shift_timeline.tsx` — remove modal state, import, and rendering
        - _Requirements: 12.3_

    - [ ] 16.3 Check if `@dnd-kit/core` is used elsewhere in the codebase; if not, remove from `package.json` and run `pnpm install`
        - Search for `@dnd-kit` imports across all files
        - _Requirements: 12.1, 12.4_

    - [ ] 16.4 Move `add_user_modal.tsx` and `modal_import_csv.tsx` from `partials/update/` to `partials/` since the `update/` directory context no longer applies
        - Update import paths in `[id].page.tsx`
        - _Requirements: 12.2_

- [ ] 17. Checkpoint — No broken imports, no unused code
    - Run `pnpm typecheck` to verify no type errors from removed files.

### Phase 7: Final Validation

- [ ] 18. Code Quality
    - [ ] 18.1 Run `pnpm prettier` in `frontend/` to format code
    - [ ] 18.2 Run `pnpm typecheck` in `frontend/` to verify no type errors

## Notes

- **Ordering rationale**: Route and skeleton first to enable manual testing early. State management before UI so components can be wired immediately. Tables are the most complex piece — built incrementally (render → menu → bulk → errors). Cleanup is last to avoid breaking the app during development.
- **Risk: `categorizeUsers` adaptation**: The existing helper separates fixed and temp users. The new page combines them into one view. May need a wrapper or modified version that merges both into a single `groupUsers` map. Verify during Phase 2.
- **Risk: Payload split on save**: The combined view must correctly split users back into `fixed` and `temp` payloads. Off-by-one in `is_fixed` check (it's `0 | 1` not `boolean`) could cause silent data corruption. Add explicit type guard.
- **Convention**: All new files use `.tsx` extension (not `.page.tsx`) except the page file itself. Partials go in `frontend/src/pages/manage/shift/partials/`.
- **No new API endpoints**: If any integration issue arises from reusing existing endpoints, flag immediately rather than working around it.
