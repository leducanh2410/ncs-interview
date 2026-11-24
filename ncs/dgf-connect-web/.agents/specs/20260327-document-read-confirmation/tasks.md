# Implementation Plan: Document Read Confirmation

## Overview

Implementation follows a Foundation-First strategy: database migrations and models are established first so every subsequent layer has a stable schema to build on, then services encapsulate business logic, then events/listeners/jobs wire up the async triggers, then API controllers expose the surface, and finally the frontend admin UI is added. This ordering ensures that each phase produces independently testable artifacts before the next phase depends on them.

### Implementation Strategy
- **Bottom-up within each layer**: types/models → services → controllers → UI.
- **Backend before frontend**: all API endpoints are verifiable via HTTP client before any UI work begins.
- **Async-safe by design**: event listeners and jobs implement `ShouldQueue` from the start; no refactoring needed later.
- **Idempotency first**: unique constraints and upsert patterns are built into the data layer so higher layers can call freely without collision guards.

---

## Implementation Plan

### Phase 1: Database & Models

- [x] 1. Create database migrations
    - [x] 1.1 Create migration for `document_required_configs` table
        - Columns: `id`, `workplace_id BIGINT UNSIGNED NULL FK→workplaces`, `document_id BIGINT UNSIGNED NULL FK→documents SET NULL`, `title_vi VARCHAR(255)`, `title_en VARCHAR(255)`, `title_ja VARCHAR(255)`, `is_active BOOLEAN DEFAULT TRUE`, `created_by BIGINT UNSIGNED FK→users`, `timestamps`
        - No `scope` column — derived from `workplace_id` (NULL = system-wide, non-NULL = workplace)
        - `document_id` FK uses `SET NULL` on document deletion
        - Index: `(workplace_id, is_active)`, `(is_active)`
        - _Requirements: 1.1, 1.2, 2.1, 2.2_

    - [x] 1.2 Create migration for `document_read_confirmations` table
        - Columns: `id`, `user_id BIGINT UNSIGNED FK→users CASCADE`, `document_required_config_id BIGINT UNSIGNED FK→document_required_configs CASCADE`, `ip_address VARCHAR(45)`, `device_info JSON NULL`, `timestamps`
        - No `confirmed_at` column — `created_at` serves as confirmation timestamp
        - FK on `document_required_config_id` uses `CASCADE` on delete (confirmations deleted with config)
        - Unique constraint: `(user_id, document_required_config_id)`
        - Index: `(document_required_config_id)` for audit queries
        - _Requirements: 7.1, 7.2, 7.3_

    - [x] 1.3 Create migration to add `has_pending_documents` to `users` table
        - `has_pending_documents BOOLEAN NOT NULL DEFAULT FALSE`
        - Index: `(has_pending_documents)`
        - _Requirements: 6.2, 6.3_

- [x] 2. Create Eloquent models
    - [x] 2.1 Create `App\Models\DocumentRequiredConfig`
        - `$guarded = []`
        - Casts: `is_active => boolean`
        - Accessor: `getScopeAttribute()` — returns `'system'` if `workplace_id` is null, `'workplace'` otherwise
        - Append: `$appends = ['scope']` so it's included in JSON serialization
        - Relations: `belongsTo(Document)`, `belongsTo(Workplace)` (nullable), `belongsTo(User, 'created_by')`, `hasMany(DocumentReadConfirmation)`
        - Scopes: `scopeActive()`, `scopeSystem()` (where workplace_id IS NULL), `scopeForWorkplace(int $workplaceId)`
        - _Requirements: 1.1, 1.2, 2.1, 2.2_

    - [x] 2.2 Create `App\Models\DocumentReadConfirmation`
        - `$guarded = []`
        - Casts: `device_info => array`
        - Relations: `belongsTo(User)`, `belongsTo(DocumentRequiredConfig)`
        - _Requirements: 7.1, 7.2_

    - [x] 2.3 Add `Document` model observer (or boot method)
        - On `Document::deleting`: set `document_id = NULL` and `is_active = false` on all `DocumentRequiredConfig` records referencing this document
        - _Requirements: 1.5_

    - [x] 2.4 Update `App\Models\User`
        - Add `has_pending_documents` to `casts()` as `boolean`
        - Add `@property bool $has_pending_documents` to docblock
        - _Requirements: 6.2, 6.3_

