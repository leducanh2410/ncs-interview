# Implementation Plan: PDF Template Export

## Overview

This implementation follows a foundation-first strategy: database schema and models first, then backend services and API endpoints, then frontend pages and editor. The PDF generation engine (FPDI + TCPDF) is tackled early as it's the highest-risk component. The visual field editor is the most complex frontend piece and is built incrementally — basic rendering first, then interaction layers. The async export system and locking mechanism are built after the core generation engine is validated.

### Implementation Strategy
- Foundation-first: migrations → models → config → services → controllers → routes → frontend
- Risk-first for PDF generation: validate FPDI+TCPDF approach early with a minimal proof-of-concept
- Incremental editor: PDF rendering → field display → draw → select/move → resize → copy/paste → zoom
- Backend fully functional before frontend integration to enable API testing independently
- Export flow built from user list screen, async from the start

## Implementation Plan

### Phase 1: Database & Models

- [x] 1. Database migrations
    - [x] 1.1 Create `pdf_templates` migration
        - Columns: `id`, `name` (unique), `file_path`, `file_name`, `page_count`, `created_at`, `updated_at`, `deleted_at` (soft-delete)
        - Index on `deleted_at`
        - _Requirements: 1.1, 1.2, 1.3_

    - [x] 1.2 Create `pdf_template_fields` migration
        - Columns: `id`, `pdf_template_id` (FK cascade), `page`, `shape` (VARCHAR 20, default 'rectangle'), `x`, `y`, `width`, `height` (DECIMAL 8,4), `data_source_key`, `placeholder` (VARCHAR 255 nullable), `fill_mode` (VARCHAR 20, default 'text'), `text_config` (JSON nullable), `shape_config` (JSON nullable), `created_at`, `updated_at`
        - Index on `(pdf_template_id, page)`
        - _Requirements: 2.9, 4.1, 5.1_

    - [x] 1.3 Create `export_batches` migration
        - Columns: `id`, `pdf_template_id` (FK cascade), `exported_by` (FK users), `status` (VARCHAR 20, default 'pending'), `zip_file_path` (nullable), `created_at`, `updated_at`
        - Indexes on `pdf_template_id` and `status`
        - _Requirements: 8.1, 9.1_

    - [x] 1.4 Create `export_batch_items` migration
        - Columns: `id`, `export_batch_id` (FK cascade), `user_id` (FK users), `status` (VARCHAR 20, default 'pending'), `file_path` (nullable), `file_name` (nullable), `error_message` (text nullable), `created_at`, `updated_at`
        - Indexes on `export_batch_id`, `user_id`, `(export_batch_id, status)`
        - _Requirements: 8.4, 8.5, 9.2_

- [x] 2. Eloquent models
    - [x] 2.1 Create `PdfTemplate` model
        - Extends `AbstractModel`, `$guarded = []`
        - Uses `SoftDeletes` trait
        - Relationships: `hasMany` → PdfTemplateField, `hasMany` → ExportBatch
        - Appended attributes: `field_count` (count of fields), `lock_info` (from Redis via PdfTemplateLockService)
        - _Requirements: 1.1, 1.2, 1.3_

    - [x] 2.2 Create `PdfTemplateField` model
        - Extends `AbstractModel`, `$guarded = []`
        - Casts: `shape` → PdfTemplateFieldShape, `fill_mode` → PdfTemplateFieldFillMode, `text_config` → array, `shape_config` → array, `x`/`y`/`width`/`height` → float
        - Relationship: `belongsTo` → PdfTemplate
        - _Requirements: 2.9_

    - [x] 2.3 Create `ExportBatch` model
        - Extends `AbstractModel`, `$guarded = []`
        - Casts: `status` → ExportBatchStatus
        - Relationships: `belongsTo` → PdfTemplate, `belongsTo` → User (exported_by as `exporter`), `hasMany` → ExportBatchItem
        - Appended attributes: `total_count`, `completed_count`, `failed_count`
        - Methods: `isInProgress(): bool`, `isCompleted(): bool`
        - _Requirements: 8.1, 9.1_

    - [x] 2.4 Create `ExportBatchItem` model
        - Extends `AbstractModel`, `$guarded = []`
        - Casts: `status` → ExportBatchItemStatus
        - Relationships: `belongsTo` → ExportBatch, `belongsTo` → User
        - Scopes: `scopePending`, `scopeCompleted`, `scopeFailed`
        - _Requirements: 8.4, 8.5, 9.2_

    - [x] 2.5 Create enum classes
        - [x] 2.5.1 `PdfTemplateFieldShape` enum (app/Enums/PdfTemplateFieldShape.php)
            - Values: `Rectangle = 'rectangle'`, `Circle = 'circle'`
            - Cast in PdfTemplateField model
        - [x] 2.5.2 `PdfTemplateFieldFillMode` enum (app/Enums/PdfTemplateFieldFillMode.php)
            - Values: `Text = 'text'`, `Shape = 'shape'`
            - Cast in PdfTemplateField model
        - [x] 2.5.3 `ExportBatchStatus` enum (app/Enums/ExportBatchStatus.php)
            - Values: `Pending = 'pending'`, `Processing = 'processing'`, `Completed = 'completed'`, `Failed = 'failed'`
            - Cast in ExportBatch model
        - [x] 2.5.4 `ExportBatchItemStatus` enum (app/Enums/ExportBatchItemStatus.php)
            - Values: `Pending = 'pending'`, `Completed = 'completed'`, `Failed = 'failed'`
            - Cast in ExportBatchItem model

