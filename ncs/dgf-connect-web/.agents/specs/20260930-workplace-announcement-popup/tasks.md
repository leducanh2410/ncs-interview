# Implementation Plan: Workplace Announcement Popup

## Overview

The work is built bottom-up in three vertical slices that can each be verified on their own: backend first (schema, permission, model, admin API, employee API, tests), then the admin web page against the real API, then the mobile popup against the real employee endpoint. Inside the backend the order is schema → permission → model → admin write path → admin read path → employee read path, so every later step has a reachable state produced by real endpoints, which is what the lifecycle tests rely on. The web and mobile slices are independent of each other and may be done in either order once the backend is merged locally.

### Implementation Strategy

- Foundation-first inside the backend, feature-slice across the three apps.
- Every backend state used by a test is produced through the real endpoints at least once (create → update → replace image → remove image → delete).
- Reuse over new code: `ImageHelper`, `UrlHelper`, `TranslateContentAction`, `PollController` shape, the document `TextEditor`, the policy dialog pattern.
- Run the project's completion checks at the end of each slice, not only at the end.

## Implementation Plan

### Phase 1: Backend foundation

- [x] 1. Schema and permission
    - [x] 1.1 Create migration `2026_09_30_000002_create_announcements_tables.php`
        - Tables `announcements`, `announcement_translations`, `announcement_workplaces` exactly as in design.md, including the composite index and unique indexes
        - _Requirements: 1.1, 1.2, 1.3_

    - [x] 1.2 Add `PermissionName::AnnouncementList/Create/Edit/Delete` (`announcement.list` etc.)
        - Declared right after the notification cases
        - Add `AnnouncementCreate` and `AnnouncementEdit` to the `admin/translate` permission list in `routes/api.php`
        - _Requirements: 5.1, 5.4_

    - [x] 1.3 Data migration `2026_09_30_000001_grant_announcement_permissions.php`
        - Insert the four permission rows if missing and attach them to Global Admin and Admin roles; reversible
        - Confirm `RolePermissionSeeder` picks it up through `PermissionName::values()`
        - _Requirements: 5.2_

- [x] 2. Models and enum
    - [x] 2.1 `App\Enums\AnnouncementState` (`inactive`, `scheduled`, `active`, `expired`) using `EnumTrait`
        - _Requirements: 3.2_

    - [x] 2.2 `App\Models\Announcement`
        - `SoftDeletes`, casts, `translations()`, `workplaces()`, `creator()`
        - `scopeValidAt`, `scopeForWorkplaceIds`, appended `image_url` and `state` accessors
        - _Requirements: 1.4, 1.5, 3.2, 6.7_

    - [x] 2.3 `App\Models\AnnouncementTranslation`
        - Fillable `announcement_id`, `lang`, `title`, `content`
        - _Requirements: 1.2_

    - [x] 2.4 Model factories for `Announcement` and `AnnouncementTranslation`
        - Default to an active window around `now()`
        - _Requirements: 1.1_

- [x] 3. Checkpoint - Migrate and run the unit tests for the model
    - `tests/Unit/Models/AnnouncementTest.php`: `state` accessor four cases plus both boundaries; `validAt` excludes inactive, future, past, soft-deleted
    - _Requirements: 1.4, 3.2_

### Phase 2: Backend admin API

- [x] 4. Requests
    - [x] 4.1 `Manage/Announcement/IndexRequest`
        - `page`, `page_size`, `workplace_id`, `state`, `keyword`
        - _Requirements: 3.3_

    - [x] 4.2 `Manage/Announcement/StoreRequest`
        - Rules from design.md Data Models, `end_at` uses `after:start_at`, per-locale `required_with` pairs
        - _Requirements: 2.1, 2.2, 2.3, 2.7_

    - [x] 4.3 `Manage/Announcement/UpdateRequest extends StoreRequest`
        - Adds `remove_image` boolean
        - _Requirements: 2.5_

- [x] 5. Actions (`app/Actions/Manage/Announcement/`)
    - [x] 5.1 `SyncAnnouncementTranslationsAction`
        - Upsert per locale in `Locale::getBaseTranslationLocales()` when both fields are filled, delete otherwise
        - _Requirements: 2.6_

    - [x] 5.2 `StoreAnnouncementImageAction`
        - `ImageHelper::handleImageUpload($file, "announcements/{$id}", 1200, $oldPath)` and save the path
        - _Requirements: 2.3, 2.4_

    - [x] 5.3 `CreateAnnouncementAction`
        - `DB::transaction`: create with `created_by`, `sync` workplaces, translations, image; return reloaded model with `workplaces`, `translations`
        - _Requirements: 2.1, 2.8, 2.9, 2.10_

    - [x] 5.4 `UpdateAnnouncementAction`
        - Same as create on the existing row; handle `image` replacement and `remove_image`
        - _Requirements: 2.4, 2.5, 2.8, 2.9, 2.10, 2.11_

    - [x] 5.5 `DeleteAnnouncementAction`
        - Soft delete in a transaction, delete the file after commit
        - _Requirements: 3.4_

    - [x] 5.6 `GetAnnouncementsAction`
        - Eager loads, filters (`state` translated to SQL on `is_active`/`start_at`/`end_at`), order `start_at desc, id desc`, paginate
        - _Requirements: 3.1, 3.2, 3.3_