- [x] 3. Checkpoint — Verify database foundation
    - Run `docker compose exec php php artisan migrate` and confirm all three migrations apply cleanly.
    - Run `docker compose exec php php artisan tinker` and verify model relations resolve without errors.

---

### Phase 2: Core Services

- [x] 4. Implement `DocumentReadConfirmationService`
    - [x] 4.1 Create `App\Services\DocumentReadConfirmationService`
        - Implement `getPendingConfigs(User $user): Collection`
            - Query 1: active system-scoped configs where `user->paper_status = 'approved'` and no confirmation exists for this user
            - Query 2: active workplace-scoped configs where user has ≥1 `ShiftUser` with `approved_at IS NOT NULL` for that workplace and no confirmation exists
            - Return merged collection
        - _Requirements: 6.3, 6.4_

    - [x] 4.2 Implement `setPendingFlag(User $user, bool $value): void`
        - `User::where('id', $user->id)->update(['has_pending_documents' => $value])`
        - _Requirements: 3.3, 4.2, 5.2_

    - [x] 4.3 Implement `evaluateAndResetFlag(User $user): void`
        - Call `getPendingConfigs($user)`; if empty, call `setPendingFlag($user, false)`
        - _Requirements: 6.4, 7.5_

    - [x] 4.4 Implement `confirm(User $user, int $configId, string $ipAddress, array $deviceInfo): ?DocumentReadConfirmation`
        - If config is inactive: return null (no-op success, no record created)
        - Verify config is applicable to this user (403 otherwise)
        - `firstOrCreate(['user_id'=>..., 'document_required_config_id'=>...], ['ip_address'=>..., 'device_info'=>...])` — never overwrites existing audit data
        - Call `evaluateAndResetFlag($user)` after insert
        - Wrap in `DB::transaction()`
        - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

- [x] 5. Implement `DocumentReadConfigService`
    - [x] 5.1 Create `App\Services\Admin\DocumentReadConfigService`
        - `listSystemConfigs(): Collection` — active + inactive, with eager `document`
        - `listWorkplaceConfigs(Workplace $workplace): Collection`
        - `listAllConfigs(User $admin): Collection` — returns system-wide configs + configs for workplaces the admin manages; GlobalAdmin sees all
        - `createConfig(array $data, User $createdBy): DocumentRequiredConfig`
            - Insert record. Does NOT auto-dispatch backfill.
        - `updateConfig(DocumentRequiredConfig $config, array $data): DocumentRequiredConfig`
            - Update record. Does NOT auto-dispatch backfill.
            - Reject `document_id` change if any confirmations exist for this config (422).
        - `deleteConfig(DocumentRequiredConfig $config): void`
            - Hard-delete the config; confirmations are cascade-deleted by DB FK.
        - `getBackfillCount(DocumentRequiredConfig $config): int`
            - Count eligible employees without confirmation for this config.
        - `triggerBackfill(DocumentRequiredConfig $config): void`
            - Reject with 422 if config is inactive. Dispatch `BackfillDocumentPendingFlagsJob`. Called only after admin confirms.
        - _Requirements: 1.2, 1.3, 2.2, 2.3, 5.1, 5.2, 5.3_

---

### Phase 3: Events, Listeners & Queued Jobs

- [x] 6. Create `UserPaperApproved` event and dispatch it
    - [x] 6.1 Create `App\Events\UserPaperApproved`
        - Constructor: `public function __construct(public User $user)`
        - Implements `ShouldDispatchAfterCommit`
        - _Requirements: 3.1_

    - [x] 6.2 Dispatch `UserPaperApproved` from paper approval action
        - In the existing `Admin\UserController::approvePapers` (or its underlying action/service), after `paper_status` is set to `approved`, fire `UserPaperApproved::dispatch($user)`
        - _Requirements: 3.1_

- [x] 7. Create `CheckSystemDocumentRequirementsListener`
    - [x] 7.1 Create `App\Listeners\CheckSystemDocumentRequirementsListener`
        - Implements `ShouldQueue`
        - `handle(UserPaperApproved $event)`:
            - Query active system-scoped configs
            - Filter to those with no existing confirmation for `$event->user`
            - If any remain: call `DocumentReadConfirmationService::setPendingFlag($user, true)`, then send FCM notification to user's devices
        - Register in `EventServiceProvider`: `UserPaperApproved => [CheckSystemDocumentRequirementsListener]`
        - _Requirements: 3.1, 3.2, 3.3, 3.4_

