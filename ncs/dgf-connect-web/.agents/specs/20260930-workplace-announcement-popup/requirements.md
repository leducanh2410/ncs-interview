# Requirements Document

## Introduction

This feature adds a workplace-scoped popup announcement that admins author on the web and that employees see as a modal the moment they open the mobile app. Today the only admin-authored message is the `notifications` record: it is broadcast to every employee through a Firebase topic, it has a single send time instead of an applicability period, it holds at most 255 characters of plain text, it cannot carry an image, and it is delivered into the notification list rather than shown proactively. The new Announcement is a separate entity with its own tables, admin screen, and app endpoint. It targets one or more workplaces, is valid only between an admin-chosen start and end, carries rich-text content and an optional image, and is presented as a popup after the app's existing mandatory-popup chain has finished. **This is additive**: the existing `notifications` table, its admin screen, the `PushNotificationToUser` job, and every mobile notification endpoint are left untouched, and no push notification is sent for an announcement.

### Feature Summary

Admins create period-bound, workplace-targeted rich-text announcements with an optional image, and the employee app shows every currently valid announcement for the employee's workplaces as a popup each time the app is opened.

### Business Value

- Lets a workplace manager reach only the employees of specific workplaces with operational notices (schedule changes, safety reminders, event details) without spamming the whole company.
- Guarantees the message is actually seen: a popup on app open is far more visible than one more row in the notification list.
- Lets the admin set the notice's lifetime once instead of remembering to delete it, so stale notices never linger.
- Supports images and formatted text, which the current notification cannot, so posters and multi-paragraph instructions can be shown as-is.

### Scope

**Included:**

- New `announcements`, `announcement_translations`, and `announcement_workplaces` tables.
- Admin CRUD API under `manage/announcements` gated by four new permissions `announcement.list`, `announcement.create`, `announcement.edit`, `announcement.delete`, mirroring the notification permissions.
- Admin web page (list + create/update modal) under `frontend/src/pages/manage/announcement`.
- Per-locale title and content translations with the existing AI-translate action.
- Single optional image per announcement, uploaded from the admin form and served to the app by URL.
- Mobile endpoint returning the currently valid announcements for the authenticated employee.
- Mobile popup dialog, shown once per app cold start after the mandatory-popup chain (documents, exams, surveys) is clear.

**Excluded:**

- Any push notification (Firebase) for announcements.
- Any change to the `notifications` table, the admin notification screen, or the mobile notification list.
- Per-user read tracking or "do not show again" state. An announcement is shown on every cold start while it is valid.
- Re-showing the popup when the app returns from background (foreground resume).
- Targeting individual employees or groups. Targeting is by workplace only.
- Admin analytics on how many employees saw an announcement.
- Multiple images or file attachments per announcement.

## Glossary

| Term | Definition |
|------|------------|
| Announcement | A record in the new `announcements` table: title, rich-text content, optional image, validity period, active flag, and one or more target workplaces. |
| Announcement_Translation | A row in `announcement_translations` holding the title and content of one Announcement in one locale other than the base locale. |
| Base_Locale | The locale the admin writes the base `title` and `content` in, currently `vi` (`config('app.base_locale')`). |
| Validity_Period | The half-open interval `[start_at, end_at)` during which an Announcement is eligible to be shown. |
| Active_Flag | The `is_active` boolean an admin toggles to suspend or resume an Announcement without editing its period. |
| Valid_Announcement | An Announcement whose Active_Flag is true and whose Validity_Period contains the current server time. |
| Target_Workplace | A workplace linked to an Announcement through `announcement_workplaces`. |
| Workplace_Membership | A row in `workplace_users` linking an employee to a workplace. It is the sole authority for which announcements an employee may see. |
| Announcement_Image | The single optional image stored on the app's default filesystem disk under `announcements/{id}` and exposed to clients as a URL. |
| Admin_Announcement_API | The `manage/announcements` REST endpoints used by the admin web. |
| Employee_Announcement_API | The `user/announcements/active` endpoint used by the mobile app. |
| Announcement_List_Screen | The admin web list page at `frontend/src/pages/manage/announcement`. |
| Announcement_Form | The admin create/update modal on the Announcement_List_Screen. |
| Rich_Text_Editor | The existing `@mantine/tiptap` editor already used for documents, producing HTML. |
| Mandatory_Popup_Chain | The existing sequence in the mobile `HomeTab` that shows pending documents, then pending exams, then pending surveys before the employee can use the app. |
| Announcement_Popup | The new mobile dialog presenting one Valid_Announcement (image, title, HTML content, close button). |
| Cold_Start | The mobile app being launched from a terminated state and reaching `HomeTab` after `SplashScreen` or login. |
| Translate_Action | The existing `POST admin/translate` endpoint backed by `TranslateContentAction`, which the admin form calls to fill other locales. |

