# Chat Mobile API Integration Guide

Reviewed against the current backend implementation on 2026-06-08.

## Readiness Decision

Mobile can start API integration for the chat MVP now.

The current backend is ready for:

- Employee conversation list, conversation detail, message list, text send, mark read, close, and reopen.
- Employee-created support chats with an initial text message.
- Admin-created group, shift, and user-group chats that employees can read and reply to.
- Realtime updates through Laravel broadcasting with a Pusher-compatible Soketi server.
- FCM push notifications for chat events when the recipient is not actively viewing the conversation.
- Admin/support workflows if a mobile admin client is also needed.
- Per-user chat rate limiting: message send is throttled at 30 requests/minute and support-chat creation at 5 requests/minute, returning HTTP 429 when exceeded.

The current backend still needs backend confirmation for:

- Mobile attachment release. Attachment upload/download code paths and feature tests are present, but mobile should keep attachments behind a feature flag until backend confirms the attachment tests pass in the project test environment.
- A dedicated endpoint to refresh an FCM token after login.
- Fully localized/dynamic system-message rendering from API data. The API currently returns the system message key in `body`, but does not expose `system_payload`.

## API Base

All paths below are relative to the API base URL used by the app.

Current Laravel routes are registered without an automatic `/api` prefix, so the real paths are:

```text
/user/chat/...
/admin/chat/...
/broadcasting/auth
```

If a deployment exposes these behind an API gateway prefix, the mobile base URL should include that prefix. Do not use the older planning-doc paths like `/api/chat/...` unless the deployment gateway rewrites them.

## Auth

All chat endpoints require the existing `auth:api` access-token guard.

Send either:

```http
Authorization: Bearer <access_token>
Accept: application/json
Content-Type: application/json
```

The guard also accepts an `access_token` cookie, but mobile should use the bearer token.

Employee login can register the device FCM token:

```http
POST /auth/login
```

Required login fields include:

```json
{
  "email": "employee@example.com",
  "password": "secret",
  "device_id": "device-stable-id",
  "device_name": "iPhone 15",
  "platform": "ios",
  "fcm_token": "optional-fcm-token",
  "lang": "ja",
  "remember": true
}
```

Current gap: if the FCM token rotates after login, there is no dedicated token-update endpoint. Mobile should send the latest token at login, and the backend should add a small refresh endpoint if push reliability requires token updates without re-login.

## Response Envelope

Success:

```json
{
  "data": {}
}
```

Paginated success:

```json
{
  "data": {
    "data": [],
    "current_page": 1,
    "last_page": 1,
    "per_page": 15,
    "total": 0
  }
}
```

Error:

```json
{
  "error": "E_UNPROCESSABLE_ENTITY",
  "data": {
    "field": ["Validation message"]
  }
}
```

Common chat-related errors:

```text
401 AUTH_INVALID_TOKEN or E_UNAUTHORIZED
403 E_FORBIDDEN
404 E_NOT_FOUND
422 E_UNPROCESSABLE_ENTITY
422 CHAT_INVALID_CURSOR
422 CHAT_MESSAGE_NOT_IN_CONVERSATION
422 CHAT_CONVERSATION_CLOSED
422 CHAT_ALREADY_CLOSED
422 CHAT_ALREADY_OPEN
403 CHAT_REOPEN_NOT_ALLOWED
429 Too Many Requests (chat send and support-create rate limits)
```

The `429` response follows Laravel's throttle behavior and includes a `Retry-After` header (seconds). Mobile should back off and retry after that delay rather than hammering the endpoint.

All timestamps are ISO 8601 strings or `null`.

## Enums

Conversation types:

```text
admin_group
shift_group
user_group
support
```

Conversation statuses:

```text
open
closed
```

Participant roles:

```text
admin
employee
```

Message types:

```text
file
image
system
text
```

Text and system messages are the stable MVP path. Image/file messages should remain feature-flagged until backend confirms the current attachment slice.

## Core Payloads

Conversation list item:

```json
{
  "id": 123,
  "type": "support",
  "system_key": null,
  "status": "open",
  "title": "Optional title",
  "participant_count": 2,
  "unread_count": 3,
  "last_message_at": "2026-06-08T09:00:00+00:00",
  "last_message": {
    "id": 456,
    "message_type": "text",
    "body": "Last message preview",
    "sender_user_id": 10,
    "sender_name": "Yamada Taro",
    "created_at": "2026-06-08T09:00:00+00:00"
  }
}
```

Conversation detail (the admin endpoint adds `last_read_message_id` / `last_read_at` per
participant, which is what draws read receipts; the employee endpoint omits them):

```json
{
  "id": 123,
  "type": "support",
  "system_key": null,
  "status": "open",
  "title": "Optional title",
  "permissions": {
    "employee_can_send_message": true,
    "employee_can_send_image": true,
    "employee_can_send_file": true,
    "can_employee_reopen": true
  },
  "self": {
    "role": "employee",
    "unread_count": 0,
    "last_read_message_id": 456,
    "last_read_at": "2026-06-08T09:01:00+00:00"
  },
  "last_message_id": 456,
  "last_message_at": "2026-06-08T09:00:00+00:00",
  "participants": [
    {
      "user_id": 10,
      "name": "Yamada Taro",
      "role": "employee",
      "joined_at": "2026-06-08T08:50:00+00:00"
    }
  ]
}
```

Message:

```json
{
  "id": 456,
  "conversation_id": 123,
  "sender_user_id": 10,
  "client_message_id": "0fdf4e6e-4d34-4d57-8b56-2387ec0d9eb4",
  "message_type": "text",
  "body": "Hello",
  "attachments": [
    {
      "id": 1,
      "attachment_id": 99,
      "type": "image",
      "url": "https://api.example.com/chat/attachments/99",
      "filename": "photo.jpg",
      "mime_type": "image/jpeg",
      "size_bytes": 120034,
      "position": 0
    }
  ],
  "created_at": "2026-06-08T09:00:00+00:00"
}
```

System message:

```json
{
  "id": 457,
  "conversation_id": 123,
  "sender_user_id": null,
  "client_message_id": null,
  "message_type": "system",
  "body": "chat.system.conversation_closed",
  "attachments": [],
  "created_at": "2026-06-08T09:10:00+00:00"
}
```

Current system-message limitation: `body` is a translation key, and `system_payload` is stored in the database but is not returned by `toPayloadArray()`. Mobile should either map known system keys to generic local text or hide system messages until backend exposes `system_payload`.

Known system keys:

```text
chat.system.conversation_created
chat.system.title_set
chat.system.conversation_closed
chat.system.conversation_reopened
chat.system.support_admin_assigned
chat.system.support_admin_removed
chat.system.employee_permissions_updated
chat.system.participant_added
chat.system.participant_removed
```

## Employee Endpoints

### List Conversations

```http
GET /user/chat/conversations?page=1&page_size=50
```

Query:

```text
page_size: optional integer, 1 to 100, default 15
page: optional Laravel pagination page
```

Returns paginated conversation list items. Only active participant conversations are returned. Removed participants do not see old conversations.

### Create Support Conversation

```http
POST /user/chat/support-conversations
```

Body:

```json
{
  "title": "Optional title",
  "body": "Initial support message",
  "client_message_id": "0fdf4e6e-4d34-4d57-8b56-2387ec0d9eb4"
}
```

Validation:

```text
body: required string, max 5000
title: optional string, max 255
client_message_id: optional UUID string, max 64
```

Returns the created conversation payload, not the first message payload.

Rate limit: this endpoint is throttled at 5 requests/minute per user. Exceeding it returns `429 Too Many Requests` with a `Retry-After` header.

Important retry caveat: this endpoint is not currently idempotent across repeated `POST /user/chat/support-conversations` calls. If the network drops after sending, do not blindly retry the create request. Prefer fetching `/user/chat/conversations` and checking for the new support chat, or ask backend to add a conversation-level idempotency key before enabling automatic retries for support creation.

### Conversation Detail

```http
GET /user/chat/conversations/{conversation_id}
```

Returns conversation metadata, permissions, self read state, and active participants.

### List Messages