- [x] 8. Create `CheckWorkplaceDocumentRequirementsListener`
    - [x] 8.1 Create `App\Listeners\CheckWorkplaceDocumentRequirementsListener`
        - Implements `ShouldQueue`
        - `handle(ShiftUserApproved $event)`:
            - Resolve `$workplaceId` from `$event->shiftUser->shift->workplace_id`
            - Count prior approved ShiftUser records for `(user_id, workplace_id)` excluding the current one
            - If count > 0: early return (not the first shift)
            - Query active workplace-scoped configs for `$workplaceId` with no confirmation from this user
            - If any: call `setPendingFlag(true)`, send FCM notification
        - Register `ShiftUserApproved => [...existing..., CheckWorkplaceDocumentRequirementsListener]` in `EventServiceProvider`
        - _Requirements: 4.1, 4.2, 4.3, 4.4, 4.5_

- [x] 9. Create `BackfillDocumentPendingFlagsJob`
    - [x] 9.1 Create `App\Jobs\BackfillDocumentPendingFlagsJob`
        - Implements `ShouldQueue`
        - Constructor: `__construct(public int $configId)`
        - `handle()`:
            - Load config; determine scope from `workplace_id` (NULL = system, non-NULL = workplace)
            - For system (workplace_id IS NULL): `User::where('paper_status', 'approved')->whereDoesntHave('documentReadConfirmations', fn($q)=>$q->where('document_required_config_id', $this->configId))->chunkById(100, fn($users)=>...)`
            - For workplace (workplace_id IS NOT NULL): chunk users with ≥1 approved `ShiftUser` at the config's workplace and no confirmation
            - For each chunk: `User::whereIn('id', $ids)->update(['has_pending_documents'=>true])` + send FCM with full pending document data in batches of 500 tokens
        - _Requirements: 5.5, 5.6, 5.7_

- [x] 10. Checkpoint — Verify event/listener/job wiring
    - Write a quick Tinker script or feature test that approves a user's paper and confirms `has_pending_documents` is set to true and a queued job is dispatched.

---

### Phase 4: API Layer — Backend

- [x] 11. Admin API — System-Wide Document Read Config endpoints
    - [x] 11.1 Create `App\Http\Requests\Admin\DocumentReadConfig\StoreRequest`
        - Validate: `document_id` (exists), `title_vi/en/ja` (required, max:255), `is_active` (boolean)
        - _Requirements: 1.2_

    - [x] 11.2 Create `App\Http\Requests\Admin\DocumentReadConfig\UpdateRequest`
        - Same fields as StoreRequest but all optional (for partial updates)
        - _Requirements: 1.3, 1.4_

    - [x] 11.3 Create `App\Http\Controllers\Admin\DocumentReadConfigController`
        - `index()`: return `DocumentReadConfigService::listAllConfigs()` — unified list of all configs (system-wide + all workplaces the admin can access). Supports optional query params: `workplace_id`, `scope`
        - `store(StoreRequest $request)`: create config with `workplace_id=null` (system-wide)
        - `update(DocumentRequiredConfig $config, UpdateRequest $request)`: update, guard workplace_id=null, block document_id change if confirmations exist
        - `destroy(DocumentRequiredConfig $config)`: delete
        - `confirmations(DocumentRequiredConfig $config)`: paginated list of Read_Confirmations with user info (WorkplaceAdmin can access if config belongs to their workplace or is system-wide)
        - `backfillCount(DocumentRequiredConfig $config)`: return affected employee count
        - `backfill(DocumentRequiredConfig $config)`: reject if inactive (422), dispatch BackfillDocumentPendingFlagsJob
        - `allConfirmations()`: paginated unified audit view across all configs, with filters (config_id, workplace_id, user_id). WorkplaceAdmin results auto-scoped to their workplaces + system-wide.
        - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 5.2, 5.3, 7.6_

    - [x] 11.4 Register routes in `routes/api.php` under `admin` prefix
        - `GET  /admin/document-read-configs` → `index` (permission: `config.list`)
        - `POST /admin/document-read-configs` → `store` (permission: `config.edit`)
        - `PUT  /admin/document-read-configs/{config}` → `update` (permission: `config.edit`)
        - `DELETE /admin/document-read-configs/{config}` → `destroy` (permission: `config.edit`)
        - `GET  /admin/document-read-configs/{config}/confirmations` → `confirmations` (permission: `config.list`)
        - `GET  /admin/document-read-configs/{config}/backfill-count` → `backfillCount` (permission: `config.edit`)
        - `POST /admin/document-read-configs/{config}/backfill` → `backfill` (permission: `config.edit`)
        - `GET  /admin/document-read-confirmations` → unified audit list (permission: `config.list`)
        - _Requirements: 1.1–1.6, 5.2, 5.3, 7.6_