- [x] 3. Checkpoint — Verify migrations run and models load
    - Run `php artisan migrate` and verify tables created
    - Verify model relationships with tinker
    - Verify soft-delete works on PdfTemplate

### Phase 2: Backend Configuration & Permissions

- [x] 4. Data source configuration
    - [x] 4.1 Create `config/pdf_data_sources.php`
        - Define array of data sources, each with: `key`, `name`, `placeholder`, `type`, `group`, `resolver`
        - Include ~10 initial model_field sources: `user.email`, `user.name`, `user.name_kana`, `user.staff_no`, `user_info.birthday`, `user_info.phone`, `user_info.address`, `user_info.postal_code`, `user_info.nationality`, `user_info.residence_number_1`
        - Include fixed string sources: `fixed_company_name`, `fixed_company_address`
        - Include 2-3 custom_function examples: `is_household_head`, `user.full_address` (prefecture + address), `current_date_ymd` (date as custom function)
        - _Requirements: 3.1, 3.3, 3.4, 3.5_

    - [x] 4.2 Create `DataSourceResolver` service
        - Located at `app/Services/Admin/PdfTemplate/DataSourceResolver.php`
        - Method: `resolve(string $key, User $user): string|bool` — looks up key in config, executes resolver
        - Method: `resolveAll(array $keys, User $user): array` — batch resolve
        - Method: `getAll(): array` — returns all sources without resolver closures (for API response)
        - For `model_field`: use `data_get()` on user/userInfo with dot-notation path (handle null userInfo)
        - For `fixed`: return the configured string value
        - For `custom_function`: call the closure with `$user` and `$user->userInfo` (nullable)
        - _Requirements: 3.3, 3.4, 3.5_

- [x] 5. Permissions setup
    - [x] 5.1 Add permission enum values to `PermissionName`
        - `PdfTemplateList = 'pdf_template.list'`
        - `PdfTemplateCreate = 'pdf_template.create'`
        - `PdfTemplateUpdate = 'pdf_template.update'`
        - `PdfTemplateDelete = 'pdf_template.delete'`
        - `PdfTemplateExport = 'pdf_template.export'`
        - _Requirements: 1.5_

    - [x] 5.2 Create seeder or migration to insert permissions into DB
        - Add the 5 permissions to the permissions table
        - _Requirements: 1.5_

- [x] 6. Checkpoint — Verify config loads and permissions exist
    - Test `config('pdf_data_sources')` returns expected array
    - Test `DataSourceResolver` resolves a model_field, date, and custom_function
    - Verify permissions exist in DB

### Phase 3: Backend Template CRUD & Locking

- [x] 7. Form request classes
    - [x] 7.1 Create `StoreRequest` for template creation
        - Rules: `name` required/string/max:255/unique (among non-deleted), `file` required/mimes:pdf/max:5120
        - _Requirements: 1.1, 1.4_

    - [x] 7.2 Create `UpdateRequest` for template update
        - Rules: `name` required/string/max:255/unique (excluding current ID, among non-deleted)
        - _Requirements: 1.1_

    - [x] 7.3 Create `SaveFieldsRequest` for field sync
        - Rules: `fields` required/array, validate each field's page, shape, coordinates, data_source_key (must exist in config), placeholder (nullable, max 255), fill_mode, text_config/shape_config JSON structure
        - _Requirements: 2.9, 4.1, 4.2, 4.3, 4.4, 5.3_

    - [x] 7.4 Create `IndexRequest` for template listing
        - Rules: `name` nullable/string, `page` nullable/integer, `page_size` nullable/integer
        - _Requirements: 1.2_

    - [x] 7.5 Create `ReUploadRequest` for PDF re-upload
        - Rules: `file` required/mimes:pdf/max:5120
        - _Requirements: 1.6_

    - [x] 7.6 Create `LockRequest` for template locking
        - Rules: `reason` required/in:editing
        - Note: `exporting` reason is set automatically by the system when export is triggered, not via API
        - _Requirements: 10.1, 10.5_

- [x] 8. PdfTemplateService
    - [x] 8.1 Create `PdfTemplateService` at `app/Services/Admin/PdfTemplateService.php`
        - `list(array $conditions)`: paginated query with optional name filter, excludes soft-deleted, append field_count and lock info
        - `create(array $data)`: store PDF on S3, count pages (using a PDF parser), create DB row
        - `show(PdfTemplate $template)`: load with fields, generate signed URL
        - `update(PdfTemplate $template, array $data)`: check lock, update name
        - `delete(PdfTemplate $template)`: check lock, soft-delete
        - `syncFields(PdfTemplate $template, array $fields)`: check lock, delete existing fields, bulk insert new ones in transaction
        - `reUpload(PdfTemplate $template, UploadedFile $file)`: check lock, replace S3 file, update file_path/file_name/page_count
        - _Requirements: 1.1, 1.2, 1.3, 1.6, 2.9_

    - [x] 8.2 Implement PDF page count detection
        - Use `setasign/fpdi` to open the uploaded PDF and get page count
        - Store in `pdf_templates.page_count`
        - _Requirements: 1.1_