- [x] 6. Controller and routes
    - [x] 6.1 `Manage\AnnouncementController` with `index`, `store`, `update`, `destroy` mirroring `PollController`
        - _Requirements: 2.9, 3.1, 3.5_

    - [x] 6.2 Routes under the manage group
        - `GET /manage/announcements`, `POST /manage/announcements`, `POST /manage/announcements/{announcement}` (method spoofing to PUT), `DELETE /manage/announcements/{announcement}`, each with its own `permission:announcement.list|create|edit|delete` middleware
        - _Requirements: 5.3_

- [x] 7. Translate context
    - [x] 7.1 Add `CONTEXT_ANNOUNCEMENT` to `OpenRouterTranslationService::contexts()` with the HTML-preserving prompt line
        - _Requirements: 4.3_

- [x] 8. Checkpoint - Admin lifecycle feature test
    - `tests/Feature/Manage/AnnouncementLifecycleTest.php` as listed in design.md, using `Storage::fake` and a forced storage failure
    - _Requirements: 2.1 to 2.11, 3.1 to 3.5, 5.3_

### Phase 3: Backend employee API

- [x] 9. Employee endpoint
    - [x] 9.1 `App\Actions\User\Announcement\GetActiveAnnouncementsForUserAction`
        - Workplace ids from `workplace_users`; `validAt(now())->forWorkplaceIds()->with('translations')` ordered `start_at desc, id desc`
        - Serialise `id`, `title`, `content`, `image_url`, `start_at`, `end_at`, `translations`
        - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.7, 6.8_

    - [x] 9.2 `User\AnnouncementController::getActive` and route `GET /user/announcements/active` next to `user/notifications`
        - _Requirements: 6.1_

- [x] 10. Checkpoint - Employee feature test
    - `tests/Feature/Api/User/ActiveAnnouncementsTest.php`: membership intersection, once per announcement, `is_active`, both boundaries with `Carbon::setTestNow`, soft-deleted excluded, empty for no membership and admin-only, `image_url` null and translations present
    - Seed announcements through the admin endpoint at least once so the reachable state is proven
    - _Requirements: 6.1 to 6.8_

- [x] 11. Backend completion checks
    - [x] 11.1 `docker compose exec php php artisan test --parallel --filter Announcement`
    - [x] 11.2 `npm run prettier` and `npm run pint` in `./backend`

### Phase 4: Admin web

- [x] 12. Configuration
    - [x] 12.1 `ANNOUNCEMENT_LIST`, `ANNOUNCEMENT_CREATE`, `ANNOUNCEMENT_EDIT`, `ANNOUNCEMENT_DELETE` right after `NOTIFICATION_DELETE` in `permission_name.enum.ts`, role labels in `locales/*/role.json`, `MANAGE_ANNOUNCEMENT_LIST` / `MANAGE_ANNOUNCEMENT_DETAIL` in `api.config.ts`
        - _Requirements: 5.5_

    - [x] 12.2 `RouteConfig.MANAGE_ANNOUNCEMENT`, sidebar item after Notification with `LuMegaphone`, `route.json` entries and `for_scanner.ts`
        - _Requirements: 4.6_

    - [x] 12.3 Types `announcement.d.ts` and enum `announcement_state.enum.ts`
        - _Requirements: 3.2_

- [x] 13. Page
    - [x] 13.1 `api.ts` with list query and create/update/delete mutations (`postForm`, `_method=PUT`), invalidating `['announcements']`
        - _Requirements: 4.5_

    - [x] 13.2 `partials/announcement_form.ts`: initial values, schema (empty editor detection, end after start, at least one workplace), `buildFormData`
        - _Requirements: 4.4_

    - [x] 13.3 `partials/announcement_create_update.tsx`
        - Title, tiptap editor (reuse document `TextEditor`), workplace `MultiSelect`, `DateTimePicker` ×2, `Switch`, image `Dropzone` with preview and remove, per-locale translation block with AI translate (`context: 'announcement'`)
        - Populate editors from `announcement` on edit
        - _Requirements: 4.2, 4.3, 4.4, 4.7_

    - [x] 13.4 `index.page.tsx`
        - `CommonSearchBox` filters, `DDataTable` columns (thumbnail, title with language flags, workplaces, period, state badge, actions), `DConfirmModal` delete, `usePermissions([...])` with list/create/edit/delete mapped to query, create button, edit icon, delete icon
        - _Requirements: 4.1, 4.5, 4.7_