## Requirements

### Requirement 1: Announcement Data Model

**User Story:** As the system, I want announcements stored separately from notifications with their own period, targets, image, and translations, so that the existing broadcast notification pipeline is not altered and announcement rules can evolve independently.

#### Acceptance Criteria

1. WHEN the migrations run THEN the system SHALL create an `announcements` table with `id`, `title` (string 255), `content` (mediumtext, HTML), `image_path` (nullable string), `start_at` (datetime), `end_at` (datetime), `is_active` (boolean, default true), `created_by` (nullable FK to users, null on delete), timestamps, and a soft-delete column.
2. WHEN the migrations run THEN the system SHALL create an `announcement_translations` table with `announcement_id` (FK, cascade delete), `lang` (2 chars), `title`, `content` (mediumtext), timestamps, and a unique index on (`announcement_id`, `lang`).
3. WHEN the migrations run THEN the system SHALL create an `announcement_workplaces` pivot with `announcement_id` and `workplace_id` (both FK, cascade delete) and a unique index on the pair.
4. IF an Announcement is soft-deleted THEN the system SHALL exclude it from every query in this feature, including the Employee_Announcement_API.
5. WHERE `start_at` and `end_at` are stored THEN the system SHALL store them in the application timezone convention already used by `notifications.scheduled_at`.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None.

### Requirement 2: Admin Creates and Updates an Announcement

**User Story:** As an admin, I want to create an announcement with a title, rich-text content, an optional image, target workplaces, and a validity period, so that employees of those workplaces see it while it is relevant.

#### Acceptance Criteria

1. WHEN an admin submits the Announcement_Form THEN the Admin_Announcement_API SHALL require `title` (max 255), `content` (HTML, max 65535 characters, and non-empty after stripping tags and whitespace entities), `workplace_ids` (array, min 1, each an existing workplace), `start_at` and `end_at` (datetime), and `is_active` (boolean).
2. IF `end_at` is not strictly after `start_at` THEN the Admin_Announcement_API SHALL reject the request with a validation error on `end_at`.
3. IF an `image` file is submitted THEN the Admin_Announcement_API SHALL accept only `jpg`, `jpeg`, `png`, and `webp` files up to 8192 KB, and SHALL store it resized to at most 1200 px wide under `announcements/{id}` on the default disk.
4. WHEN an announcement is updated with a new image THEN the system SHALL delete the previous Announcement_Image from storage only after the transaction has committed, and SHALL delete the newly stored file if the transaction fails.
5. WHEN an announcement is updated with `remove_image = true` and no new image THEN the system SHALL delete the Announcement_Image and set `image_path` to null.
6. WHEN the request contains `title_{lang}` and `content_{lang}` for a locale in `Locale::getBaseTranslationLocales()` THEN the system SHALL upsert an Announcement_Translation for that locale, and SHALL delete the translation row when both fields are absent or empty.
7. IF `title_{lang}` is present without `content_{lang}` or vice versa THEN the Admin_Announcement_API SHALL reject the request with a validation error.
8. WHEN an announcement is created or updated THEN the system SHALL sync `announcement_workplaces` to exactly the submitted `workplace_ids`.
9. WHEN the create or update succeeds THEN the Admin_Announcement_API SHALL return the announcement with its `workplaces`, `translations`, and resolved `image_url`.
10. WHEN the create or update is performed THEN the system SHALL run the database writes and image storage inside one transaction so a storage failure leaves no partial record.
11. WHERE the announcement already exists THEN the Admin_Announcement_API SHALL allow editing regardless of whether the Validity_Period has started, because an announcement is period-based content rather than a one-shot send.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Requirement 5.

### Requirement 3: Admin Lists and Deletes Announcements

**User Story:** As an admin, I want to see all announcements with their workplaces, period, and current state, and delete ones I no longer need, so that I can manage what employees are being shown.

#### Acceptance Criteria

1. WHEN an admin requests the list THEN the Admin_Announcement_API SHALL return a paginated list ordered by `start_at` descending, each item including `workplaces` (id, name), `translations`, `image_url`, and a computed `state`.
2. WHEN computing `state` THEN the system SHALL return `inactive` if Active_Flag is false, otherwise `scheduled` if now is before `start_at`, `active` if now is within the Validity_Period, and `expired` if now is at or after `end_at`.
3. WHEN the list request carries `workplace_id`, `state`, or `keyword` filters THEN the Admin_Announcement_API SHALL apply them, treating the value `all` as no filter, and `keyword` SHALL match the base title or any translated title with `%` and `_` treated literally.
4. WHEN an admin deletes an announcement THEN the system SHALL soft-delete the record, clear its `image_path`, and remove its Announcement_Image from storage.
5. IF a deleted announcement is requested for update or delete again THEN the Admin_Announcement_API SHALL respond `404 Not Found`.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1, Requirement 5.