- [x] 8.3 Create PdfTemplateLockService
        - Located at `app/Services/Admin/PdfTemplate/PdfTemplateLockService.php`
        - Uses Laravel's `Cache` facade with Redis driver
        - Lock key pattern: `pdf_template:lock:{template_id}`
        - Lock value format: JSON `{user_id, reason, user_name, locked_at}`
        - TTL: 1800 seconds (30 minutes)
        - Methods:
          - `acquire(int $templateId, int $userId, string $reason, string $userName): bool` — SETNX or SET with NX
          - `release(int $templateId, int $userId): bool` — delete if owner matches
          - `refresh(int $templateId, int $userId): bool` — EXPIRE if owner matches
          - `get(int $templateId): ?array` — get lock info (user_id, reason, user_name, ttl_remaining)
          - `isLocked(int $templateId): bool`
          - `isLockedBy(int $templateId, int $userId): bool`
        - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6, 10.7, 10.8_

- [x] 9. PdfTemplateController
    - [x] 9.1 Create controller at `app/Http/Controllers/Admin/PdfTemplateController.php`
        - `index(IndexRequest)`: list templates (with lock_info from Redis)
        - `store(StoreRequest)`: create template
        - `show(PdfTemplate)`: get detail with fields, signed URL, and lock_info
        - `update(PdfTemplate, UpdateRequest)`: update template name (lock check via Redis)
        - `destroy(PdfTemplate)`: soft-delete template (lock check via Redis)
        - `saveFields(PdfTemplate, SaveFieldsRequest)`: sync fields (lock check via Redis)
        - `reUpload(PdfTemplate, ReUploadRequest)`: re-upload PDF (lock check via Redis)
        - `dataSources()`: return data source list from DataSourceResolver
        - `lock(PdfTemplate, LockRequest)`: acquire edit lock via PdfTemplateLockService
        - `refreshLock(PdfTemplate)`: extend TTL for existing lock
        - `unlock(PdfTemplate)`: release edit lock via PdfTemplateLockService
        - _Requirements: 1.1, 1.2, 1.3, 1.6, 2.9, 3.1, 3.2, 10.1_

    - [x] 9.2 Register routes in `routes/api.php`
        - Group under `admin/pdf-templates` with appropriate permission middleware per route
        - `GET /` → index (pdf_template.list)
        - `POST /` → store (pdf_template.create)
        - `GET /data-sources` → dataSources (pdf_template.list)
        - `GET /{template}` → show (pdf_template.list)
        - `PUT /{template}` → update (pdf_template.update)
        - `DELETE /{template}` → destroy (pdf_template.delete)
        - `PUT /{template}/fields` → saveFields (pdf_template.update)
        - `POST /{template}/re-upload` → reUpload (pdf_template.update)
        - `POST /{template}/lock` → lock (pdf_template.update)
        - `POST /{template}/refresh-lock` → refreshLock (pdf_template.update)
        - `POST /{template}/unlock` → unlock (pdf_template.update)
        - _Requirements: 1.5_

- [x] 10. Checkpoint — Test template CRUD and locking via API
    - Test upload, list, show, update, soft-delete endpoints
    - Test re-upload preserves fields, updates page count
    - Test field save and retrieve round-trip
    - Test permission enforcement (unauthorized returns 403)
    - Test validation errors (wrong file type, oversized, duplicate name)
    - Test lock/unlock/refresh flow via Redis
    - Test update/delete blocked when locked (423)
    - Verify Redis lock TTL expires after 30 minutes
    - Test lock_info is appended to template responses

### Phase 4: PDF Generation Engine

- [x] 11. Install PHP dependencies
    - [x] 11.1 Install `setasign/fpdi` and `tecnickcom/tcpdf` via Composer
        - _Requirements: 4.1, 5.1_

    - [x] 11.2 Set up Japanese font for TCPDF
        - Add Japanese font files to the project
        - Register font with TCPDF's font utility
        - _Requirements: NFR (Japanese font support)_

- [x] 12. GeneratePdfAction
    - [x] 12.1 Create action at `app/Actions/Admin/PdfTemplate/GeneratePdfAction.php`
        - Input: `PdfTemplate` (with fields), optional `array $overrides`, uses placeholder values
        - Load template PDF from S3 into FPDI
        - For each page: import page via FPDI, iterate fields for that page
        - Resolve each field's value: use override if provided, else use field placeholder or data source placeholder
        - Convert percentage coordinates to absolute positions using page dimensions
        - _Requirements: 7.1_

    - [x] 12.2 Implement text fill mode rendering
        - Use TCPDF Cell/MultiCell for text placement
        - Apply font weight (normal/bold), font size
        - Apply horizontal and vertical alignment
        - Implement auto letter spacing: `spacing = (field_width - total_char_width) / (char_count - 1)`, render each character individually with calculated x offset
        - Implement overflow modes: wrap (MultiCell), trim (Cell with no overflow), shrink (loop reducing font size until fits)
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7_

    - [x] 12.3 Implement shape fill mode rendering
        - If resolved value is truthy: draw rectangle via `Rect()` or circle via `Ellipse()`
        - Support filled vs outlined via TCPDF draw style parameter ('F' for filled, 'D' for outlined)
        - Apply color from shape_config
        - Apply stroke width from shape_config for outlined mode
        - If resolved value is falsy: skip rendering
        - _Requirements: 5.1, 5.2, 5.3_

