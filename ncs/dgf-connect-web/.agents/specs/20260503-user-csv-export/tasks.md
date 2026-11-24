# Implementation Plan: User CSV Export

## Overview

Implementation follows a **foundation-first** order: backend data layer (migration, registry, models) → backend job/controller → frontend API hooks → frontend modal and trigger → frontend history/detail/sidebar updates → tests → final code quality. Backend lands first because the property registry is the single source of truth and the frontend needs the registry endpoint and request shape settled before its modal can be built. Within each phase, smaller building blocks (registry, resolver) come before consumers (job, controller). Tests are interleaved per phase as well as consolidated at the end.

### Implementation Strategy
- Build the property registry and resolver as pure, easily-unit-testable services first; they are the most error-prone surface (31 fields × 4 type families).
- Migrate `export_batches` early so all downstream backend work has the schema it needs.
- Reuse the existing PDF export flow's patterns (controller, action, job, route prefix) to keep PR review easy.
- Move sidebar/breadcrumbs last so reviewers can see the whole feature working before navigation moves.
- Run translation flow (`pnpm trans:prepare` → fill ja/vi → `pnpm trans:apply`) once at the end of frontend work.

## Implementation Plan

### Phase 1: Backend Data Layer

- [x] 1. Database migration to extend `export_batches`
    - [x] 1.1 Create migration `extend_export_batches_for_csv`
        - Add `type ENUM('pdf','csv') NOT NULL DEFAULT 'pdf'` after `id`
        - Add `file_path VARCHAR(500) NULL` after `zip_file_paths`
        - Add `meta JSON NULL` after `file_path`
        - Add `error_message TEXT NULL` after `status`
        - Add `csv_total_count INT NOT NULL DEFAULT 0`, `csv_completed_count INT NOT NULL DEFAULT 0`, `csv_failed_count INT NOT NULL DEFAULT 0` (authoritative for csv rows)
        - Modify `pdf_template_id` to nullable
        - _Requirements: 6.1, 6.10_

    - [x] 1.2 Update `ExportBatch` model
        - Add `type`, `file_path`, `meta` to casts (`type => ExportBatchType::class`, `meta => 'array'`)
        - Update accessors: `getTotalCountAttribute`, `getCompletedCountAttribute`, `getFailedCountAttribute` to read raw CSV-specific columns (`csv_total_count`, `csv_completed_count`, `csv_failed_count`) when `type=csv` and from items when `type=pdf` (preserve existing PDF behavior)
        - Add `isCsv(): bool` and `isPdf(): bool` helpers
        - _Requirements: 6.1, 6.9, 6.10_

    - [x] 1.3 Create `ExportBatchType` enum at `backend/app/Enums/ExportBatchType.php`
        - Cases: `Pdf = 'pdf'`, `Csv = 'csv'`
        - _Requirements: 6.1_

- [x] 2. CSV Property Registry and Resolver
    - [x] 2.1 Create `CsvPropertyDefinition` DTO at `backend/app/Services/Csv/CsvPropertyDefinition.php`
        - Fields: `key`, `label_ja`, `source` ('user' | 'user_info'), `column`, `type` ('string' | 'date' | 'boolean' | enum class FQN)
        - _Requirements: 3.2, 5.3_

    - [x] 2.2 Create `CsvPropertyRegistry` at `backend/app/Services/Csv/CsvPropertyRegistry.php`
        - Define all 31 canonical properties with Japanese labels in user_form.tsx display order
        - Methods: `all(): array<string, CsvPropertyDefinition>`, `validateKeys(array $keys): array $unknownKeys`, `defaultOrderedKeys(): array<string>`
        - _Requirements: 3.1, 3.2, 9.5_

    - [x] 2.3 Create `CsvPropertyResolver` at `backend/app/Services/Csv/CsvPropertyResolver.php`
        - Method `resolve(User $user, string $key): string`
        - String → as-is; Date → `Y/m/d`; Enum → `EnumHelper::getText(...)` (Japanese); Boolean → `はい`/`いいえ`/empty; Null → empty
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

- [x] 3. Checkpoint - Run unit tests for registry/resolver and migration
    - Ensure migration applies cleanly; existing PDF tests still pass with hydrated columns
    - Verify resolver handles every type variant

### Phase 2: Backend Job and HTTP Layer

