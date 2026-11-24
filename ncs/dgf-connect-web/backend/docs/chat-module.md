# Chat Module Design

## Overview

The chat module provides self-hosted realtime group chat for employees and admins.

Primary goals:

- Admin-created group chats for manual users, shift members, or user groups
- Employee-created support chats, similar to support tickets
- Realtime delivery through Laravel broadcasting + Soketi
- Mobile push notifications through FCM
- Message history, unread state, reconnect sync, and audit logging

The backend database is the source of truth. WebSocket events are used only for realtime delivery.

---

## Realtime Infrastructure

Use the existing Soketi service as the Pusher-compatible WebSocket server.

### Already present in this repo

Most of the realtime stack is already wired and should be reused, not rebuilt:

- Soketi is defined as a service in both `docker-compose.yml` and `docker-compose.prod.yml` (with `SOKETI_DEFAULT_APP_*` env).
- The `pusher` broadcast connection already exists in `config/broadcasting.php`, and `.env.example` points `PUSHER_HOST=soketi`.
- FCM is already integrated via `kreait/laravel-firebase`, with a working queued pattern (`app/Jobs/PushNotificationToUser.php`, `SendPollAvailableFcmJob.php`) and `UserDevice.fcm_token`. Milestone 6 must reuse this, not reinvent it.
- Redis is configured for queue + cache (use it for the presence/active-conversation TTL).
- `routes/channels.php` already has a `user-{id}` private channel as a template.

### Gaps that block broadcasting today

- `pusher/pusher-php-server` is **not** installed. Without it the `pusher` driver cannot publish to Soketi. This is the real Milestone 1 blocker.
- `config/broadcasting.php` defaults `BROADCAST_CONNECTION` to `null`, so broadcasting is effectively off until `BROADCAST_CONNECTION=pusher` is set. (The previously malformed `.env.example` line has been fixed.)

Recommended production topology:

```text
Flutter app
  -> REST API: https://api.example.com
  -> WebSocket: wss://ws.example.com
  -> Nginx
  -> Soketi

Laravel API
  -> saves message in DB
  -> broadcasts event to Soketi
  -> Flutter clients receive realtime event
```

Laravel should use the `pusher` broadcast driver and the server-side package `pusher/pusher-php-server` to publish events to Soketi.

Flutter should use a Pusher-compatible package such as `pusher_channels_flutter`.

---

## Chat Types

| Type | Description | Created by | Participant source | Auto close |
|------|-------------|------------|--------------------|------------|
| `admin_group` | Normal admin-created group chat | Admin | Manually selected users | No default auto close |
| `shift_group` | Group chat for users in a specific `Shift` | Admin | Approved `ShiftUser` members, synced both ways | Configurable days after shift end, default 3 |
| `user_group` | Group chat for a specific user group | Admin | Snapshot of user group members | No default auto close |
| `support` | Employee support chat | Employee | Employee + assigned admins | After configurable inactivity period, default 1 day |

### The Internal Admin Group

One `admin_group` conversation is owned by the application rather than by whoever created it. It is
marked by `chat_conversations.system_key = 'admin_internal'` (unique, so the group is a singleton)
and is created by its migration; `EnsureAdminInternalConversationAction` recreates it if it is ever
missing, so a fresh database or a restored dump heals itself.

Membership is not edited, it is derived: every account holding `manager.auth` through a role and not
disabled — the same question `ResolveWebAdminAccessAction` answers at login. `SyncAdminInternalParticipantsAction`
reconciles the whole roster on every trigger rather than applying deltas, so a missed event converges
on the next run. Triggers are the Laratrust role/permission events (a role attached to a user, the
permission moved between roles), a `User` observer for `is_disabled`, and a nightly job that catches
pivot rows written straight through the query builder by a seeder or a data fix.

Losing admin access ends membership but never the history: the participant row flips to `removed`
and every message the account wrote stays. Regaining access reactivates the same row, so the read
marker it already had is preserved.