- [x] 13. Checkpoint — Verify PDF generation
    - Create a test template with text and shape fields
    - Generate a PDF for a test user
    - Verify text placement, alignment, spacing, and overflow behavior
    - Verify shape conditional rendering
    - Verify Japanese text renders correctly

### Phase 5: Backend Async Export & History

- [x] 14. ProcessExportChunkJob
    - [x] 14.1 Create job at `app/Jobs/ProcessExportChunkJob.php`
        - Constructor: `$batchId`, `$offset` (default 0), `$chunkSize` (default 50)
        - `$tries = 3`, `$timeout = 300`
        - `handle()` method:
            1. Load ExportBatch with template (including fields)
            2. If batch status is "pending", update to "processing"
            3. Query ExportBatchItems with status "pending", ordered by id, limit $chunkSize
            4. For each item:
                - Load User with UserInfo (nullable)
                - Try: call GeneratePdfAction, store PDF on S3 at path `pdf/{templateId}/{year}/{batchId}/{userId}.pdf`, update item → "completed" with file_path/file_name
                - Catch: update item → "failed" with error_message
            5. Count remaining "pending" items
            6. If > 0: dispatch new ProcessExportChunkJob($batchId, $offset + $chunkSize, $chunkSize)
            7. If 0: call finalizeBatch()
        - `finalizeBatch()` method:
            1. Load all "completed" items, package into zip on S3 at path `pdf/{templateId}/{year}/{batchId}/all.zip`
            2. Update batch: status → "completed" (or "failed" if failed_count == total_count), zip_file_path
            3. Release template export lock
        - `failed()` method: mark all remaining pending items as "failed", set batch to "failed" (or "completed" if some items succeeded), release lock
        - _Requirements: 8.1, 8.2, 8.3, 8.4, 8.5, 8.6, 8.7, 8.8_

- [x] 15. PdfExportController
    - [x] 15.1 Create controller at `app/Http/Controllers/Admin/PdfExportController.php`
        - `preview(PdfTemplate, PreviewRequest)`: check lock, synchronous, generate PDF binary response using placeholders
        - `batchExport(BatchExportRequest)`: check lock (reject if locked), create batch + items, lock template for export, dispatch first chunk job, return 202
        - `exportHistory(PdfTemplate, IndexRequest)`: paginated batch list with counts
        - `getBatch(ExportBatch)`: batch info without items
        - `listBatchItems(ExportBatch, IndexRequest)`: paginated list of items with user info
        - `downloadItem(ExportBatchItem)`: signed URL redirect for individual PDF
        - `downloadBatch(ExportBatch)`: signed URL redirect for zip file
        - _Requirements: 6.1, 7.1, 8.1, 9.1, 9.2, 9.3_

    - [x] 15.2 Create form request classes
        - `PreviewRequest`: no body parameters needed (uses placeholder values)
        - `BatchExportRequest`: `template_id` required/exists:pdf_templates, `user_ids` array, `select_all` boolean, `filters` nullable/array
            - Custom validation: either `user_ids` has items or `select_all` is true
            - If `select_all`: resolve user IDs from filters server-side
        - _Requirements: 6.1, 7.1, 8.1_

    - [x] 15.3 Implement batch creation logic in controller/service
        - Validate template is not locked (reject if locked for editing or export)
        - If `select_all`: use the same query builder as user list to resolve user IDs from filter params
        - Create ExportBatch (status=pending, exported_by=auth user)
        - Bulk-insert ExportBatchItems (one per user, status=pending)
        - Lock template for export
        - Dispatch ProcessExportChunkJob(batch_id, offset=0, chunk_size=50)
        - Return 202 with batch data
        - _Requirements: 7.3, 7.4, 7.5, 7.6, 8.1_

    - [x] 15.4 Register export routes in `routes/api.php`
        - `POST /admin/pdf-templates/{template}/preview` (pdf_template.export)
        - `POST /admin/pdf-export/batch-export` (pdf_template.export)
        - `GET /admin/pdf-templates/{template}/export-history` (pdf_template.list)
        - `GET /admin/export-batches/{batch}` (pdf_template.list) — batch info only
        - `GET /admin/export-batches/{batch}/items` (pdf_template.list) — paginated items list
        - `GET /admin/export-batches/{batch}/download` (pdf_template.export)
        - `GET /admin/export-batch-items/{item}/download` (pdf_template.export)
        - _Requirements: 1.5_