- [x] 4. CSV Export Job
    - [x] 4.1 Create `CsvExportJob` at `backend/app/Jobs/CsvExportJob.php`
        - Constructor takes `int $batchId`; `tries = 3`; `backoff = [10, 30, 60]`
        - `handle()`: set status to processing, resolve users, write rows to temp file, upload to S3, update batch row
        - _Requirements: 6.3, 6.4, 6.5, 6.7_

    - [x] 4.2 Implement user resolution in job
        - From `meta.user_ids` (when not select_all): `User::whereIn('id', ...)->with('info')->cursor()`
        - From `meta.filters` (when select_all): re-apply existing user-list filter scope; cursor
        - Chunk 500 at a time
        - _Requirements: 6.2, NFR Performance_

    - [x] 4.3 Implement CSV writing using `league/csv`
        - `Writer::createFromPath($tmp, 'w+')`; `setOutputBOM(BOM::UTF8)`; `setNewline("\r\n")`
        - First row: Japanese labels for `ordered_keys` from `CsvPropertyRegistry`
        - For each user: row of `CsvPropertyResolver::resolve(user, key)` values for each `export_keys` entry
        - File name: `users_export_{Y_m_d_His}.csv`
        - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6_

    - [x] 4.4 Implement S3 upload + retry + cleanup
        - Upload temp file via S3 disk; on transient failure, throw to trigger retry
        - On success: store path in `file_path`, set `status=completed`, `csv_completed_count=csv_total_count`, `csv_failed_count=0`
        - `finally`: delete temp file
        - On unrecoverable failure: let Laravel retry normally; implement `failed(Throwable $e)` to set `status=failed`, persist `error_message`, and leave no S3 upload
        - _Requirements: 6.6, 6.7, NFR Reliability_

- [x] 5. CSV Export Action
    - [x] 5.1 Create `CreateCsvExportAction` at `backend/app/Actions/Csv/CreateCsvExportAction.php`
        - `execute(int $exportedBy, array $payload): ExportBatch`
        - Validate `ordered_keys` against `CsvPropertyRegistry::validateKeys()`
        - Compute `csv_total_count` (count of `user_ids` or filter-resolved query count)
        - Derive `export_keys` by filtering `ordered_keys` to keys present in `selected_keys`
        - DB transaction: `ExportBatch::create([type=csv, status=pending, csv_total_count=..., meta=...])`, then `dispatch(new CsvExportJob($batch->id))`
        - _Requirements: 6.1, 6.2, 6.8_

- [x] 6. CSV Export HTTP Endpoints
    - [x] 6.1 Create `CreateCsvExportRequest` FormRequest
        - Validation: `selected_keys: array|min:1`, `ordered_keys: array|min:1`, `user_ids: array|nullable`, `user_ids.*: integer|exists:users,id`, `select_all: boolean`, `filters: array|nullable`
        - Custom rule: `selected_keys` ⊆ `ordered_keys`
        - Custom rule: `select_all` XOR non-empty `user_ids`
        - Custom rule: all `ordered_keys` must be in registry
        - Do not add a CSV-specific `user_ids` max; match the existing PDF export request behavior
        - _Requirements: 2.6, 6.1, NFR Security_

    - [x] 6.2 Create `CsvExportController` at `backend/app/Http/Controllers/Admin/CsvExportController.php`
        - `store(CreateCsvExportRequest)`: call `CreateCsvExportAction`, return batch resource
        - `show(int $id)`: return batch with meta
        - `download(int $id)`: ensure `status=completed`, return signed S3 URL JSON
        - _Requirements: 6.8, 7.7, 7.8, 7.9_

    - [x] 6.3 Create `GetCsvPropertiesController` (or method) returning the registry
        - Endpoint shape: `[{ key, label_ja }]` ordered by default order
        - _Requirements: 3.1, 3.2_

    - [x] 6.4 Register routes in `backend/routes/api.php`
        - `POST /admin/csv-export/batch` → `CsvExportController@store` with backend permission value `user.csv.export`
        - `GET /admin/csv-export/batch/{id}` → `show` with backend permission value `user.csv.export`
        - `GET /admin/csv-export/batch/{id}/download` → `download` with backend permission value `user.csv.export`
        - `GET /admin/csv-export/properties` → registry, with backend permission value `user.csv.export`
        - _Requirements: 9.1, 9.2_

- [x] 7. Extend Export History endpoint and PDF download permission gating
    - [x] 7.1 Create or migrate to a unified export history controller/action
        - Preferred: create `ExportHistoryController` backed by a shared `GetExportHistoryAction`; keep existing PDF history route as a compatibility alias if needed
        - Accept `?type=pdf|csv` query param; filter `WHERE type = ?` when present
        - Scope rows by caller permission: only PDF rows for `PDF_TEMPLATE_LIST`-only users, only CSV rows for `USER_EXPORT_CSV`-only users, both for users with both permissions
        - Include `type`, `file_path`, `meta` in response payload
        - _Requirements: 7.1, 7.2, 7.3, 7.4_

    - [x] 7.2 Update PDF batch detail/download endpoints to be visible to history viewers
        - Keep PDF batch detail visible only with `PDF_TEMPLATE_LIST`; download still requires `PDF_TEMPLATE_EXPORT`
        - _Requirements: 9.3_

