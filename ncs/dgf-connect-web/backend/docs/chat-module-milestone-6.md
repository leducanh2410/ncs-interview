# Milestone 6 — Notifications, Attachments, and Hardening

## Context

The chat module (`backend/docs/chat-module.md`) is built through Milestone 5: data model,
messaging, admin/shift/support chats, close/reopen, and lifecycle auto-close all ship and broadcast
realtime over Soketi. **Milestone 6 is the final enrichment + hardening slice** that makes the
module production-ready for MVP:

1. **FCM push** so users get notified of chat activity when not actively viewing a conversation.
2. **Attachments** so image/file messages actually upload, store, and download securely (the
   `employee_can_send_image` / `employee_can_send_file` flags from M3 are currently inert).
3. **Hardening** — rate limiting, WS/Soketi timeout review, and closing test coverage gaps.

The whole stack to reuse already exists: a queued FCM job pattern (`SendPollAvailableFcmJob`),
localized strings via `__('key', locale: $locale)` over `Locale::getTranslationLocales()`, an image
helper, a private-storage disk, and the `chat_message_attachments` table (inert, waiting for M6).

**Decisions locked with the user:**
- Attachments use a **dedicated `chat_attachments` table** (not `DocumentAttachment`); re-point the
  existing `chat_message_attachments.attachment_id` FK to it.
- Upload uses a **single multipart POST** to the existing message endpoint (files + body together).
- Push is **push-only** — a dedicated immediate FCM job; **no** notification-center (`Notification`)
  rows.

Ship as the three PRs below, in order.

---

## PR 1 — FCM Notifications

Mirror the `SendPollAvailableFcmJob` pattern (`app/Jobs/SendPollAvailableFcmJob.php`). Do **not**
write `Notification` rows and do **not** reuse the everyMinute `PushNotificationToUser` job.

### New: active-conversation heartbeat (Redis)
- `POST /user/chat/active` + `POST /admin/chat/active` (body: `conversation_id`) — sets a short-TTL
  Redis key `chat:active_conversation:{user_id}` = `conversation_id`. Flutter pings on enter + while
  active. Also `DELETE` variant (or rely on TTL) to clear on leave.
- Use the **Redis store explicitly** (`Cache::store('redis')->put(..., $ttl)` or `Redis::setex`) —
  `config/cache.php` defaults `CACHE_STORE=database`, so the default Cache store will not give the
  TTL semantics we want.
- TTL from new config `chat.notifications.active_conversation_ttl` (default 45s).

### New: `app/Jobs/SendChatMessageFcmJob.php`
- Constructor: `array $recipientUserIds, int $conversationId, array $titleByLocale, array $bodyByLocale, array $data`.
- `handle(Messaging $messaging)`: load users with `devices` where `fcm_token` not null; per user:
  - **Suppress** if `chat:active_conversation:{user_id}` === `$conversationId` (best-effort only).
  - Resolve locale from `$user->settings['lang']`, pick localized title/body (fallback `en`).
  - `CloudMessage::new()->withNotification(...)->withData($data)`; `sendMulticast($message, $tokens)`
    wrapped in try/catch + `App::environment() !== 'local'` guard, exactly like the poll job.

### New: `app/Actions/Chat/Notification/NotifyChatRecipientsAction.php` (chokepoint)
- Resolves recipients = active participants of the conversation **minus the actor/sender**.
- Builds `titleByLocale` / `bodyByLocale` over current locale + `Locale::getTranslationLocales()`
  using `__('chat.notification.*', locale: $locale)`:
  - title = conversation title (or sender name fallback);
  - body for a message: text → truncated `body`; image → `chat.notification.sent_image`;
    file → `chat.notification.sent_file`; system events → per-`ChatSystemMessageType` key.
- `data` payload: `type`, `conversation_id`, `message_id` for deep-linking.
- Dispatches `SendChatMessageFcmJob`.

### Wire-in points (dispatch after DB commit, next to existing `NewChatMessage::dispatch`)
- `app/Actions/Chat/Message/SendMessageAction.php` — new user/admin message.
- `app/Actions/Chat/Support/CreateSupportConversationAction.php` — support chat created (notify
  chat-assignment admins; see note below).