- [x] 12. Admin API — Per-Workplace Document Read Config endpoints
    - [x] 12.1 Create `App\Http\Controllers\Admin\WorkplaceDocumentReadConfigController`
        - `index(Workplace $workplace)`: `DocumentReadConfigService::listWorkplaceConfigs($workplace)`
        - `store(Workplace $workplace, StoreRequest $request)`: create with `workplace_id=$workplace->id`
        - `update(Workplace $workplace, DocumentRequiredConfig $config, UpdateRequest $request)`: update, enforce same workplace
        - `destroy(Workplace $workplace, DocumentRequiredConfig $config)`: delete, enforce same workplace
        - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5_

    - [x] 12.2 Register routes under `admin/workplaces/{workplace}` prefix
        - `GET    /admin/workplaces/{workplace}/document-read-configs`
        - `POST   /admin/workplaces/{workplace}/document-read-configs`
        - `PUT    /admin/workplaces/{workplace}/document-read-configs/{config}`
        - `DELETE /admin/workplaces/{workplace}/document-read-configs/{config}`
        - `GET    /admin/workplaces/{workplace}/document-read-configs/{config}/backfill-count`
        - `POST   /admin/workplaces/{workplace}/document-read-configs/{config}/backfill`
        - All guarded by `workplace.edit` permission scoped to `$workplace`
        - _Requirements: 2.1–2.5, 5.2, 5.3_

- [x] 13. User API — Confirmation endpoint
    - [x] 13.1 Create `App\Http\Requests\User\ConfirmDocumentReadRequest`
        - Validate: `device_info` (nullable array), `device_info.model/os/app_version` (nullable string, max:255)
        - _Requirements: 7.1_

    - [x] 13.2 Create `App\Http\Controllers\User\DocumentReadConfirmationController`
        - `confirm(DocumentRequiredConfig $config, ConfirmDocumentReadRequest $request)`:
            - `$user = Auth::user()`
            - Delegate to `DocumentReadConfirmationService::confirm($user, $config->id, $request->ip(), $request->input('device_info', []))`
            - Return `{ confirmed: true, has_pending_documents: $user->fresh()->has_pending_documents }` (returns success even if config is inactive — no-op)
        - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5_

    - [x] 13.3 Register route under authenticated `user` group
        - `POST /user/document-read-configs/{config}/confirm`
        - _Requirements: 7.1_

- [x] 14. Enhance login API to return pending documents
    - [x] 14.1 Update `AuthController::login()`
        - After `getAuthData()`, call `DocumentReadConfirmationService::getPendingForLoginResponse($data['user'])`
            - Implement `getPendingForLoginResponse(User $user): array`:
                - If `!$user->has_pending_documents`: return `[]`
                - Else: call `getPendingConfigs($user)`, format each as `{ id, workplace_id, title_vi, title_en, title_ja, document_url }` ordered by `id` ascending
                - If result is empty (stale flag): call `setPendingFlag($user, false)`, return `[]`
        - Add `pending_documents` key to the `$data` array before returning
        - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5_

- [x] 15. Checkpoint — Verify full API surface
    - Use HTTP client (Postman / PHPUnit) to verify:
        - Admin can create system-wide and workplace configs.
        - Approving a paper sets `has_pending_documents = true` (via queue).
        - Login response contains `pending_documents` array.
        - Confirmation endpoint records audit data and resets flag.

---

### Phase 5: Frontend Admin UI