Because the roster is derived, the conversation is immutable to humans: delete, close, add/remove
participant, deputy assignment, and the employee permission switches are all refused at the action
level (`ErrorCode::ChatSystemConversationImmutable`). Renaming is the one exception and is limited to
global admins — every member is an admin, so "admin only" would not narrow anything.

`chat:promote-admin-internal {conversation}` turns an existing hand-made admin group into this one,
keeping its messages, for environments that already had such a chat before the feature existed.

### Read Receipts

Who has seen a message is derived from `chat_participants.last_read_message_id` — no per-message
read table. The admin conversation-detail payload exposes each participant's marker so clients can
draw the avatar of every reader under the last message they read, and
`GET /admin/chat/conversations/{id}/messages/{messageId}/receipts` returns the explicit seen/unseen
split behind that row. `MarkConversationReadAction` broadcasts `chat.conversation.read` when a
marker moves forward, which is what keeps the receipts live.

The trade-off is the timestamp: `last_read_at` is the time of the participant's latest mark-read
call, so for an older message it is an upper bound on when they saw it. Exact per-message times
would need a `chat_message_reads` table; that was deliberately not built.

---

## Roles and Permissions

Visibility and message access cannot be expressed by `chat_participants.status` alone — support-chat pre-assignment visibility is a role check, not a participant check. Define an explicit authorization matrix (chat type × actor role × action) and back it with the existing `Role` / `Permission` system, including a new dedicated "chat assignment" permission for support-chat admins. Policy/visibility tests are written within the milestones that introduce these rules (Milestones 2–4), not deferred to the end.

### Admin Group Chats

Admins with the proper role can create group chats with:

- One or multiple manually selected users
- A specific shift
- A specific user group

Admins have full permission inside the chat:

- Send text messages
- Send images
- Attach files
- Add/remove participants where applicable
- Update employee chat permissions
- Close/reopen the chat

### Employee Permissions in Admin-Created Chats

By default, employees can:

- Send text messages
- Send images
- Attach files

Admins can disable employee permissions at the chat level. This applies to all employees in that chat, not individual employees.

Recommended permission fields on the conversation:

```text
employee_can_send_message
employee_can_send_image
employee_can_send_file
```

### Support Chats

Employees can start support chats by sending an initial message.

Support chat visibility:

- All admins with the chat assignment role can see unassigned support chats.
- Only admins with the chat assignment role can view the message content before assignment.
- Assignment admins can assign themselves and/or other admins.
- After assignment, the support chat behaves like a normal group chat.
- Assignment admins can add/remove other admins from the support chat.
- The support chat remains visible to all chat-assignment admins.

Employees may have multiple open support chats.

---

## Conversation Lifecycle

### Open

An open conversation allows messages according to permissions.

### Closed

A closed conversation is read-only.

Rules:

- Users can still read old messages.
- Admins cannot send messages while closed unless the chat is reopened.
- Employees can reopen their own support chat unless the admin closed it with reopening disabled.
- Admins can reopen a closed chat unless business rules prevent it.

Recommended fields:

```text
status: open | closed
closed_at
closed_by_user_id
close_reason
can_employee_reopen
```

### Admin Close With Reopen Disabled

Admins can close a support chat and prevent employee reopening.

Example behavior:

```text
can_employee_reopen = false
```

Employees can still read the messages but cannot reopen or send new messages.

---

## Auto Close Rules

### Shift Group Chat

Shift-based group chats automatically close a configurable number of days **after the shift's end datetime** (not after chat creation or last activity).

Recommended env:

```env
CHAT_SHIFT_GROUP_AUTO_CLOSE_DAYS=3
```

There is no single end-datetime column: a `Shift` has a `date`, and the time window (`start_time` / `end_time`) lives on the related `WorkplaceShift`. The scheduled job should derive the shift end as `shift.date` combined with `shift.workplaceShift.end_time`, then close any still-open shift chat past `shiftEnd + CHAT_SHIFT_GROUP_AUTO_CLOSE_DAYS`. Account for overnight shifts where `end_time < start_time` (end falls on the next day).

