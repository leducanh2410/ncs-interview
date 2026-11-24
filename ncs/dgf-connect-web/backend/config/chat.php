<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Shift Group Auto Close
    |--------------------------------------------------------------------------
    |
    | Number of days after a shift's end datetime before its shift-group chat is
    | automatically closed. The shift end is derived from the shift date combined
    | with the related workplace shift's end_time (overnight shifts roll over to
    | the next day).
    |
    */

    'shift_group' => [
        'auto_close_days' => (int) env('CHAT_SHIFT_GROUP_AUTO_CLOSE_DAYS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Support Chat Auto Close
    |--------------------------------------------------------------------------
    |
    | Number of inactive hours before a support chat is automatically closed.
    | Inactivity is measured from last_activity_at, which only real user/admin
    | messages reset — system events (assignment, permission changes, close /
    | reopen notices) do not.
    |
    */

    'support' => [
        'auto_close_inactive_hours' => (int) env('CHAT_SUPPORT_AUTO_CLOSE_INACTIVE_HOURS', 24),
        'retention_days' => (int) env('CHAT_SUPPORT_RETENTION_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Push Notifications
    |--------------------------------------------------------------------------
    |
    | active_conversation_ttl is how long (seconds) a "user is actively viewing
    | conversation X" heartbeat stays in Redis. While the heartbeat matches the
    | conversation a message belongs to, push is suppressed for that recipient.
    | This is best-effort suppression only, never guaranteed-delivery logic.
    |
    */

    'notifications' => [
        'active_conversation_ttl' => (int) env('CHAT_ACTIVE_CONVERSATION_TTL', 45),
        'presence_store' => env('CHAT_PRESENCE_STORE', 'redis'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Storage disk and upload limits for chat image/file messages. Files are
    | stored privately and only served through the access-checked download route.
    |
    */

    'attachments' => [
        'disk' => env('CHAT_ATTACHMENT_DISK', env('FILESYSTEM_DISK', 'local')),
        'max_files' => (int) env('CHAT_ATTACHMENT_MAX_FILES', 10),
        'max_size_kb' => (int) env('CHAT_ATTACHMENT_MAX_SIZE_KB', 8192),
        'mimes' => env('CHAT_ATTACHMENT_MIMES', 'jpg,jpeg,png,webp,heic,pdf,doc,docx,xls,xlsx'),
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Thumbnails
    |--------------------------------------------------------------------------
    |
    | Thumbnails are small JPEG previews generated on upload and stored on the
    | same private disk as the original attachment. They are served through the
    | access-checked /chat/attachments/{id}/thumbnail route so membership rules
    | apply identically to both the original and the preview.
    | max_width is the longest edge in pixels passed to scaleDown().
    |
    */

    'thumbnails' => [
        'max_width' => (int) env('CHAT_THUMBNAIL_MAX_WIDTH', 200),
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversation-Created Broadcast
    |--------------------------------------------------------------------------
    |
    | When a conversation is created (or members are added) every recipient is
    | notified on their private user-{id} channel so the chat surfaces in their
    | list. To avoid an O(N) flood of broadcast jobs for large groups, the
    | recipients are batched: one event carries up to channel_chunk_size private
    | channels in a single Soketi/Pusher trigger. Keep this <= 100, the Pusher
    | protocol ceiling on channels per trigger.
    |
    */

    'broadcast' => [
        'channel_chunk_size' => (int) env('CHAT_BROADCAST_CHANNEL_CHUNK_SIZE', 100),
    ],
];