- [ ] 16. Unified Document Read Config management page
    - [x] 16.1 Create type `DocumentRequiredConfig` in `frontend/src/types/models/document_required_config.d.ts`
        - Include computed `scope` field (derived from `workplace_id`)
        - _Requirements: 1.1, 2.1_

    - [x] 16.2 Create API hooks in `frontend/src/pages/admin/document-read-config/api.ts`
        - `useDocumentReadConfigsQuery(params?)` — GET `/admin/document-read-configs` (unified list, supports `workplace_id` and `scope` filters)
        - `useCreateDocumentReadConfigMutation()` — POST system-wide config
        - `useCreateWorkplaceDocumentReadConfigMutation()` — POST workplace config
        - `useUpdateDocumentReadConfigMutation()` — PUT
        - `useDeleteDocumentReadConfigMutation()` — DELETE
        - `useBackfillCountQuery(configId)` — GET backfill count
        - `useTriggerBackfillMutation()` — POST trigger backfill
        - `useConfirmationsQuery(configId)` — GET per-config confirmations
        - `useAllConfirmationsQuery(params?)` — GET `/admin/document-read-confirmations` (unified audit, supports config_id, workplace_id, user_id filters)
        - _Requirements: 1.1–1.5, 2.1–2.4, 5.2, 5.3, 7.6_

    - [x] 16.3 Create config form component
        - Form fields: `title_vi`, `title_en`, `title_ja` (TextInput × 3), `document_id` (Select, from existing document list API), `workplace_id` (Select, nullable — null = system-wide), `is_active` (Switch)
        - Submit calls create or update mutation
        - _Requirements: 1.2, 1.3, 2.2_

    - [x] 16.4 Create config list page at `/admin/document-read-configs`
        - Table columns: type (system/workplace), workplace name, title (vi), document name, status badge, actions
        - Filter by type (system-wide / workplace)
        - Actions: edit, delete, backfill, view confirmations
        - "Add" button opens form modal
        - _Requirements: 1.1, 1.5, 2.1, 2.4_

    - [x] 16.5 Create backfill confirmation modal
        - "Backfill" action fetches affected employee count via `backfill-count` endpoint
        - Modal text: "This will require X employees to read and confirm this document. Proceed?"
        - On confirm: triggers backfill via POST endpoint
        - _Requirements: 5.2, 5.3_

    - [x] 16.6 Create confirmations audit page
        - Standalone page at `/admin/document-read-confirmations` (unified cross-config view)
        - Also accessible from config list via "View confirmations" action (pre-filters by config_id)
        - Filters: config selector, workplace selector, employee search
        - Paginated table: employee name/staff_no, config title, workplace name, `created_at` (confirmation time), IP, device info
        - Uses `useAllConfirmationsQuery` for standalone view, `useConfirmationsQuery` for per-config drill-down
        - _Requirements: 7.6_

---

### Phase 6: Testing

- [x] 18. Unit tests
    - [x] 18.1 `tests/Unit/Services/DocumentReadConfirmationServiceTest.php`
        - `getPendingConfigs` returns system configs for paper-approved user, ordered by ID
        - `getPendingConfigs` returns workplace configs for user with approved shift at that workplace
        - `getPendingConfigs` excludes inactive configs
        - `getPendingConfigs` excludes already-confirmed configs
        - `confirm()` creates record with audit data via `firstOrCreate`
        - `confirm()` is idempotent (second call returns existing record, does NOT overwrite audit data)
        - `confirm()` on inactive config returns null (no-op)
        - `evaluateAndResetFlag()` resets flag when no pending remain
        - _Requirements: 6.3, 6.4, 7.2, 7.3, 7.4, 7.5_

    - [x] 18.2 `tests/Unit/Listeners/CheckWorkplaceDocumentRequirementsListenerTest.php`
        - Sets flag and notifies on first approved shift with active configs
        - Does NOT set flag on second approved shift at same workplace
        - Does NOT set flag when no active workplace configs exist
        - _Requirements: 4.1–4.5_

    - [x] 18.3 `tests/Unit/Jobs/BackfillDocumentPendingFlagsJobTest.php`
        - Sets flag for all paper-approved users without confirmation (system config)
        - Sets flag for all users with approved shift at workplace without confirmation (workplace config)
        - Processes in chunks; does not set flag for already-confirmed users
        - _Requirements: 5.1–5.4_