```http
GET /user/chat/conversations/{conversation_id}/messages?limit=50
GET /user/chat/conversations/{conversation_id}/messages?before_id=456&limit=50
GET /user/chat/conversations/{conversation_id}/messages?after_id=456&limit=50
```

Query:

```text
limit: optional integer, 1 to 100, default 30
before_id: optional integer, fetch older history
after_id: optional integer, fetch missed/newer messages
```

Rules:

- Do not send `before_id` and `after_id` together. Backend returns `CHAT_INVALID_CURSOR`.
- Default request returns the newest page, sorted ascending by id in the response.
- `before_id` is for backscroll.
- `after_id` is for reconnect or missed-message sync.

Response:

```json
{
  "data": {
    "messages": [],
    "has_more": false,
    "oldest_id": null,
    "newest_id": null
  }
}
```

### Send Text Message

```http
POST /user/chat/conversations/{conversation_id}/messages
```

Body:

```json
{
  "body": "Message body",
  "client_message_id": "0fdf4e6e-4d34-4d57-8b56-2387ec0d9eb4"
}
```

Validation:

```text
body: required when files is absent, nullable string, max 5000
client_message_id: optional UUID string, max 64
files: provisional attachment field, optional array, max 10 by default
files.*: provisional attachment field, file, configured mime list, max 8192 KB by default
```

For the stable text-chat path, send JSON with `body` and `client_message_id`. Use a client-generated UUID for every send. The backend enforces uniqueness per conversation and returns the existing message if the same `client_message_id` is resent to the same conversation. This makes normal message retry safe.

For feature-flagged attachments, use multipart form data instead of JSON. Do not enable this in the production mobile app until backend confirms the attachment tests and storage behavior.

Send can fail with:

```text
404 E_NOT_FOUND: user cannot access this conversation
403 E_FORBIDDEN: employee message permission disabled
422 CHAT_CONVERSATION_CLOSED: conversation is closed
422 E_UNPROCESSABLE_ENTITY: validation error
429 Too Many Requests: send rate limit exceeded (30/minute per user); honor Retry-After
```

### Mark Read

```http
POST /user/chat/conversations/{conversation_id}/read
```

Body with explicit marker:

```json
{
  "message_id": 456
}
```

Body can also be empty:

```json
{}
```

Behavior:

- If `message_id` is omitted, backend marks read through `last_message_id`.
- If `message_id` belongs to another conversation, backend returns `CHAT_MESSAGE_NOT_IN_CONVERSATION`.
- The read marker never moves backward.
- Response includes `last_read_message_id`, `last_read_at`, and `unread_count`.

Recommended mobile behavior: call this when the chat screen opens and when the newest visible message changes.

### Close Conversation

```http
POST /user/chat/conversations/{conversation_id}/close
```

Body:

```json
{
  "close_reason": "Optional reason"
}
```

Employees can close their own support conversations. Admin participants can close via the admin endpoint.

Response:

```json
{
  "data": {
    "id": 123,
    "status": "closed",
    "closed_at": "2026-06-08T09:10:00+00:00",
    "closed_by_user_id": 10,
    "close_reason": "Optional reason",
    "can_employee_reopen": true
  }
}
```

### Reopen Conversation

```http
POST /user/chat/conversations/{conversation_id}/reopen
```

Employees can reopen their own support conversation only when `can_employee_reopen` is true. Admins can reopen from the admin endpoint.

Response:

```json
{
  "data": {
    "id": 123,
    "status": "open"
  }
}
```

### Active Conversation Heartbeat

```http
POST /user/chat/active
DELETE /user/chat/active
```

POST body:

```json
{
  "conversation_id": 123
}
```

Purpose: suppress FCM push while the user is actively viewing the same conversation.

Current TTL is configured by `CHAT_ACTIVE_CONVERSATION_TTL`, default 45 seconds. Mobile should:

- POST once when entering a chat screen.
- Repeat about every 30 seconds while the screen is visible.
- DELETE when leaving the chat screen.
- Stop heartbeat when the app is backgrounded.

This heartbeat does not mark messages as read. Use the mark-read endpoint separately.

## Admin Endpoints

Mobile employee integration can ignore this section unless there is an admin/manager mobile app.

Admin endpoints require the same bearer token plus permission middleware.