### Support Chat

Support chats automatically close after a configurable inactivity period.

Recommended env:

```env
CHAT_SUPPORT_AUTO_CLOSE_INACTIVE_HOURS=24
```

Only real user/admin messages should reset the inactivity timer. System events such as assignment, participant changes, or permission updates should not reset it.

---

## Participant Rules

### Participant List

Employees can see the participant list.

### Newly Added Users

Newly added users can see previous messages.

### Removed Users

Removed users cannot see old messages after removal.

Implementation note: message list APIs must check current participant status. If a user was removed, they should not be able to fetch the conversation or its messages.

### Shift Group Participant Sync

A shift chat binds to the **`Shift`** model; membership is driven by its `ShiftUser` rows.

Membership rule: a `ShiftUser` is a chat participant only once it is **approved** (`approved_at` set / approved status). `is_absent` is ignored — an approved-but-absent member stays in the chat.

Shift group participants must sync both ways with shift membership:

- When a `ShiftUser` becomes approved, add the user to the shift chat.
- When a `ShiftUser` loses approval or its row is deleted, remove the user from the shift chat.
- Do **not** add/remove based on `is_absent` or on pending (not-yet-approved) status.

Implementation: funnel all shift-membership changes through a single chokepoint (model events on `ShiftUser`, or one Action all paths call) rather than hooking each admin/self-signup/approval path separately. Write an audit log entry for each automatic add/remove.

This is different from user group chats (which snapshot at creation).

### User Group Participant Snapshot

User group chats use a snapshot of members at creation time.

Later changes to the original user group do not automatically update existing chat participants.

---

## Message Types

Initial supported message content:

- Plain text
- Emoji as normal text
- Images
- File attachments

Not included in the initial design:

- Mentions
- Message editing
- Message deletion
- Rich text formatting

---

## Read / Unread State

Read/unread state is required.

Recommended approach:

- Store per-user read state per conversation.
- Track `last_read_message_id` and/or `last_read_at`.
- Calculate unread count from messages newer than the user's read marker.

Recommended fields on participant records:

```text
last_read_message_id
last_read_at
unread_count // recommended denormalized value
```

`unread_count` is recommended, not optional: computing unread counts per conversation across the whole conversation list will N+1 / table-scan at scale. Maintain the denormalized counter and reconcile on read. Add an index on `chat_participants(user_id, status)` for the conversation-list and access-check queries.

The mobile app should mark a conversation as read when the user opens it or when visible messages are consumed.

---

## Reconnect and Missed Message Sync

Mobile WebSocket connections are not reliable enough to be the only delivery mechanism.

Recommended flow:

```text
1. Flutter connects to Soketi.
2. Flutter subscribes to private conversation channels.
3. Realtime events update the active screen.
4. On reconnect/app resume, Flutter calls REST API to fetch missed messages.
5. Backend returns messages newer than the last known message id/timestamp.
```

Recommended sync endpoint behavior:

```text
GET /api/chat/conversations/{conversation_id}/messages?after_id={message_id}
```

or:

```text
GET /api/chat/sync?since={timestamp}
```

The API response should be authoritative even if some WebSocket events were missed.

Recommendations:

- Prefer the per-conversation `?after_id=` form as the primary sync mechanism. The global `/api/chat/sync?since=` form is heavier and must be scoped strictly to the user's active participations; treat it as secondary.
- History backscroll needs its own cursor (e.g. `?before_id=`), distinct from the `after_id` used for forward sync.

---

## Push Notifications

Use FCM for mobile push notifications.

Trigger examples:

- New chat message
- Support chat created
- Admin assigned to support chat
- Chat closed/reopened
- Participant added/removed

### Suppressing Push for Active Users

It is possible to avoid sending FCM notifications when a user is currently connected to the same conversation.

Recommended rule:

- If the user is currently connected and actively viewing the conversation, do not send push.
- If the user is connected but viewing another conversation, send push.
- If presence status is uncertain, send push.

Implementation options:

1. Track active conversation state from Flutter via API/heartbeat.
2. Use WebSocket presence channels to know connected users.
3. Store short-lived active state in Redis, for example:

```text
chat:active_conversation:{user_id} = {conversation_id}
TTL = 30-60 seconds
```

Flutter updates this state when entering/leaving a chat screen and periodically while active.

This optimization should not be treated as guaranteed delivery logic. It is only for notification suppression.

---

## Private Channels

Each conversation should use a private channel.

Example channel:

```text
private-chat.conversation.{conversation_id}
```

Authorization rule:

- User must be an active participant, or
- User must be an admin with permission to access the support chat according to support-chat rules.

Laravel channel authorization should use API token authentication for Flutter.

### Auth guard caveat (important)

This app does **not** use Sanctum or Passport. It uses a custom `access-token` guard (`config/auth.php` → `api` guard, registered via `Auth::extend('access-token', ...)` in `AppServiceProvider`, backed by the `AccessToken` model). Standard Pusher/Flutter `/broadcasting/auth` examples assume Sanctum, so this is the most likely thing to silently fail.

Milestone 1 must therefore:

- Register the broadcasting auth endpoint behind the existing `auth:api` (custom `access-token`) guard.
- Confirm how the Flutter Pusher client passes the access token to the `POST /broadcasting/auth` request (header vs. body) and that the custom guard reads it from that location.

Make this an explicit, separately verified deliverable.

---

## High-Level Data Model

### `chat_conversations`

Suggested fields:

```text
id
type
status
title
created_by_user_id
shift_id nullable          // FK to Shift (shift_group type)
user_group_id nullable
employee_can_send_message
employee_can_send_image
employee_can_send_file
can_employee_reopen
last_message_id nullable
last_message_at nullable
closed_at nullable
closed_by_user_id nullable
close_reason nullable
auto_close_at nullable
last_activity_at nullable
created_at
updated_at
```

### `chat_participants`

Suggested fields:

```text
id
conversation_id
user_id
role: admin | employee
status: active | removed
joined_at
removed_at nullable
removed_by_user_id nullable
last_read_message_id nullable
last_read_at nullable
created_at
updated_at
```

### `chat_messages`

Suggested fields:

```text
id
conversation_id
sender_user_id nullable        // null for system messages
client_message_id nullable     // client-generated UUID for idempotency
message_type: text | image | file | system
body nullable                  // for system messages, store a translation key, not literal text
system_payload json nullable   // structured data for rendering localized system messages
created_at
updated_at
```

Notes:

- **Idempotency:** mobile + unreliable WebSocket means the send API will be retried. Add `client_message_id` (UUID, unique per `conversation_id`) so a resent POST returns the existing message instead of creating a duplicate. This is required for the reconnect/sync flow, not optional.
- **System message localization:** this is a Japanese-facing app with an existing i18n setup (`Notification` + `NotificationTranslation`). `message_type = system` rows must not store hardcoded text. Store a translation key + `system_payload`, and render per-locale at read time, mirroring the existing notification translation approach. The same applies to all FCM push titles/bodies.
- **Attachments are modeled separately** — see `chat_message_attachments` below. A single `attachment_id` column was dropped because the spec allows multiple images/files per message.
- **Indexes:** add `chat_messages(conversation_id, id)` for history/sync queries.

### `chat_message_attachments`

Allows multiple attachments per message. Model against the existing `DocumentAttachment` pattern.

```text
id
message_id
attachment_id      // FK to the stored file/attachment record
position           // ordering within the message
created_at
updated_at
```

### `chat_support_assignments`

Could be separate or represented by `chat_participants` with admin role.

If separate:

```text
id
conversation_id
admin_user_id
assigned_by_user_id
assigned_at
removed_at nullable
```