- [x] 14. Translations
    - [x] 14.1 `pnpm trans:prepare`, fill `vi` and `ja`, `pnpm trans:apply`
        - _Requirements: 4.7_

- [x] 15. Checkpoint - Frontend tests and checks
    - `index.page.test.tsx` and `partials/announcement_create_update.test.tsx` per design.md
    - `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`
    - _Requirements: 4.1 to 4.7_

### Phase 5: Mobile app (`dgf-connect-app`, branch `feat/DGFC_113_notification_popup`)

- [x] 16. Data layer
    - [x] 16.1 `Endpoint.activeAnnouncements('/user/announcements/active')`
        - _Requirements: 7.1_

    - [x] 16.2 `models/entities/announcement/announcement.dart` and `announcement_response.dart` (`json_serializable`), with `titleFor(lang)` / `contentFor(lang)` fallback
        - _Requirements: 6.5, 6.6_

    - [x] 16.3 `repositories/announcement_repository.dart` following `NotificationRepositoryImpl`
        - _Requirements: 7.1, 7.7_

    - [x] 16.4 `models/states/announcement_flow_state/announcement_flow_state.dart` (`freezed`) and `providers/announcement_provider.dart` (non-autoDispose `StateNotifierProvider`) with `load()`, `seed()`, `markShownAndAdvance()`, cleared on logout
        - _Requirements: 7.3, 7.6_

    - [x] 16.5 Run `build_runner` for the new generated files
        - _Requirements: 7.1_

- [x] 17. UI
    - [x] 17.1 `widgets/popup/announcement_dialog_content.dart`
        - Optional `CachedNetworkImage`, title, `Html(data:)` in a bounded `SingleChildScrollView`, close button
        - _Requirements: 7.4_

    - [x] 17.2 `showAnnouncementDialog` in `dialog_helper.dart` (`showDialog` + `PopScope` + transparent `Dialog`), back gesture routed to `onClose`
        - _Requirements: 7.4, 7.5_

    - [x] 17.3 `HomeTab` hook
        - `useEffect` on paper status calling `load()`; `useEffect` showing `currentItem` only when docs, exams, and surveys are all clear; `onClose` → `markShownAndAdvance()`
        - _Requirements: 7.1, 7.2, 7.3, 7.6, 7.8_

    - [x] 17.4 Localisation keys in all five `.arb` files and `intl_utils:generate`
        - _Requirements: 7.4_

- [x] 18. Checkpoint - Verification on the Android emulator (iOS still to be checked by the team)
    - Popup order after mandatory chain, sequential display of two announcements, English and Japanese rendering, locale fallback verified on emulator
    - Unit tests for `AnnouncementFlowController` and `Announcement` added under `test/`
    - Run `format.sh` and `flutter analyze`
    - _Requirements: 7.1 to 7.8_

### Phase 6: Final validation

- [x] 19. Code quality
    - [x] 19.1 Backend: `npm run prettier`, `npm run pint`, full `php artisan test --parallel`
    - [x] 19.2 Frontend: `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `pnpm test`
    - [x] 19.3 Mobile: `format.sh`, `flutter analyze`
    - [x] 19.4 Remove any comments added during implementation; verify no locale JSON was edited by hand

## Notes

- **Multipart update**: Laravel does not parse multipart bodies on `PUT`, so the update route is `POST` with `_method=PUT`. The frontend `ApiHelper.postForm` must append `_method`; tests should call the route the same way.
- **Timezone**: admin inputs are in `Asia/Tokyo` and serialised as `YYYY-MM-DD HH:mm:ss`, identical to `scheduled_at`. Keep the backend comparison in the app timezone convention already used for notifications; do not convert on the employee path.
- **Empty editor**: tiptap returns `<p></p>` for an empty document. The schema must strip tags before checking emptiness or the required rule never fires.
- **Shown-once state**: keep it in a provider that survives `HomeTab` rebuilds but is reset on logout; the mobile agent report confirmed `HomeTab` is lazily loaded and can be rebuilt on tab changes.
- **Uncommitted generated files in the app repo**: the working tree carried over ~50 regenerated `*.g.dart` / `*.freezed.dart` / `lib/generated/**` files and a debug `AndroidManifest.xml` change from before the branch was created. Commit only the files this feature touches.
- **Risk**: AI translation of long HTML may exceed the 5000-character per-item limit; the form must surface the error and let the admin translate manually.
- **Out of scope reminders**: no push, no read tracking, no resume re-show. The `RootScreen` lifecycle hook is the natural extension point if resume re-show is requested later.