### Admin List Conversations

```http
GET /admin/chat/conversations?page=1&page_size=50
```

Requires `chat.view`.

### Admin Create Conversation

```http
POST /admin/chat/conversations
```

Requires `chat.create`.

Manual group body:

```json
{
  "type": "admin_group",
  "title": "Optional title",
  "participant_user_ids": [10, 11],
  "employee_can_send_message": true,
  "employee_can_send_image": true,
  "employee_can_send_file": true
}
```

Shift group body:

```json
{
  "type": "shift_group",
  "title": "Optional title",
  "shift_id": 123
}
```

User group body:

```json
{
  "type": "user_group",
  "title": "Optional title",
  "user_group_id": 456
}
```

Rules:

- `participant_user_ids` only applies to `admin_group`.
- `shift_id` only applies to `shift_group`.
- `user_group_id` only applies to `user_group`.
- Creator cannot be included in `participant_user_ids`.
- Only approved shift users are included in shift-group chats.
- User-group chats snapshot current group members at creation.

### Manager Direct Conversation Lookup

```http
GET /manage/chat/direct-conversation?user_id={employee_id}
```

Requires `chat.view`. Lives under the `/manage` prefix (manager mobile app) rather than `/admin`, but uses the same bearer token and permission model.

Looks up the existing 1-on-1 conversation between the caller and `user_id`. There is no separate "direct" conversation type — a direct chat is simply an `admin_group` conversation whose only two active participants are the caller and `user_id` (the same shape "Admin Create Conversation" above produces when a single employee is selected). Closed conversations are still returned — the conversation-detail APIs and UI already handle the closed state.

Found (200):

```json
{
  "data": {
    "conversation_id": 123
  }
}
```

If more than one matching 1-on-1 exists (creation has no dedup for `admin_group`), the most recently active thread is returned (`last_activity_at`, then `id`).

Not found (404) — **this is a normal, expected outcome, not an error condition**:

```json
{
  "error": "E_NOT_FOUND",
  "data": null
}
```

Mobile should treat this 404 as "no direct conversation yet" and fall back to `POST /admin/chat/conversations` (`type: "admin_group"`, `participant_user_ids: [user_id]`) to start one — do not surface it as a failure to the user.

That create fallback requires `chat.create`, which is independent of the `chat.view` permission that gates this lookup. If the account does not have `chat.create`, hide or disable the start-chat action rather than following the 404 with a create call that would 403.

Invalid `user_id` (422):

```json
{
  "error": "E_UNPROCESSABLE_ENTITY",
  "data": {
    "user_id": ["The selected user id is invalid."]
  }
}
```

Looking up the caller's own id, a missing id, or a non-existent user all return this same `error` code; only the `user_id` message string differs. **Do not** fall back to create in these cases: `participant_user_ids` cannot include the creator, so that path would 422 as well. Only the 404 above is a create-fallback signal.

The client should check `user_id === me` before calling this endpoint. Tapping yourself is a normal UI case — skip the request, do not toast an error, and do not fall back to create. Treat a missing or non-existent `user_id` as a real error.

### Admin Conversation Detail, Messages, Send, Read

```http
GET  /admin/chat/conversations/{conversation_id}
GET  /admin/chat/conversations/{conversation_id}/messages
POST /admin/chat/conversations/{conversation_id}/messages
POST /admin/chat/conversations/{conversation_id}/read
```

Requires `chat.view`.

Payloads match the employee endpoints.

### Message Read Receipts

```http
GET /admin/chat/conversations/{conversation_id}/messages/{message_id}/receipts
```

Requires `chat.view` and active membership of the conversation.

```json
{
  "message_id": 456,
  "seen_count": 2,
  "total_count": 5,
  "seen": [
    {
      "user_id": 11,
      "name": "Yamada Taro",
      "staff_no": "E001",
      "profile_picture": "path/to/avatar.jpg",
      "role": "admin",
      "seen_at": "2026-06-08T09:11:00+00:00"
    }
  ],
  "unseen": [
    { "user_id": 12, "name": "Suzuki Hana", "staff_no": "E002", "profile_picture": null, "role": "admin" }
  ]
}
```