- [x] 8. Permission seeding
    - [x] 8.1 Add `USER_EXPORT_CSV` permission to the permission seeder
        - Add backend enum case `UserExportCsv = 'user.csv.export'` to `backend/app/Enums/PermissionName.php`
        - Register under the existing user-permissions seeding section
        - Preserve custom Admin role assignments per existing reseed pattern
        - _Requirements: 9.1, 9.5_

    - [x] 8.2 Add frontend permission definitions
        - Add `USER_EXPORT_CSV` to the `PermissionName` union in `frontend/src/enums/permission_name.enum.ts`
        - Add `USER_EXPORT_CSV: { value: 'user.csv.export', text: 'User CSV Export' }` to `PermissionNameEnum`
        - Add `t('role|user.csv.export')` to `frontend/src/locales/for_scanner.ts`
        - _Requirements: 9.11, 9.12_

- [x] 9. Checkpoint - Backend integration smoke test
    - Run `docker compose exec php ./vendor/bin/phpunit --filter Csv`
    - Manually POST a CSV export via curl/Tinker and verify batch row + queued job

### Phase 3: Frontend - API and Modal

- [x] 10. Frontend types and API hooks
    - [x] 10.1 Add types
        - `frontend/src/types/models/csv_export.ts`: `CsvExportBatch`, `CsvPropertyMeta`
        - Extend `ExportBatch` type to include `type`, `file_path`, `meta`
        - _Requirements: 6.1, 7.1_

    - [x] 10.2 Create API hooks at `frontend/src/pages/admin/csv-export/api.ts`
        - `useGetCsvPropertiesQuery` (staleTime: Infinity)
        - `useCreateCsvExportMutation`
        - `useGetCsvExportBatchQuery(id, options)` with polling when status is pending/processing
        - `useGetCsvExportDownloadUrlMutation`
        - _Requirements: 2.1, 6.3, 7.10_

    - [x] 10.3 Extend export-history hook
        - `useGetExportHistoryQuery` accepts `type` query param
        - _Requirements: 7.1, 7.3_

    - [x] 10.4 Add `ExportBatchTypeEnum` at `frontend/src/enums/export_batch_type.enum.ts`
        - Values `pdf` / `csv` with badge color helper
        - _Requirements: 7.2_

- [x] 11. Property selection modal
    - [x] 11.1 Reuse existing drag-and-drop dependency
        - Use `@hello-pangea/dnd`, already present in `frontend/package.json`
        - Follow the existing `DragDropContext` / `Droppable` / `Draggable` pattern from `frontend/src/pages/admin/workplace/partials/workplace_position.tsx`
        - Do not add `@dnd-kit/*`
        - _Requirements: 2.4_

    - [x] 11.2 Create `ExportCsvModal` at `frontend/src/pages/admin/user/partials/export_csv_modal.tsx`
        - Props identical to `ExportPdfModal`: `{ isOpened, onClose, selectedUserIds, selectAll, totalUsers, filters }`
        - Display selected user count text (mirror PDF modal)
        - Render header: "Reset to default" button + selected/total counter
        - Render `DragDropContext` with `Droppable` and `Draggable` rows over the `ordered` array
        - Each row: drag handle, checkbox, label
        - Footer: Cancel + Export button
        - _Requirements: 1.3, 1.4, 1.5, 2.1, 2.2, 2.3, 2.4_

    - [x] 11.3 Implement localStorage logic
        - Define `USER_CSV_EXPORT_CONFIG_KEY = 'user_csv_export_config'`
        - On mount: read JSON; reconcile with canonical keys (drop unknown, append missing as deselected); fall back to default on parse error
        - On Export click: write current `{ selected, order }` to localStorage, then call mutation
        - Reset to default: replace in-modal state only; don't touch localStorage
        - _Requirements: 2.8, 2.9, 10.1, 10.2, 10.3, 10.4, 10.5, 10.6_

    - [x] 11.4 Derive `Default_Csv_Config` from the registry endpoint
        - `{ selected: all registry keys, order: all registry keys }` in registry order
        - _Requirements: 3.2, 9.x (Reset to default)_

    - [x] 11.5 Wire submit + success toast
        - Build payload (`select_all` vs `user_ids` + `filters`) from props
        - On success: close modal, show toast with link to `ADMIN_CSV_EXPORT_BATCH_DETAIL`
        - On 422/403/etc: show error notification
        - _Requirements: 1.4, 1.5, 6.8, 9.2_