### `chat_audit_logs`

Suggested fields:

```text
id
conversation_id
actor_user_id nullable
action
payload json nullable
created_at
```

Audit examples:

- Conversation created
- Participant added
- Participant removed
- Employee permissions updated
- Support admin assigned
- Support admin removed
- Conversation closed
- Conversation reopened
- Auto closed

---

## Suggested API Areas

Exact endpoints can be finalized during implementation, but the module should expose APIs for:

### Conversation List

```text
GET /api/chat/conversations
```

Returns conversations visible to the authenticated user, including unread counts and last message preview.

### Conversation Detail

```text
GET /api/chat/conversations/{conversation_id}
```

Returns metadata, participants, permissions, and status.

### Messages

```text
GET  /api/chat/conversations/{conversation_id}/messages
POST /api/chat/conversations/{conversation_id}/messages
```

### Mark Read

```text
POST /api/chat/conversations/{conversation_id}/read
```

### Admin Create Conversation

```text
POST /api/admin/chat/conversations
```

Supports manual, shift, and user group chat creation.

### Admin Update Permissions

```text
PATCH /api/admin/chat/conversations/{conversation_id}/employee-permissions
```

### Close / Reopen

```text
POST /api/chat/conversations/{conversation_id}/close
POST /api/chat/conversations/{conversation_id}/reopen
```

Authorization depends on chat type and actor role.

### Support Chat

```text
POST /api/chat/support-conversations
POST /api/admin/chat/support-conversations/{conversation_id}/assign-admins
POST /api/admin/chat/support-conversations/{conversation_id}/remove-admins
```

---

## Implementation Notes

- Use Laravel Action classes for chat business logic.
- Use database transactions when creating conversations, participants, messages, audit logs, and broadcast jobs.
- Broadcast events only after DB commit.
- Use queues for notifications and non-critical side effects.
- Use scheduled commands for auto-close jobs.
- Keep REST API as the source of truth; WebSocket events are for realtime updates only.

---

## Implementation Roadmap

The work is organized as six milestones rather than many fine-grained phases. Each milestone is a vertically sliced, shippable unit; the sub-bullets marked **(PR)** are the suggested pull-request breakdown within a milestone. Tests are written within the milestone that introduces the behavior — there is no separate "testing phase." Policy/visibility and removed-user access-denial tests are mandatory in the milestones that introduce those rules (M3, M4).

### Milestone 1: Infrastructure and Broadcasting

This is a risk spike — prove the realtime path end to end before building features on it.

- Fix broadcasting configuration for Soketi (set `BROADCAST_CONNECTION=pusher`; the default is `null`).
- Install `pusher/pusher-php-server` (not currently in `composer.json`).
- Register broadcasting auth routes against the custom `access-token` (`auth:api`) guard — see the "Auth guard caveat" section; **this is the highest-risk item, verify it first**.
- Add private channel authorization for chat conversations.
- Put Soketi behind Nginx for public `wss://` access.
- Verify Flutter can connect, authenticate private channels, and receive test events.

Deliverable: an authenticated realtime channel works between Laravel, Soketi, and Flutter.

### Milestone 2: Core Data Model and Messaging

Folds the old "data model" and "basic messaging" phases together — the schema isn't proven until something reads and writes it, so they ship as one vertical slice. Basic read/unread and the forward-sync param live here too, because the conversation-list API returns unread counts and the message-list API is the sync endpoint.

- **(PR)** Migrations, models, and enums/constants for `chat_conversations`, `chat_participants`, `chat_messages`, `chat_message_attachments`, `chat_audit_logs`, and optional `chat_support_assignments`. Include indexes (`chat_messages(conversation_id, id)`, `chat_participants(user_id, status)`) and basic policy/authorization helpers.
- **(PR)** Conversation list (with unread counts + last-message preview) and conversation detail APIs.
- **(PR)** Message list API with pagination (history `before_id` cursor + forward `after_id` sync param) and send-message API with `client_message_id` idempotency.
- **(PR)** Private broadcast event for new messages, dispatched after DB commit. Update conversation `last_message_id`, `last_message_at`, and `last_activity_at`.
- **(PR)** Mark-as-read API storing `last_read_message_id` / `last_read_at`; maintain the denormalized `unread_count`.