Derived from each participant's `last_read_message_id`: a member has seen the message once their
marker reaches it. Both lists exclude the message sender, the caller, and anyone who joined after
the message was sent. `seen_at` is that member's latest mark-read time, so for an older message it
is an upper bound on when they actually saw it, not the exact moment.

Drawing the avatars under a message does not need this endpoint — the conversation detail already
carries every participant's marker. Call it when the user opens the "who has seen this" list.

### The Internal Admin Group

One conversation carries `system_key: "admin_internal"`. It is an ordinary `admin_group` chat, but
the backend owns it: its roster is the set of accounts holding `manager.auth` through a role, kept
in sync as roles change and as accounts are disabled or re-enabled. The API refuses to delete it,
close it, or change its roster, and only a global admin may rename it, so clients should hide those
actions instead of offering buttons that can only fail.

The conversation list pins it to the top of the first page, ahead of recency, so it cannot fall off
a paged list on a quiet day. Clients should render the order they are served rather than re-sorting.

### Update Employee Permissions

```http
PATCH /admin/chat/conversations/{conversation_id}/employee-permissions
```

Requires `chat.edit`.

Body:

```json
{
  "employee_can_send_message": true,
  "employee_can_send_image": true,
  "employee_can_send_file": true
}
```

Rules:

- Only active admin participants can update these flags.
- Support conversations reject permission updates.
- Image/file flags affect the provisional attachment send path.

### Admin Close/Reopen

```http
POST /admin/chat/conversations/{conversation_id}/close
POST /admin/chat/conversations/{conversation_id}/reopen
```

Requires `chat.edit`.

Close body:

```json
{
  "close_reason": "Optional reason",
  "can_employee_reopen": false
}
```

For support chats, an admin can set `can_employee_reopen` when closing.

### Support Triage List

```http
GET /admin/chat/support-conversations?status=unassigned&page_size=50
```

Requires `chat.assign`.

Query:

```text
status: optional, one of unassigned, assigned, all. Default unassigned.
page_size: optional integer, 1 to 100
```

Response item:

```json
{
  "id": 123,
  "type": "support",
  "status": "open",
  "title": "Optional title",
  "created_by_user_id": 10,
  "created_by_name": "Yamada Taro",
  "participant_count": 1,
  "last_message_at": "2026-06-08T09:00:00+00:00",
  "last_message": {
    "id": 456,
    "message_type": "text",
    "body": "Help please",
    "sender_user_id": 10,
    "sender_name": "Yamada Taro",
    "created_at": "2026-06-08T09:00:00+00:00"
  }
}
```

Support visibility rules:

- `chat.assign` admins can view unassigned support chats and messages.
- They cannot send until assigned as an active admin participant.
- Assignment admins can assign themselves or other eligible admins.

### Assign Support Admins

```http
POST /admin/chat/support-conversations/{conversation_id}/assign-admins
```

Requires `chat.assign`.

Body:

```json
{
  "admin_user_ids": [20, 21]
}
```

Only users with `chat.assign` can be assigned. Assignment is idempotent for already-active admins.

Response:

```json
{
  "data": {
    "conversation_id": 123,
    "assigned_user_ids": [20, 21]
  }
}
```

### Remove Support Admins

```http
POST /admin/chat/support-conversations/{conversation_id}/remove-admins
```

Requires `chat.assign`.

Body:

```json
{
  "admin_user_ids": [20]
}
```

Response:

```json
{
  "data": {
    "conversation_id": 123,
    "removed_user_ids": [20]
  }
}
```

Removed admins lose conversation/message access.

### Admin Active Conversation Heartbeat

```http
POST /admin/chat/active
DELETE /admin/chat/active
```

Same semantics as the employee heartbeat, but requires `chat.view`.

## Realtime Integration

The backend broadcasts with Laravel broadcasting using the `pusher` driver and Soketi. Mobile should use a Pusher-compatible client, for example `pusher_channels_flutter`.

Deployment values must be provided per environment:

```text
Pusher/Soketi app key
WebSocket host
WebSocket port
TLS on/off
Auth endpoint: <API_BASE>/broadcasting/auth
```

Development defaults in the repo use:

```text
PUSHER_APP_KEY=dgf-connect
PUSHER_APP_ID=1
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

### Broadcast Auth

The auth endpoint is:

```http
POST /broadcasting/auth
```

Headers:

```http
Authorization: Bearer <access_token>
Accept: application/json
```

Body sent by Pusher clients:

```json
{
  "socket_id": "1234.5678",
  "channel_name": "private-chat.conversation.123"
}
```

Successful response contains an `auth` field. A 403 means the current user cannot subscribe to that private channel.

### Channels

Conversation channel:

```text
private-chat.conversation.{conversation_id}
```

User channel:

```text
private-user-{user_id}
```

Laravel Echo subscribes using names without the `private-` prefix, but many mobile Pusher clients require the full channel name. For Flutter, expect to subscribe to `private-chat.conversation.$id` and `private-user-$userId`.

Conversation channel authorization:

- Active participants can subscribe.
- `chat.assign` admins can subscribe to support conversations, including unassigned support chats.
- Removed participants and unrelated users are rejected.

User channel authorization:

- Only the same authenticated user can subscribe to `private-user-{user_id}`.

### Events

New message:

```text
event: chat.message.new
channel: private-chat.conversation.{conversation_id}
payload: ChatMessage
```

Use this event to append a new message if it is not already in local state. De-duplicate by `id`, and optionally by `client_message_id` for optimistic sends.

Conversation created/assigned to user:

```text
event: chat.conversation.created
channel: private-user-{user_id}
payload:
{
  "conversation": {
    "id": 123,
    "type": "support",
    "status": "open",
    "title": "Optional title",
    "created_by_user_id": 10,
    "participants": []
  }
}
```

Use this event to refresh the conversation list or insert the new conversation.

Conversation state changed:

```text
event: chat.conversation.state
channel: private-chat.conversation.{conversation_id}
payload:
{
  "id": 123,
  "status": "closed",
  "closed_at": "2026-06-08T09:10:00+00:00",
  "closed_by_user_id": 10,
  "close_reason": "Optional reason",
  "can_employee_reopen": true
}
```

Use this event to disable the composer when closed and refresh conversation detail.

Read marker moved (read receipts):

```text
event: chat.conversation.read
channel: private-chat.conversation.{conversation_id}.read
payload:
{
  "conversation_id": 123,
  "user_id": 10,
  "last_read_message_id": 456,
  "last_read_at": "2026-06-08T09:11:00+00:00"
}
```

Emitted only when a participant's marker actually moves forward. Patch that participant's
`last_read_message_id` in the roster you already hold and redraw the read receipts; nothing needs
to be refetched.

This is a channel of its own, not the conversation channel: read markers are admin-only, the same
way the employee conversation detail omits them. Subscribing needs `chat.view` on top of access to
the conversation, so employee clients must not subscribe to it.

Event-name note: Laravel Echo listens with a leading dot, for example `.chat.message.new`. Pusher mobile libraries usually deliver the event name without the dot, for example `chat.message.new`.

## Mobile Sync Strategy

Recommended startup/resume flow:

1. Authenticate and store the access token.
2. Fetch `/user/chat/conversations?page_size=50`.
3. Subscribe to `private-user-{user_id}`.
4. Subscribe to `private-chat.conversation.{id}` for visible conversations or for the currently opened conversation.
5. When opening a conversation, fetch detail and latest messages.
6. Start active heartbeat for that conversation.
7. Mark read after messages become visible.

Recommended reconnect flow:

1. Reconnect Pusher/Soketi.
2. Refresh conversation list.
3. For the currently open conversation, call:

```http
GET /user/chat/conversations/{conversation_id}/messages?after_id={last_known_message_id}
```

4. Append returned messages in ascending order and de-duplicate by `id`.

There is no global chat sync endpoint in the current implementation. Use per-conversation `after_id` for missed messages.

Recommended backscroll flow:

```http
GET /user/chat/conversations/{conversation_id}/messages?before_id={oldest_loaded_message_id}&limit=50
```

Prepend returned messages. Stop when `has_more` is false.

## Push Notifications

Chat FCM is push-only. It does not create notification-center rows.

Push is sent to active conversation participants except the sender. For new support chats, push is sent to admins with `chat.assign`. Push is suppressed if the recipient has an active heartbeat for the same conversation.

The FCM data payload contains string values:

```json
{
  "type": "chat_message",
  "conversation_id": "123",
  "message_id": "456"
}
```

Possible chat push types:

```text
chat_message
chat_support_created
chat_admin_assigned
chat_state_changed
```

On push tap:

1. Parse `conversation_id`.
2. Open the chat screen.
3. Fetch conversation detail.
4. Fetch messages, preferably with the local cursor if one exists.
5. Mark read after the message is visible.

Local development note: `SendChatMessageFcmJob` skips actual Firebase sends when `APP_ENV=local`. Backend tests assert dispatch/suppression logic, but mobile will not receive local FCM from this job unless the environment is non-local and Firebase is configured.

## Attachment Status

Attachments are implemented in the current backend work tree and have dedicated feature tests, but mobile should treat them as feature-flagged until backend confirms the tests pass in the project test environment and the storage settings are deployed.

Observed backend contract:

```http
POST /user/chat/conversations/{conversation_id}/messages
POST /admin/chat/conversations/{conversation_id}/messages
GET  /chat/attachments/{attachment_id}
```

Multipart send fields:

```text
body: optional when files is present
client_message_id: optional UUID string, max 64
files[]: optional files
```

Default limits from `config/chat.php`:

```text
max_files: 10
max_size_kb: 8192
mimes: jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx
image_mimes: image/jpeg,image/png,image/webp,image/heic
```

Attachment response item:

```json
{
  "id": 1,
  "attachment_id": 99,
  "type": "image",
  "url": "https://api.example.com/chat/attachments/99",
  "filename": "photo.jpg",
  "mime_type": "image/jpeg",
  "size_bytes": 120034,
  "position": 0
}
```

Download:

```http
GET /chat/attachments/{attachment_id}
```

The download route is behind the same `auth:api`, disabled-user, and conversation-view access checks. Mobile must include the bearer token and should treat the response as binary.

Attachment permission behavior:

- In non-support chats, employee text/image/file sends are gated by `employee_can_send_message`, `employee_can_send_image`, and `employee_can_send_file`.
- Admin participants are not gated by employee permission flags.
- Support chats do not use these employee permission flags.

Current caveat: attachment feature tests exist for upload, message type derivation, attachment-only messages, permission denial, idempotent multipart retry, participant download, removed-user denial, non-participant denial, and support-view-admin download. Do not ship attachment UI until backend confirms those tests pass in CI or the shared backend environment.

## Backend Deployment Requirements For Realtime/Push

These are not mobile tasks, but mobile testing depends on them:

- `BROADCAST_CONNECTION=pusher`.
- `pusher/pusher-php-server` installed. It is present in `composer.json`.
- Soketi reachable from mobile clients through the configured host/port/TLS.
- `/broadcasting/auth` reachable over the API base URL with bearer auth.
- Queue worker running for `ShouldBroadcast` events and FCM jobs.
- Redis/cache store available for active-conversation heartbeat. Default chat presence store is `redis`.
- Firebase credentials configured in non-local environments for FCM sends.
- Scheduler running for support and shift-chat auto-close jobs.

## Integration Checklist For Mobile

- Use bearer token auth for REST and broadcast auth.
- Use `/user/chat/...` paths for employee app chat.
- Generate a UUID `client_message_id` for every normal text message.
- Do not auto-retry support chat creation without checking whether the chat was already created.
- Use message `id` as the primary cursor and de-duplication key.
- Use `before_id` for older history and `after_id` for missed messages.
- Mark read when the conversation is visible.
- Start the active heartbeat while the chat screen is visible and stop it on leave/background.
- Subscribe to `private-user-{user_id}` for new conversations.
- Subscribe to `private-chat.conversation.{conversation_id}` for message and state events.
- Treat `system` messages as generic keys for now, or hide them until `system_payload` is exposed.
- Handle `429 Too Many Requests` on message send (30/min) and support-create (5/min) by backing off for the `Retry-After` duration.
- Keep attachment upload/download feature-flagged until backend confirms attachment tests pass.