- [x] 12. Trigger button on user list
    - [x] 12.1 Add `useDisclosure` for CSV modal in user list page
        - Render "Export CSV" button next to existing "Export PDF" button
        - Gate visibility on `USER_EXPORT_CSV` permission via `usePermissions`
        - _Requirements: 1.1, 1.2_

    - [x] 12.2 Pass selection props to `ExportCsvModal`
        - Reuse the same `selectedUserIds`, `selectAll`, `totalUsers`, `filters` already passed to `ExportPdfModal`
        - _Requirements: 1.4, 1.5_

### Phase 4: Frontend - History and Detail

- [x] 13. Update Export History page
    - [x] 13.1 Add Type column to the data table
        - Render badge using `ExportBatchTypeEnum` color helper
        - _Requirements: 7.2_

    - [x] 13.2 Add Type filter to `CommonSearchBox`
        - Options: All / PDF / CSV
        - Hide template_id select when type === 'csv'
        - _Requirements: 7.3, 7.4, 7.5_

    - [x] 13.3 Update Template column to show `-` for CSV rows
        - _Requirements: 7.6_

    - [x] 13.4 Update View link to route to CSV detail page when `type=csv`
        - Use `RouteHelper.getPath('ADMIN_CSV_EXPORT_BATCH_DETAIL', { id })` for csv rows
        - _Requirements: 7.7_

- [x] 14. CSV batch detail page
    - [x] 14.1 Create `frontend/src/pages/admin/csv-export/batch/[id].page.tsx`
        - Fetch via `useGetCsvExportBatchQuery` with auto-refresh while pending/processing
        - Display: ID, status badge, exported_by, total_count, created_at, error_message
        - Selected/exported keys: render `export_keys` as chip list in CSV output order
        - User selection summary: "N selected users" or "All users matching filters: ..."
        - Download button: enabled when status=completed; on click, call download mutation and trigger browser download
        - _Requirements: 7.7, 7.8, 7.9, 7.10_

    - [x] 14.2 Register route in `RouteConfig`
        - `ADMIN_CSV_EXPORT_BATCH_DETAIL` with path `/admin/csv-export/batch/[id]`
        - _Requirements: 7.7_

- [x] 15. Sidebar reorganization
    - [x] 15.1 Move Export history sidebar entry
        - In `frontend/src/configs/route.config.ts`: add `ADMIN_USER_EXPORT_HISTORY` with path `/admin/user/export-history`
        - Move the page from `frontend/src/pages/admin/pdf-template/export-history/index.page.tsx` to `frontend/src/pages/admin/user/export-history/index.page.tsx`
        - In `frontend/src/configs/sidebar.config.ts`: add `ADMIN_USER_EXPORT_HISTORY` to the "User" group after "Face registration" and remove the PDF-template-group entry
        - Keep `ADMIN_PDF_EXPORT_HISTORY` only as a temporary compatibility alias/redirect if existing links need migration
        - _Requirements: 8.1, 8.2_

    - [x] 15.2 Update breadcrumbs on Export History page and PDF/CSV detail pages
        - `Home → Users → Export history` (replace existing `Home → PDF Templates → Export History`)
        - _Requirements: 8.4_

    - [x] 15.3 Hide sidebar entry when user has neither `PDF_TEMPLATE_LIST` nor `USER_EXPORT_CSV`
        - _Requirements: 9.4_

- [x] 16. Checkpoint - Frontend smoke test
    - Manually: trigger CSV export, verify modal persists config, verify history shows new row with type badge, verify detail page polls and shows download
    - Verify Export PDF flow still works unchanged

### Phase 5: Translations

- [x] 17. Translation flow
    - [x] 17.1 Run `pnpm trans:prepare` in `./frontend`
        - Captures any new keys added (button labels, modal text, validation messages, table columns, filter labels)

    - [x] 17.2 Fill ja/vi translations in `.translation-workflow/translation-batch.json`
        - Preserve placeholders like `{{count}}`

    - [x] 17.3 Run `pnpm trans:apply`
        - Patches `frontend/src/locales/vi/translation.json` and `ja/translation.json`

### Phase 6: Tests