- `app/Actions/Chat/Support/AssignSupportAdminsAction.php` — admin assigned.
- `app/Actions/Chat/Lifecycle/{Close,Reopen}ConversationAction.php` — close/reopen.
- (Optional, same pattern) participant add/remove actions.

> Support pre-assignment: recipients for an unassigned support chat are the chat-assignment admins.
> Reuse the role/visibility resolution already in `ResolveConversationViewAccessAction` rather than
> re-deriving it.

### New backend lang keys
Add `lang/{en,vi,ja}/chat.php` with `notification.sent_image`, `notification.sent_file`, and one key
per relevant `ChatSystemMessageType`. (These are localized at send time per recipient.)

### Config / env
`config/chat.php` → `'notifications' => ['active_conversation_ttl' => (int) env('CHAT_ACTIVE_CONVERSATION_TTL', 45)]`.

### Tests (`tests/Feature/Chat/...`)
- Recipient resolution excludes sender; suppression skips a user whose Redis active key matches.
- Job dispatched on send/assign/close (use `Bus::fake()` / `Queue::fake()`).
- Localization picks the recipient's locale.
- FCM send itself is skipped in `local` env — assert recipient/suppression logic, not the FCM call.

---

## PR 2 — Attachments

### Schema
1. **New migration — `chat_attachments`**: `id`, `disk` (string), `path` (string),
   `original_name` (string), `mime_type` (string), `size_bytes` (unsignedBigInteger),
   `created_by_user_id` (nullable FK `users`, nullOnDelete), timestamps.
2. **New migration — re-point FK**: on `chat_message_attachments`, drop the existing
   `attachment_id` FK to `document_attachments` and re-add `->constrained('chat_attachments')->cascadeOnDelete()`.
   Safe because the table is inert (no rows). Mind the auto-generated FK constraint name when
   dropping.

### Models
- **New `app/Models/ChatAttachment.php`**: `$guarded = []`; `belongsTo` creator; a `url` accessor
  via `UrlHelper::getStorageUrl()` is **not** used for the response — instead the payload returns a
  guarded download route (below).
- **Update `app/Models/ChatMessageAttachment.php`**: `attachment()` now `belongsTo(ChatAttachment::class, 'attachment_id')`.
- **Update `app/Models/ChatMessage.php::toPayloadArray()`**: attachment map →
  `{ id, attachment_id, type (mime-derived: image/file), url (route to download endpoint),
  filename (original_name), mime_type, size_bytes, position }`. URL = `route('chat.attachments.download', $a->attachment_id)` so every fetch is access-checked.

### Upload flow (single multipart POST, reuses existing message endpoints)
- **Update `app/Http/Requests/Chat/SendMessageRequest.php`**:
  - `body` → `required_without:files` (was `required`);
  - `files` → `nullable|array|max:{chat.attachments.max_files}`;
  - `files.*` → `file|mimes:{allowed}|max:{chat.attachments.max_size_kb}`.
- Validated payload includes `UploadedFile`s (Laravel `validated()` returns validated files), so the
  thin User + Admin `ChatMessageController`/`MessageController@store` need **no change** — they
  already forward `$request->validated()`.
- **Update `app/Actions/Chat/Message/SendMessageAction.php`**:
  - Permission gating for employees in non-support chats: block images when `!employee_can_send_image`,
    files when `!employee_can_send_file` (alongside the existing `employee_can_send_message` check).
  - Derive `message_type`: any non-image file → `file`; all-image → `image`; no files → `text`.
  - Inside the existing transaction, after creating the message: for each file, store to
    `chat/{conversation_id}` on `config('chat.attachments.disk')` (images scaled via
    `ImageHelper::handleImageUpload`, others `->store(...)`), create a `ChatAttachment`, then a
    `ChatMessageAttachment` with incrementing `position`.
  - Keep the `client_message_id` idempotency fast-path — a retried multipart POST returns the
    existing message without re-storing files.