- [x] 16. Checkpoint — Test async export and history APIs
    - Test preview with user data, placeholder data, and overrides
    - Test batch export trigger returns 202 and creates correct records
    - Test ProcessExportChunkJob processes items and dispatches next chunk
    - Test batch finalization creates zip and updates status (completed/failed)
    - Test item-level failure handling (failed items, batch continues)
    - Test job failure marks remaining items as failed
    - Test template lock acquired on export, released on completion
    - Test export history and batch info endpoints
    - Test batch items list endpoint with pagination
    - Test download endpoints for items and zip
    - Test select-all mode resolves users correctly

### Phase 6: Frontend Foundation

- [x] 17. Frontend types and API config
    - [x] 17.1 Create TypeScript types
        - Create `frontend/src/types/models/pdf_template.d.ts`
        - Define: `PdfTemplate`, `PdfTemplateDetail`, `PdfTemplateField` (with `placeholder`), `TextConfig`, `ShapeConfig` (with `stroke_width`), `ExportBatch`, `ExportBatchDetail`, `ExportBatchItem`, `DataSource` (with types: model_field, fixed, custom_function)
        - Include status types, lock fields, nullable file_path/error_message on items
        - _Requirements: 1.2, 2.9, 3.1, 8.1, 9.1_

    - [x] 17.2 Add API endpoints to `api.config.ts`
        - Template CRUD: list, create, show, update, delete, save-fields, re-upload, data-sources
        - Locking: lock, refresh-lock, unlock
        - Export: preview, batch-export, export-history, batch-info, batch-items, download-item, download-batch
        - _Requirements: all_

    - [x] 17.3 Add route config entries
        - `ADMIN_PDF_TEMPLATE_LIST`, `ADMIN_PDF_TEMPLATE_DETAIL` with permission checks
        - _Requirements: 1.5_

- [x] 18. React Query hooks
    - [x] 18.1 Create `frontend/src/pages/admin/pdf-template/api.ts`
        - `useGetPdfTemplatesQuery(params, hasPermission)` — list templates
        - `useGetPdfTemplateDetailQuery(id, hasPermission)` — get template with fields
        - `useGetDataSourcesQuery(hasPermission)` — get data source list
        - `useCreatePdfTemplateMutation()` — upload + create (FormData)
        - `useUpdatePdfTemplateMutation(id)` — update name
        - `useDeletePdfTemplateMutation()` — soft-delete
        - `useSaveFieldsMutation(id)` — save field definitions
        - `useReUploadPdfMutation(id)` — re-upload PDF file
        - `useLockTemplateMutation(id)` — acquire edit lock (Redis)
        - `useRefreshLockMutation(id)` — extend lock TTL
        - `useUnlockTemplateMutation(id)` — release edit lock
        - `usePreviewPdfMutation(id)` — returns blob
        - `useCreateBatchExportMutation()` — trigger async export, returns batch
        - `useGetExportHistoryQuery(templateId, params, hasPermission)` — paginated batches
        - `useGetExportBatchQuery(batchId, hasPermission)` — batch info (no items)
        - `useGetExportBatchItemsQuery(batchId, params, hasPermission)` — paginated items list
        - `useDownloadExportItem(itemId)` — download individual PDF
        - `useDownloadBatchZip(batchId)` — download zip
        - _Requirements: all_

- [x] 19. Checkpoint — Verify frontend types compile
    - Run `pnpm typecheck` to confirm no type errors

### Phase 7: Template List & CRUD Pages

- [x] 20. Template list page
    - [x] 20.1 Create `frontend/src/pages/admin/pdf-template/index.page.tsx`
        - Breadcrumb navigation
        - Search box (filter by name)
        - `DDataTable` with columns: name, page count, field count, lock status, created at, updated at, actions
        - Create button → opens upload modal
        - Action buttons per row: edit (navigate to editor), re-upload, delete (confirm modal), export history
        - Lock indicator column: shows lock icon + tooltip with locker name and reason when locked
        - Delete button disabled when template is locked
        - Permission checks for rendering action buttons
        - _Requirements: 1.2, 1.5, 10.5_

    - [x] 20.2 Create upload modal at `partials/create_template_modal.tsx`
        - Form with name input and PDF file dropzone (Mantine Dropzone, accept PDF, max 5MB)
        - Submit calls `useCreatePdfTemplateMutation`
        - On success: close modal, navigate to editor page
        - _Requirements: 1.1, 1.4_

    - [x] 20.3 Create delete confirmation modal
        - Confirm dialog with template name
        - Warning: "This will soft-delete the template. Export history will be preserved."
        - Calls `useDeletePdfTemplateMutation`
        - _Requirements: 1.3, 1.7_

    - [x] 20.4 Create re-upload modal at `partials/re_upload_modal.tsx`
        - PDF file dropzone
        - Warning: "Existing field definitions will be preserved but may no longer align with the new PDF."
        - Submit calls `useReUploadPdfMutation`
        - _Requirements: 1.6_

- [x] 21. Checkpoint — Verify template list page works
    - Upload a template, verify it appears in the list
    - Re-upload PDF, verify page count updates and fields preserved
    - Delete a template, verify soft-deleted (hidden from list)
    - Verify lock indicators display correctly
    - Verify permission-based rendering

### Phase 8: Field Editor — PDF Rendering