- [x] 18. Backend tests
    - [x] 18.1 `backend/tests/Unit/Services/Csv/CsvPropertyRegistryTest.php`
        - 31 keys returned in documented order
        - `validateKeys` accepts known, rejects unknown
        - All definitions point to valid model + column
        - _Requirements: 3.1, 3.2_

    - [x] 18.2 `backend/tests/Unit/Services/Csv/CsvPropertyResolverTest.php`
        - String/date/enum/boolean/null cases
        - RFC 4180 escaping handled by writer (assert via integration on output)
        - camelCase test methods per CLAUDE.md
        - _Requirements: 4.1–4.5_

    - [x] 18.3 `backend/tests/Unit/Jobs/CsvExportJobTest.php`
        - Status transitions (pending → processing → completed)
        - User resolution from user_ids and from filters
        - File contents: BOM bytes, CRLF, header row Japanese, column order matches `export_keys`
        - On row exception: status=failed, no S3 upload, temp file removed
        - On S3 failure final retry: status=failed
        - _Requirements: 5.1–5.6, 6.4–6.7_

    - [x] 18.4 `backend/tests/Feature/Admin/CsvExportControllerTest.php`
        - 403 without permission
        - 422 cases: empty selected_keys, unknown key, both/neither user_ids and select_all
        - 200: creates batch, dispatches job (Queue::fake), returns id
        - GET show returns meta
        - GET download: 409 when not completed, 200 with signed URL when completed
        - _Requirements: 6.1, 6.2, 6.8, 7.7, 7.8, 7.9, 9.2, NFR Security_

    - [x] 18.5 `backend/tests/Feature/Admin/ExportHistoryControllerTest.php`
        - Returns both pdf and csv rows when no type filter
        - Filters correctly when ?type=csv or ?type=pdf
        - CSV row in response has type, file_path, meta
        - Permission-scoped row visibility: PDF-only, CSV-only, and both-permission users
        - _Requirements: 7.1, 7.3, 7.4_

- [x] 19. Frontend tests
    - [x] 19.1 `ExportCsvModal` tests
        - localStorage load/fallback to default
        - Toggle/reorder updates state
        - Reset to default replaces state but not localStorage
        - Submit writes localStorage and calls mutation
        - Export button disabled when zero selected
        - _Requirements: 2.3–2.9, 10.1–10.6_

### Phase 7: Final Validation

- [x] 20. Code Quality
    - [x] 20.1 Backend: run `npm run prettier` and `npm run pint` in `./backend`; fix any issues
    - [x] 20.2 Frontend: run `pnpm lint:fix`, `pnpm prettier`, and `pnpm typecheck` in `./frontend`; fix any issues
    - [x] 20.3 Verify no leftover console.logs, debug code, or commented-out blocks
    - [x] 20.4 Run full backend suite once: `docker compose exec php ./vendor/bin/phpunit`

- [x] 21. Manual end-to-end pass
    - [x] 21.1 Login as admin with both permissions: see both buttons; export PDF still works.
    - [x] 21.2 Login as admin with only `USER_EXPORT_CSV`: only Export CSV visible; Export History shows CSV rows only.
    - [x] 21.3 Export CSV with custom selection + reorder; close and reopen modal; verify config restored.
    - [x] 21.4 Click Reset to default; verify all 31 fields selected in default order.
    - [x] 21.5 Open downloaded CSV in Japanese-locale Excel; verify Japanese headers, no mojibake, proper date/enum/boolean rendering.
    - [x] 21.6 Filter Export History by Type; verify counts and Template column.
    - [x] 21.7 Verify breadcrumbs on Export History show `Home → Users → Export history`.

## Notes

- **Foundation-first ordering**: registry/resolver are pure and unit-testable; they bear the most risk (31 fields × type variations) and must be solid before the job depends on them.
- **Migration risk**: the change to `pdf_template_id` (nullable) and the new `csv_*_count` columns interact with the existing PDF flow. The accessor preference rule (PDF reads from items, CSV reads from CSV-specific columns) keeps existing PDF tests stable and avoids adding DB columns with the same names as appended accessors; covered explicitly by 1.2 + the existing PDF test suite run in 20.4.
- **Sidebar move risk**: moving the sidebar entry without renaming the route constant means PDF detail/PDF history breadcrumbs also change to `Users → Export history`. That's the intent (unified history), but reviewers may flag it; documented in `decisions.md#q4`.
- **localStorage forward-compatibility**: 10.3 (drop unknown / append missing) is essential — without it, adding a new field in a future release would silently exclude it from existing admins' configs.
- **Translation pass at the end**: per CLAUDE.md, code first, then `pnpm trans:prepare` once. Doing it earlier wastes effort on keys that may still churn.
- **Out of scope but worth noting**: named presets, server-side preset sharing, row caps, multiple file output, and Excel (.xlsx) format are explicitly excluded; revisit per `decisions.md#future-revisions` if requested.