### Requirement 4: Admin Web Screen

**User Story:** As an admin, I want a dedicated Announcement page in the management sidebar with a list and a create/update modal, so that authoring announcements feels the same as the other management screens.

#### Acceptance Criteria

1. WHEN an admin with `announcement.list` opens the page THEN the Announcement_List_Screen SHALL show columns for title, target workplaces, validity period, state badge, image thumbnail, and edit/delete actions, using the existing data table and confirm modal components.
2. WHEN the admin opens the Announcement_Form THEN it SHALL provide a title input, a Rich_Text_Editor for content, a multi-select of workplaces from master data, a start date-time and end date-time input, an active switch, and an image dropzone with preview and a remove action.
3. WHEN the admin enables a translation locale THEN the Announcement_Form SHALL show a title input and a Rich_Text_Editor for that locale, and SHALL offer an AI-translate button that calls the Translate_Action with the base title and content.
4. IF the admin submits with `end_at` not after `start_at`, no workplace, or an empty title or content THEN the Announcement_Form SHALL show inline validation errors without calling the API.
5. WHEN a create, update, or delete succeeds THEN the Announcement_List_Screen SHALL refresh its query and show a success toast.
6. WHERE the sidebar is rendered THEN the system SHALL show the new Announcement item next to Notification, visible only to users holding `announcement.list`.
7. WHEN any user-facing text is rendered THEN the Announcement_List_Screen and Announcement_Form SHALL use the translation function with `vi`, `en`, and `ja` entries.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2, Requirement 3, Requirement 5.

### Requirement 5: Permissions

**User Story:** As a global admin, I want announcement management gated by the same four permissions the notification feature uses, so that roles can be configured consistently and a viewer-only role is possible.

#### Acceptance Criteria

1. WHEN the permission enum is loaded THEN the system SHALL include `announcement.list`, `announcement.create`, `announcement.edit`, and `announcement.delete`, declared directly after the notification permissions so the role screen shows the group right below Notification.
2. WHEN `RolePermissionSeeder` runs THEN the system SHALL grant the four permissions to the Global Admin and Admin roles the same way every other permission is granted, and a data migration SHALL grant them on already-seeded environments.
3. IF a caller lacks the matching permission THEN the Admin_Announcement_API SHALL respond `403 Forbidden` for list (`announcement.list`), create (`announcement.create`), update (`announcement.edit`), and delete (`announcement.delete`) respectively.
4. WHEN a caller holds `announcement.create` or `announcement.edit` THEN the system SHALL allow them to call the Translate_Action.
5. WHERE the admin web declares permissions THEN the system SHALL add `ANNOUNCEMENT_LIST`, `ANNOUNCEMENT_CREATE`, `ANNOUNCEMENT_EDIT`, and `ANNOUNCEMENT_DELETE` to the frontend permission enum, gate the route and sidebar on `ANNOUNCEMENT_LIST`, and disable the create, edit, and delete controls on the list page according to the other three.
6. WHERE the role screen renders the permission tree THEN the system SHALL show the group label and the four permission labels translated in `vi`, `en`, and `ja`.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: None.

### Requirement 6: Employee Retrieves Valid Announcements

**User Story:** As an employee, I want the app to fetch the announcements that apply to my workplaces and are currently valid, so that I only see notices that concern me and are still relevant.

#### Acceptance Criteria

1. WHEN an authenticated employee calls the Employee_Announcement_API THEN the system SHALL return every Valid_Announcement that has at least one Target_Workplace matching one of the employee's Workplace_Memberships.
2. IF the employee has no Workplace_Membership THEN the Employee_Announcement_API SHALL return an empty list.
3. IF an announcement targets several of the employee's workplaces THEN the Employee_Announcement_API SHALL return it exactly once.
4. WHEN announcements are returned THEN the system SHALL order them by `start_at` descending, then `id` descending.
5. WHEN an announcement is returned THEN each item SHALL include `id`, `title`, `content`, `image_url` (null when no image), `start_at`, `end_at`, and `translations` (lang, title, content).
6. IF a locale lacks an Announcement_Translation THEN the mobile app SHALL fall back to the base `title` and `content`.
7. WHERE `image_url` is produced THEN the system SHALL resolve it through the existing storage URL helper so the app can load it without authentication headers.
8. IF the caller is an admin-only user without a Workplace_Membership THEN the Employee_Announcement_API SHALL return an empty list rather than an error.

#### Additional Details

- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1.

### Requirement 7: Mobile Popup on App Open