### Secure download
- **New `app/Http/Controllers/Chat/ChatAttachmentController.php@download`**: resolve attachment →
  message → conversation; authorize via the existing `ResolveConversationViewAccessAction` (active
  participant, or support-view admin). **Removed/non-participant users get 403/404.** Then
  `Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name)`.
- **New route** `GET /chat/attachments/{attachment}` named `chat.attachments.download` behind
  `auth:api` + `CheckUserDisabled` (shared by user and admin since access is membership-based).

### Config / env
`config/chat.php` → `'attachments' => ['disk' => env('CHAT_ATTACHMENT_DISK','local'), 'max_files' => env('CHAT_ATTACHMENT_MAX_FILES',10), 'max_size_kb' => env('CHAT_ATTACHMENT_MAX_SIZE_KB',8192), 'mimes' => 'jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx']`.

### Tests
- Upload (image + file) with `Storage::fake()`; assert `chat_attachments` + `chat_message_attachments`
  rows, derived `message_type`, broadcast payload shape.
- Employee blocked when `employee_can_send_image/file` is false; allowed when true.
- Download: participant succeeds; **removed user and non-participant denied**; support-view admin
  allowed per visibility rules.
- Idempotent retry does not duplicate files.

---

## PR 3 — Hardening

### Rate limiting (`app/Providers/AppServiceProvider.php::configureRateLimiting`)
- `chat-send-limit`: `Limit::perMinute(30)->by('chat-send|'.$request->user()?->id)`.
- `chat-support-create-limit`: `Limit::perMinute(5)->by('chat-support|'.$request->user()?->id)`.
- Apply `throttle:chat-send-limit` to both message `POST` routes (user `routes/api.php:844` and the
  admin message route) and `throttle:chat-support-create-limit` to `POST /user/chat/support-conversations`.

### Soketi / Nginx review (done)
- No custom nginx conf lives in the repo (it's baked into the php image). Prod recommendation for the
  `wss://` location that fronts Soketi:
  ```nginx
  location /app/ {                       # Pusher/Soketi websocket path
      proxy_pass http://soketi:6001;
      proxy_http_version 1.1;
      proxy_set_header Upgrade $http_upgrade;
      proxy_set_header Connection "upgrade";
      proxy_set_header Host $host;
      proxy_read_timeout 120s;           # ≥ Soketi activity timeout
      proxy_send_timeout 120s;
  }
  ```
- `docker-compose.prod.yml` now sets `SOKETI_DEFAULT_APP_MAX_CONNECTIONS`
  (`DOCKER_SOKETI_MAX_CONNECTIONS`, default 10000) and `SOKETI_DEFAULT_APP_ACTIVITY_TIMEOUT`
  (`DOCKER_SOKETI_ACTIVITY_TIMEOUT`, default 120s). The activity timeout is kept larger than the
  Flutter heartbeat / `chat.notifications.active_conversation_ttl` (default 45s) so live clients are
  not dropped between pings. Dev `docker-compose.yml` is left untouched.

### Coverage sweep
- Close gaps flagged by earlier milestones surfaced while writing PR 1/2 tests (e.g. attachment
  payload in `NewChatMessage`; throttle responses return 400 + `ErrorCode::Throttle`, the app's
  convention from `bootstrap/app.php`, not a raw 429).

---

## Task-completion checks (run in `./backend`)
- `docker compose exec php npm run pint`
- `docker compose exec php npm run prettier`
- `docker compose exec php ./vendor/bin/phpunit --filter Chat`

## End-to-end verification
1. `docker compose exec php php artisan migrate` — new `chat_attachments` table + re-pointed FK.
2. Send a multipart message with an image and a PDF; confirm `message_type`, stored files, and the
   broadcast payload over Soketi carry attachment download URLs.
3. Hit the download route as a participant (200), a removed user (403/404), and a support-view admin
   (per rules).
4. With `Bus::fake()` off and a non-active recipient with an `fcm_token`, confirm
   `SendChatMessageFcmJob` is queued; set the recipient's `chat:active_conversation` key to the same
   conversation and confirm suppression.
5. Exceed `chat-send-limit` and confirm a `400` + `E_TOO_MANY_ATTEMPTS` response (the app renders
   throttling as 400 via `bootstrap/app.php`, not a raw 429).