- [x] 22. Install frontend dependencies
    - [x] 22.1 Install `pdfjs-dist` package
        - Configure PDF.js worker (copy worker file or use CDN)
        - _Requirements: 2.1_

- [x] 23. Editor page shell
    - [x] 23.1 Create `frontend/src/pages/admin/pdf-template/[id].page.tsx`
        - Load template detail via `useGetPdfTemplateDetailQuery`
        - Full-width layout: toolbar (top), PDF canvas (center), config panel (right ~300px)
        - Toolbar: page selector, zoom controls, save button, preview button, back button
        - Jotai atoms for editor state: `selectedFieldAtom`, `zoomAtom`, `currentPageAtom`, `toolModeAtom` (select/draw-rect/draw-circle), `clipboardAtom`, `fieldsAtom`, `isDirtyAtom`
        - Lock management (Redis-based):
            - Track `isDirty` state (any change made)
            - On first change: call lock API (Redis SET with TTL 30min), set `isLocked` state
            - On each subsequent change: call refresh lock API (extend TTL)
            - On save: release lock (Redis DEL)
            - On `beforeunload` / route change: release lock if held
            - If template already locked by another user: show read-only banner, disable all editing and export
        - _Requirements: 2.1, 2.8, 10.1, 10.2, 10.3_

    - [x] 23.2 Create PDF renderer component at `partials/editor/pdf_renderer.tsx`
        - Load PDF document from signed URL using `pdfjs-dist`
        - Render current page to a canvas element
        - Re-render on page change or zoom change
        - Canvas size = page dimensions × zoom factor
        - _Requirements: 2.1, 2.8_

- [x] 24. Checkpoint — Verify PDF renders in editor
    - Open a template, verify PDF page displays
    - Navigate between pages
    - Zoom in/out works
    - Lock acquired on first edit, released on save/leave

### Phase 9: Field Editor — Interactions

- [x] 25. Field overlay and selection
    - [x] 25.1 Create field overlay component at `partials/editor/field_overlay.tsx`
        - Second canvas layered on top of PDF canvas
        - Draw all fields for the current page as semi-transparent colored shapes
        - Selected field drawn with highlight border and resize handles (8 handles)
        - Display data source name or key as label inside each field
        - _Requirements: 2.1, 2.10_

    - [x] 25.2 Implement field selection
        - On click: hit-test against field bounds, select matching field (topmost if overlapping)
        - On click empty area: deselect
        - Update `selectedFieldAtom`
        - _Requirements: 2.3_

- [x] 26. Field drawing
    - [x] 26.1 Implement rectangle drawing mode
        - When tool mode is "draw-rect": click-and-drag creates a new rectangle field
        - On mouse down: record start position
        - On mouse move: draw preview rectangle
        - On mouse up: create field with calculated position/size, switch to select mode, select new field
        - Convert pixel coordinates to percentage based on canvas/page dimensions
        - Mark editor as dirty (triggers lock acquisition if not already locked)
        - _Requirements: 2.2_

    - [x] 26.2 Implement circle drawing mode
        - Same as rectangle but draws ellipse inscribed in the drag bounds
        - Field shape set to 'circle'
        - _Requirements: 2.2_

- [x] 27. Field move and resize
    - [x] 27.1 Implement field dragging (move)
        - When clicking on a selected field (not on a handle): enter drag mode
        - On mouse move: update field x/y position
        - On mouse up: finalize position
        - Clamp position to keep field within page bounds
        - _Requirements: 2.4_

    - [x] 27.2 Implement field resizing
        - When clicking on a resize handle: enter resize mode
        - Track which handle (corner or midpoint) to determine resize axis
        - On mouse move: update field dimensions and position accordingly
        - Enforce minimum field size
        - Clamp to page bounds
        - _Requirements: 2.5_

- [x] 28. Field copy/paste and delete
    - [x] 28.1 Implement copy/paste
        - Ctrl+C: copy selected field definition to `clipboardAtom`
        - Ctrl+V: create new field from clipboard with offset position (+2% x, +2% y)
        - Auto-select the pasted field
        - _Requirements: 2.6_

    - [x] 28.2 Implement delete
        - Delete or Backspace key: remove selected field from `fieldsAtom`
        - Clear `selectedFieldAtom`
        - _Requirements: 2.7_

- [x] 29. Checkpoint — Verify all editor interactions
    - Draw rectangle and circle fields
    - Select, move, resize fields
    - Copy/paste and delete fields
    - Fields persist correctly across page switches
    - Zoom works with all interactions

### Phase 10: Field Config Panel & Save

- [x] 30. Field config panel
    - [x] 30.1 Create config panel component at `partials/editor/field_config_panel.tsx`
        - Displayed when a field is selected, hidden otherwise
        - Data source: Mantine `Select` with `searchable`, items from `useGetDataSourcesQuery`, grouped by `group`, showing only name
        - Placeholder: text input (nullable, overrides data source default)
        - Fill mode: radio group (text/shape)
        - Text config (shown when fill_mode = 'text'):
            - Font weight: select (normal/bold)
            - Font size: number input (disabled when overflow = shrink)
            - H-align: segmented control (left/center/right)
            - V-align: segmented control (top/middle/bottom)
            - Letter spacing: select (auto) or number input (fixed value)
            - Overflow: select (wrap/trim/shrink) — when shrink selected, font-size input is automatically disabled
        - Shape config (shown when fill_mode = 'shape'):
            - Fill style: segmented control (filled/outlined)
            - Color: Mantine `ColorInput`
            - Stroke width: number input (shown when fill_style = outlined)
        - Read-only display: shape type, page number
        - Changes update field in `fieldsAtom` immediately
        - _Requirements: 2.3, 3.1, 3.2, 4.2, 4.3, 4.4, 4.5, 4.6, 4.7, 5.3_