**User Story:** As an employee, I want valid announcements for my workplaces to pop up as soon as I open the app, so that I cannot miss them.

#### Acceptance Criteria

1. WHEN the app reaches `HomeTab` on a Cold_Start and the employee's paper status is approved THEN the app SHALL call the Employee_Announcement_API.
2. WHILE the Mandatory_Popup_Chain still has pending documents, exams, or surveys THEN the app SHALL NOT show the Announcement_Popup.
3. WHEN the Mandatory_Popup_Chain is clear and the fetched list is non-empty THEN the app SHALL show the Announcement_Popup for the first item, and after it is closed SHALL show the next item until the list is exhausted.
4. WHEN the Announcement_Popup is shown THEN it SHALL display the Announcement_Image when present, the title and content in the app's current locale with fallback to the base text, and a close button, with content rendered from HTML and absolute links opened in the external browser.
5. IF the Announcement_Popup is dismissed by the system back gesture THEN the app SHALL treat it the same as pressing close.
6. WHILE the app process stays alive THEN the app SHALL show each announcement id at most once, even if `HomeTab` is rebuilt, and SHALL clear that memory on logout and on a new login.
7. IF the Employee_Announcement_API fails THEN the app SHALL log the error and continue without a popup or an error dialog.
8. WHERE the employee's paper status is not approved THEN the app SHALL NOT call the Employee_Announcement_API.

#### Additional Details

- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 6.

## Non-Functional Requirements

### Performance Requirements

- WHEN the Employee_Announcement_API is called THEN the system SHALL answer with a single query joining `announcements`, `announcement_workplaces`, and `workplace_users`, plus one eager load of translations, using an index on (`is_active`, `start_at`, `end_at`).
- WHEN the admin list is requested THEN the system SHALL eager-load workplaces and translations to avoid N+1 queries.

### Security Requirements

- WHEN any Admin_Announcement_API endpoint is called THEN the system SHALL require an authenticated user holding the `announcement.*` permission matching the action.
- WHEN the Employee_Announcement_API is called THEN the system SHALL derive the workplace set solely from the caller's Workplace_Memberships and SHALL ignore any client-supplied workplace id.
- WHEN HTML content is rendered in the admin web THEN the system SHALL render it only inside the Rich_Text_Editor or a sanitised preview, never via raw HTML injection of untrusted input.
- WHEN an image is uploaded THEN the system SHALL validate MIME type and size server-side and re-encode it through the image helper rather than storing the raw upload.

### Usability Requirements

- WHEN the Announcement_Form validates dates THEN it SHALL show the error on the end date field with a message explaining that the end must be after the start.
- WHEN the Announcement_Popup shows content longer than the screen THEN the content area SHALL scroll while the close button stays reachable.

### Reliability Requirements

- WHEN image storage fails during create or update THEN the system SHALL roll back the database transaction and return an error, leaving no orphan record.
- WHEN the Employee_Announcement_API fails on the mobile side THEN the app SHALL remain fully usable.

## Constraints and Assumptions

### Technical Constraints

- Backend uses the Action pattern for all new business logic and imports every class with `use` statements.
- Frontend text must go through `t(...)` and the `pnpm trans:prepare` / `trans:apply` workflow.
- Mobile models use `json_serializable`, states use `freezed`, and generated code must be produced with `build_runner`; localisation keys go into all five `.arb` files.
- The app's default filesystem disk is `local` in development and may be S3 in production, so image URLs must be produced through `UrlHelper::getStorageUrl`.
- The mobile app already depends on `flutter_html` and `cached_network_image`, which the Announcement_Popup reuses.

### Assumptions

- "Opening the app" means a Cold_Start. Showing the popup again on foreground resume is out of scope and can be added later in `RootScreen`'s lifecycle hook.
- An employee may belong to several workplaces and should see announcements from all of them.
- The base content is authored in Vietnamese; other locales are optional and fall back to the base.
- The translate service preserves HTML tags when asked to translate the content; the new `announcement` context instructs it to do so.
- Announcements are short-lived operational notices, so no archive or history view is needed on the admin side beyond the filterable list.

## Success Criteria

### Definition of Done

- [ ] All acceptance criteria in Requirements 1 to 7 are met.
- [ ] Backend feature tests cover permissions, validation, the happy path, image replace/remove, translation upsert/delete, workplace scoping, validity period boundaries, soft-delete exclusion, and transaction rollback on storage failure.
- [ ] Frontend unit tests cover the list rendering, form validation, and submit payload of the Announcement_Form.
- [ ] Mobile popup shows after the Mandatory_Popup_Chain and once per cold start, verified on both platforms.
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck`, `npm run prettier`, and `npm run pint` pass, and `pnpm trans:apply` has populated `vi` and `ja`.