- [x] 19. Integration/Feature tests
    - [x] 19.1 `tests/Feature/Api/Admin/DocumentReadConfigTest.php`
        - GlobalAdmin creates, updates, deactivates, deletes system-wide configs (200/201)
        - WorkplaceAdmin CRUDs their workplace's configs (200/201)
        - WorkplaceAdmin gets 403 on another workplace's configs
        - Updating `document_id` is blocked when confirmations exist for the config (422)
        - Deleting config cascade-deletes confirmation records
        - Backfill count endpoint returns correct affected user count
        - Backfill trigger endpoint dispatches `BackfillDocumentPendingFlagsJob`
        - Backfill trigger on inactive config returns 422
        - Unified config list returns scoped results (WorkplaceAdmin sees system-wide + their workplaces only)
        - Unified audit endpoint returns scoped confirmations for WorkplaceAdmin
        - _Requirements: 1.1–1.7, 2.1–2.5, 5.1–5.4, 7.6_

    - [x] 19.2 `tests/Feature/Api/User/DocumentReadConfirmationTest.php`
        - Confirm endpoint persists record with ip_address + device_info (immutable via `firstOrCreate`)
        - Confirm is idempotent (200 on second call, no duplicate DB row, original audit data preserved)
        - Confirm on inactive config returns 200 (no-op, no record created)
        - Flag is reset to false after all configs confirmed
        - Login response includes `pending_documents` array ordered by ID when flag is true
        - Login response includes empty `pending_documents` when flag is false (no extra query)
        - Login self-heals stale flag (configs deactivated after flag was set)
        - _Requirements: 6.1–6.5, 7.1–7.5_

---

### Phase 7: Final Validation

- [ ] 20. Code Quality
    - [ ] 20.1 Run `npm run prettier` in `./backend` to format PHP files
    - [ ] 20.2 Run `npm run pint` in `./backend` to lint/style check PHP
    - [ ] 20.3 Run `pnpm prettier` in `./frontend` to format frontend files
    - [ ] 20.4 Run `pnpm typecheck` in `./frontend` to verify no TypeScript errors

---

## Notes

- **Migration ordering**: The `document_required_configs` migration (1.1) must run before `document_read_confirmations` (1.2) due to the foreign key dependency.
- **CASCADE on config delete**: `document_read_confirmations.document_required_config_id` uses CASCADE — confirmations are deleted when the config is deleted.
- **SET NULL on document delete**: `document_required_configs.document_id` uses SET NULL — config remains but loses its document reference. Admin UI should flag orphaned configs.
- **No `scope` column**: Scope is derived from `workplace_id` (NULL = system, non-NULL = workplace). The model provides a computed `scope` accessor appended to JSON output.
- **No `confirmed_at` column**: `created_at` on `document_read_confirmations` serves as the confirmation timestamp. Records are immutable (`firstOrCreate`, never updated).
- **Backfill is opt-in**: Creating/activating a config does NOT auto-backfill. Admin must request backfill count, review it in a confirm modal, and explicitly trigger it.
- **Permission reuse for config endpoints**: The system-wide config endpoints reuse existing `config.list` / `config.edit` permissions rather than introducing new permission names, keeping the permission table lean.
- **FCM notification payload**: Push notifications include `type: "document_read_required"` and the full pending document data (id, titles, document_url, workplace_id) so the mobile app can show the popup without calling back to the server.
- **`ShiftUserApproved` already fires after DB commit** (`ShouldDispatchAfterCommit`): The new `CheckWorkplaceDocumentRequirementsListener` benefits from this — it can safely query the just-committed `ShiftUser` record.
- **Risk — backfill job with large employee base**: If the employee count grows to tens of thousands, the backfill job chunking (task 9.1) must ensure each chunk's FCM calls do not exceed FCM rate limits. Consider adding a short `usleep` between chunks or using `Bus::batch()` for controlled concurrency.
- **Frontend document picker (task 16.3)**: The existing document list API (`GET /api/admin/document`) must be called to populate the `document_id` Select. Ensure the API is accessible within the config form's context (may require a dedicated lightweight list endpoint returning just `id + title`).