- [x] 31. Save functionality
    - [x] 31.1 Implement save button
        - Calls `useSaveFieldsMutation` with all fields from `fieldsAtom`
        - Show loading state during save
        - Show success/error notification
        - On success: update `fieldsAtom` with response data (new IDs), release lock, reset `isDirty`
        - _Requirements: 2.9, 10.3_

- [x] 32. Checkpoint — Verify config panel and save
    - Configure a field with all text options
    - Configure a field with shape options
    - Save and reload — verify fields persist with correct config
    - Search data sources in dropdown
    - Verify lock released after save

### Phase 11: Preview UI (Template Editor)

- [x] 33. Preview modal
    - [x] 33.1 Create preview modal at `partials/preview_modal.tsx`
        - "Generate Preview" button: calls `usePreviewPdfMutation` (uses placeholder values from field or data source default)
        - Display PDF in `<iframe>` using blob URL (`URL.createObjectURL`)
        - Download button: trigger download of the blob
        - Loading state during generation
        - Note: Preview is allowed even when template is locked (read-only operation)
        - _Requirements: 6.1, 6.2_

- [x] 34. Checkpoint — Verify preview
    - Preview generates PDF with placeholder values
    - Download preview PDF
    - Verify locked template cannot be previewed

### Phase 12: Export from User List

- [x] 35. Export modal on user list page
    - [x] 35.1 Add user selection support to user list page
        - Add row checkboxes to `DDataTable` on user list
        - Add "select all on this page" checkbox in header
        - Add "select all N users matching filters" option (banner that appears after selecting all on page)
        - Track selection state: `selectedUserIds: number[]` or `selectAll: true` with `filters`
        - Show selection count in toolbar
        - "Export PDF" button appears in toolbar when users are selected
        - _Requirements: 7.1, 7.2_

    - [x] 35.2 Create export PDF modal at `frontend/src/pages/admin/user/partials/export_pdf_modal.tsx`
        - Template selector: searchable dropdown from `useGetPdfTemplatesQuery` (only unlocked templates)
        - Display: "Exporting for N users" or "Exporting for all X users matching current filters"
        - Export button: calls `useCreateBatchExportMutation`
        - On success: close modal, show notification with "View progress" link to batch detail
        - On 423 error: show "Template is currently locked" message
        - _Requirements: 7.3, 7.4, 7.5_

- [x] 36. Batch status page/view
    - [x] 36.1 Create batch status view at `frontend/src/pages/admin/pdf-template/export-batch/[id].page.tsx`
        - Or as a modal/drawer accessible from export history
        - Header: template name, status badge, exporter name, created at
        - Progress bar: `completed_count / total_count`, show failed count separately
        - Item list table: user name, staff_no, status badge, error message (if failed), download button (if completed)
        - Batch download button: "Download All (ZIP)" — enabled when batch status is "completed" and has completed items
        - Polling: `refetchInterval: 3000` while batch status is not "completed"
        - _Requirements: 8.7, 8.8, 8.9, 9.1, 9.2, 9.3, 9.4_

- [x] 37. Export history
    - [x] 37.1 Create export history section
        - Accessible from template list page (action button per row)
        - Located at `frontend/src/pages/admin/pdf-template/[id]/export-history.page.tsx` or as a tab/section within the template detail
        - Paginated list of batches: status badge, timestamp, exporter name, total/completed/failed counts
        - Click batch row → navigate to batch status view
        - _Requirements: 9.1, 9.2, 9.3_

- [x] 38. Checkpoint — Verify export from user list
    - Select one user → export → verify batch created and processing
    - Select multiple users on one page → export → verify
    - Select all (across pages) → export → verify user count matches
    - Verify batch status page shows progress, polls, stops on completion
    - Verify failed items show error message
    - Download individual PDFs and batch zip
    - Verify template locked during export, released after completion
    - View export history from template list

### Phase 13: Testing