Deliverable: users can list conversations, open one, send messages with reliable de-duplication, receive them realtime, see unread counts, and recover missed messages on reconnect.

### Milestone 3: Admin-Created Chats and Shift Sync

- **(PR)** Admin manual group chat creation.
- **(PR)** Shift group chat creation bound to the `Shift` model, and user group chat creation with a participant snapshot.
- **(PR)** Shift participant sync — funnel all `ShiftUser` changes through a single chokepoint (model events or one shared Action); add a user when their `ShiftUser` becomes approved, remove on loss of approval or row deletion; ignore `is_absent` and pending status; audit every automatic change. **This PR carries the heaviest test burden** because it touches existing shift flows.
- **(PR)** Employee-level chat permissions per conversation (`employee_can_send_message`, `employee_can_send_image`, `employee_can_send_file`) and the admin permission-update API.

Note: the image/file permission flags are inert until attachment upload lands (M6). They can be merged earlier as no-ops, or pull attachment upload forward if image/file chat is needed sooner.

Deliverable: admins can create and manage normal group chats, and shift chats stay synchronized with shift membership.

### Milestone 4: Support Workflow and Close/Reopen

Manual close/reopen lands here (not later) because admin-close-with-reopen-disabled is a support-chat feature and the support workflow depends on it.

- **(PR)** Manual close/reopen APIs, including admin close with `can_employee_reopen = false`; audit logs and broadcast events for close/reopen.
- **(PR)** Employee support chat creation with an initial message.
- **(PR)** Unassigned support chat listing for chat-assignment admins, assignment APIs, and add/remove other admins.
- **(PR)** Enforce support chat visibility and message-access rules (pre-assignment role gating).

Deliverable: support chats behave like ticket-style group chats with admin assignment and full close/reopen semantics.

### Milestone 5: Lifecycle Automation

- Scheduled job for shift chat auto-close (configurable days after the shift's end, derived from `shift.date` + `shift.workplaceShift.end_time`; handle overnight shifts).
- Scheduled job for inactive support chat auto-close (computed from `last_activity_at`; system events do not reset the timer).
- Audit logs and broadcast events for auto-close.

Deliverable: conversation lifecycle rules are enforced automatically.

### Milestone 6: Notifications, Attachments, and Hardening

FCM and attachments are grouped as enrichment; hardening closes the milestone.

- **(PR)** FCM notifications — reuse the existing stack (`kreait/laravel-firebase`, `UserDevice.fcm_token`, and the queued job pattern in `app/Jobs/PushNotificationToUser.php`); do not introduce a second Firebase integration. Localize push titles/bodies. Add the Redis active-conversation heartbeat and suppress push when the recipient is actively viewing the same conversation, best-effort only.
- **(PR)** Attachments — image/file upload APIs wired to `chat_message_attachments`, file type/size enforcement, and private file-access checks against current conversation membership.
- **(PR)** Hardening — rate limiting for sending messages and support chat creation; review Nginx/Soketi timeout and scaling settings; close any coverage gaps left by earlier milestones.

Deliverable: push notifications and secure attachments work, and the module is production-ready for MVP release.

---

## MVP Scope

Recommended MVP:

- Admin-created group chat
- Shift group chat with two-way participant sync
- User group chat with participant snapshot
- Employee-created support chat
- Support assignment flow
- Text/image/file messages
- Close/reopen behavior
- Auto-close for shift and inactive support chats
- Read/unread state
- FCM push notifications
- Reconnect/missed-message sync
- Audit logging

Out of scope for MVP:

- Mentions
- Message editing/deletion
- Rich text
- Per-employee custom permissions