- [x] 39. Backend unit tests
    - [x] 39.1 Test `DataSourceResolver`
        - Resolves model_field for User and UserInfo attributes
        - Resolves fixed string values
        - Resolves custom_function with correct inputs
        - Returns empty string for null values
        - Handles null UserInfo gracefully
        - _Requirements: 3.3, 3.4, 3.5_

    - [x] 39.2 Test `GeneratePdfAction`
        - Generates valid PDF binary
        - Text fields placed at correct positions
        - Shape fields drawn only when value is truthy
        - Auto letter spacing distributes correctly
        - Shrink-to-fit reduces font size
        - Wrap mode inserts line breaks
        - _Requirements: 4.1, 4.2, 4.3, 4.5, 4.6, 4.7, 5.1, 5.2_

    - [x] 39.3 Test `PdfTemplateService`
        - Creates template with S3 upload
        - Lists with name filter (excludes soft-deleted)
        - Soft-deletes template
        - Re-uploads PDF, preserves fields, updates page count
        - Syncs fields (delete + re-insert)
        - _Requirements: 1.1, 1.2, 1.3, 1.6, 2.9_

    - [x] 39.4 Test `PdfTemplateLockService`
        - Acquires lock successfully when not locked
        - Rejects lock acquisition when already locked by another user
        - Refreshes TTL on existing lock by owner
        - Releases lock by owner
        - Rejects release by non-owner
        - Returns correct lock info
        - TTL auto-expires after 30 minutes
        - _Requirements: 10.1–10.8_

    - [x] 39.4 Test `ProcessExportChunkJob`
        - Processes chunk and updates item statuses correctly
        - Dispatches next chunk when pending items remain
        - Finalizes batch (zip + status) when all items done
        - Handles per-item failures gracefully (continues processing)
        - Releases template export lock on completion
        - `failed()` method marks remaining items and releases lock
        - _Requirements: 8.1–8.9_

- [x] 40. Backend feature tests
    - [x] 40.1 Test `PdfTemplateController` endpoints
        - CRUD with valid data
        - Permission enforcement (403 for unauthorized)
        - Validation errors (422 for invalid data)
        - Field save round-trip
        - Data sources endpoint
        - Soft-delete hides from list
        - Re-upload replaces file
        - Lock/unlock/refresh endpoints
        - 423 on locked template update/delete
        - lock_info in responses
        - _Requirements: 1.1–1.8, 2.9, 3.1, 10.1–10.8_

    - [x] 40.2 Test `PdfExportController` endpoints
        - Preview with placeholders
        - Preview returns 423 when template is locked
        - Batch export trigger returns 202 with batch
        - Batch export creates correct items count
        - Batch export returns 423 when template is locked
        - Select-all mode resolves users from filters
        - Export history pagination
        - Batch info endpoint (without items)
        - Batch items list endpoint with pagination
        - Download item and download zip
        - Permission enforcement on all endpoints
        - _Requirements: 6.1–6.2, 7.1–7.6, 8.1, 9.1–9.4_

### Phase 14: Final Validation

- [x] 41. Code quality
    - [x] 41.1 Run `pnpm prettier` in `./frontend` to format frontend code
    - [x] 41.2 Run `pnpm typecheck` in `./frontend` to verify no type errors
    - [x] 41.3 Run `npm run prettier` in `./backend` to format backend code
    - [x] 41.4 Run `npm run pint` in `./backend` to lint backend code
    - [x] 41.5 Run `./vendor/bin/phpunit` in backend container to verify all tests pass

## Notes

- **Ordering rationale**: Database and models first because everything depends on them. PDF generation engine (Phase 4) is tackled before frontend to validate the FPDI+TCPDF approach early — this is the highest technical risk. Frontend editor is built incrementally to allow testing at each interaction layer. Export from user list (Phase 12) comes after the editor is complete since it depends on the generation engine and async infrastructure.
- **Risk: FPDI + TCPDF compatibility**: If FPDI v2 and TCPDF don't integrate cleanly, may need to use FPDI with FPDF instead. Validate in Phase 4 checkpoint before building further.
- **Risk: PDF.js rendering performance**: Large PDFs or high zoom levels may be slow on canvas. Mitigate by rendering only the visible page and using `requestAnimationFrame` for field overlay updates.
- **Risk: Coordinate precision**: Percentage-based coordinates may lose precision at very small scales. Using `DECIMAL(8,4)` gives 4 decimal places which is sufficient for sub-pixel accuracy.
- **Async export benefits**: Chunked processing keeps each job short-lived (< 2 min), avoids memory issues, and allows the queue worker to process other jobs between chunks. If a worker crashes, only the current chunk needs retrying.
- **Chunk size tuning**: Default chunk size of 50 users per job. Can be adjusted based on observed PDF generation time and memory usage.
- **Japanese font files**: Must be converted to TCPDF format before use. This is a one-time setup step in task 11.2.
- **Select-all scalability**: For very large user bases (1000+), the server-side user resolution ensures we don't send thousands of IDs from the frontend. The chunked job processing handles large batches gracefully.
- **Redis-based locking**: Locks are stored in Redis with 30-minute TTL. TTL auto-expires if not refreshed. Redis provides atomic operations (SETNX, EXPIRE) for reliable lock management. Lock key pattern: `pdf_template:lock:{template_id}`.
- **Lock TTL**: 30 minutes chosen as a balance — long enough for a normal editing session, short enough to not block other admins indefinitely. TTL is refreshed on each subsequent change by the editing user via the refresh-lock endpoint.
- **UserInfo nullability**: The system handles users without UserInfo records gracefully — data sources resolve to empty strings for missing UserInfo attributes.
- **Soft-delete and export cascade**: `ON DELETE CASCADE` on `export_batches.pdf_template_id` still applies, but since we're soft-deleting (not hard-deleting), cascades won't fire. Export history remains intact.
