# Implementation Plan: Guest Support Backend

## Overview

Implementation theo chiến lược **Foundation-First**: xây dựng từ tầng dữ liệu (enum, migration, model) lên đến tầng xác thực (middleware, service), rồi đến logic nghiệp vụ (actions, jobs), sau đó HTTP layer (controllers, routes), cuối cùng là mở rộng admin API và testing. Thứ tự này đảm bảo mỗi task đều có đủ dependency trước khi bắt đầu, và mỗi checkpoint có thể verify bằng migration + unit test trước khi lên tầng cao hơn.

### Implementation Strategy
- **Foundation-First**: Enum → Migration → Model → Middleware/Service → Action → Job → Controller → Route
- Không sửa logic hiện tại của authenticated support cho đến Task 11 (admin API extension) — giảm risk regression
- Mỗi task tạo ra code có thể test độc lập trước khi task tiếp theo phụ thuộc vào nó

## Implementation Plan

### Phase 1: Foundation — Enum, Migration, Models

- [x] 1. Thêm `ChatConversationType::GuestSupport` enum value
    - [x] 1.1 Mở file `backend/app/Enums/ChatConversationType.php`, thêm case `GuestSupport = 'guest_support'`
        - Không thay đổi các case hiện tại (`Support`, `AdminGroup`, v.v.)
        - _Requirements: 1, 9_

- [x] 2. Tạo migration cho `guest_support_sessions` và các cột mới
    - [x] 2.1 Tạo migration `create_guest_support_sessions_table`
        - Columns: `id`, `device_id` (string 255, index), `platform` (string 10), `fcm_token` (string 255, nullable), `guest_name` (string 100), `guest_email` (string 255), `session_token` (text — AES encrypted), `conversation_id` (foreignId, constrained chat_conversations, cascadeOnDelete), `timestamps()`
        - _Requirements: 1, 2_

    - [x] 2.4 Tạo migration `add_session_token_hash_to_guest_support_sessions` (security fix)
        - Thêm `session_token_hash` (string 64, nullable, unique) — SHA-256 HMAC hash để middleware lookup O(log n)
        - Thêm `fcm_token` tăng từ string(255) lên string(500) via `->change()` — FCM token có thể dài hơn 255 chars
        - _Requirements: 2, 10_

    - [x] 2.2 Tạo migration `add_guest_support_session_id_to_chat_conversations`
        - Thêm `guest_support_session_id` (unsignedBigInteger, nullable, index) — không dùng `constrained()` để tránh circular FK với `guest_support_sessions`
        - _Requirements: 1, 9_

    - [x] 2.3 Tạo migration `add_guest_support_session_id_to_chat_messages`
        - Thêm `guest_support_session_id` (foreignId, nullable, constrained guest_support_sessions, nullOnDelete)
        - _Requirements: 3_

- [x] 3. Tạo `GuestSupportSession` model và cập nhật các model liên quan
    - [x] 3.1 Tạo `backend/app/Models/GuestSupportSession.php`
        - `$fillable`: device_id, platform, fcm_token, guest_name, guest_email, session_token, session_token_hash, conversation_id
        - Relationship: `belongsTo(ChatConversation::class)`
        - _Requirements: 1, 2, 5_

    - [x] 3.2 Cập nhật `backend/app/Models/ChatConversation.php`
        - Thêm relationship: `hasOne(GuestSupportSession::class)`
        - _Requirements: 1, 9_

    - [x] 3.3 Cập nhật `backend/app/Models/ChatMessage.php`
        - Thêm relationship: `belongsTo(GuestSupportSession::class)`
        - _Requirements: 3, 4_

- [x] 4. Checkpoint — Verify migration và models
    - Chạy `docker compose exec php php artisan migrate` — không có lỗi
    - Chạy `docker compose exec php php artisan migrate:rollback` rồi migrate lại — rollback hoạt động đúng
    - Ensure all tests pass, ask the user if questions arise.

---

### Phase 2: Auth Layer — Middleware và Turnstile Service

- [x] 5. Tạo `TurnstileService`
    - [x] 5.1 Tạo `backend/app/Services/TurnstileService.php`
        - Method `verify(string $token, ?string $remoteIp = null): bool`
        - POST đến `https://challenges.cloudflare.com/turnstile/v0/siteverify` với `secret` từ `config('captcha.turnstile.secret_key')` và `response = $token`
        - Timeout 10 giây via `Http::timeout(10)`
        - Bắt `ConnectionException` → trả `false` (không throw)
        - Trả `false` nếu `success !== true`
        - _Requirements: 1, 10_

    - [x] 5.2 Thêm `CAPTCHA_TURNSTILE_SITE_KEY=` và `CAPTCHA_TURNSTILE_SECRET_KEY=` vào `.env.example`
        - _Requirements: 1_

- [x] 6. Tạo `AuthGuestSupport` middleware
    - [x] 6.1 Tạo `backend/app/Http/Middleware/AuthGuestSupport.php`
        - Parse `Authorization: Bearer <token>` header; trả 401 nếu thiếu
        - Compute `hash_hmac('sha256', $bearer, config('app.key'))` → query `WHERE session_token_hash = $hash` (O(log n), không AES-decrypt toàn bảng)
        - Nếu không tìm thấy session khớp → 401; nếu route có `{id}` và `session.conversation_id !== (int)$routeId` → 403
        - Set `$request->attributes->set('guestSession', $session)` để controller dùng
        - _Requirements: 2, 10_

    - [x] 6.2 Đăng ký middleware alias trong `bootstrap/app.php` (Laravel 11): `'auth.guest_support' => AuthGuestSupport::class`
        - _Requirements: 2_

---

### Phase 3: Core Business Logic — Actions

- [x] 7. Tạo `CreateGuestSupportConversationAction`
    - [x] 7.1 Tạo `backend/app/Actions/Chat/GuestSupport/CreateGuestSupportConversationAction.php`
        - Constructor inject: `RecordSystemMessageAction`, `NotifyChatRecipientsAction`
        - `__invoke(array $params): ActionResult` với params: name, email, content, device_id, platform, fcm_token, client_message_id?
        - **Check duplicate** (scope open only): `GuestSupportSession::where('device_id')->whereHas('conversation', status=open)->first()` → 409 nếu tồn tại; cho phép tạo mới nếu conversation đã closed
        - **Transaction**:
            - `$plaintext = Str::random(64)`; `$hash = hash_hmac('sha256', $plaintext, config('app.key'))`
            - `INSERT guest_support_sessions` với `session_token = Crypt::encryptString($plaintext)`, `session_token_hash = $hash`
            - `INSERT chat_conversations` với `type = GuestSupport`, `guest_support_session_id = session->id`, `created_by_user_id = null`
            - `($recordSystemMessage)($conversation, ConversationCreated, null)` — actor null cho guest
            - `INSERT chat_messages` với `sender_user_id = null`, `guest_support_session_id = session->id`, `body = $params['content']`
            - Update `last_message_id/at/last_activity_at` trên conversation
            - Return `[$session, $message, $conversation]` từ closure để tránh N+1 query sau transaction
        - **Post-transaction**: `NewChatMessage::dispatch($message)`, `notify->forGuestSupportCreated($conversation, $message)`, `ChatConversationCreated::dispatch(adminId, $payload)` cho từng CHAT_ASSIGN admin
        - Trả `ActionResult(data: ['conversation_id' => ..., 'guest_session_token' => $plaintext])`
        - _Requirements: 1, 7, 10_

    - [x] 7.2 Cập nhật `RecordSystemMessageAction` nếu cần — đảm bảo `actor_user_id = null` không crash
        - _Requirements: 1_

- [x] 8. Tạo `SendGuestMessageAction`
    - [x] 8.1 Tạo `backend/app/Actions/Chat/GuestSupport/SendGuestMessageAction.php`
        - Constructor inject: `NotifyChatRecipientsAction`
        - `__invoke(GuestSupportSession $session, array $params): ActionResult`
        - Kiểm tra `$session->conversation->status === open` → 422 nếu closed
        - `INSERT chat_messages` với `sender_user_id = null`, `guest_support_session_id = session->id`, body, client_message_id
        - Update `last_message_id/at/last_activity_at`
        - `NewChatMessage::dispatch($message)`, `notify->forGuestMessage($conversation, $message)`
        - **Override sender_name**: trên mọi return path (happy path, idempotency check, race condition fallback): `$payload['sender_name'] = $session->guest_name` trước khi return
        - _Requirements: 3_

- [x] 8b. Tạo `GuestReopenConversationAction`
    - [x] 8b.1 Tạo `backend/app/Actions/Chat/GuestSupport/GuestReopenConversationAction.php`
        - Constructor inject: `RecordSystemMessageAction`, `NotifyChatRecipientsAction`
        - `__invoke(GuestSupportSession $session, ChatConversation $conversation): ActionResult`
        - Trả 422 nếu conversation đã open
        - **Transaction**: reset `closed_at/closed_by_user_id/close_reason → null`; ghi `ChatAuditLog` với `actor_user_id = null`, `actor_role = 'guest'`; call `$recordSystemMessage($conversation, ConversationReopened, null)`
        - **Post-transaction**: dispatch `ChatConversationStateChanged`, `NewChatMessage`; call `notify->forGuestReopened($conversation, $systemMessage)`
        - _Requirements: 8_

---

### Phase 4: FCM & Notification Extension

- [x] 9. Tạo `SendGuestChatFcmJob`
    - [x] 9.1 Tạo `backend/app/Jobs/SendGuestChatFcmJob.php`
        - Constructor: `string $fcmToken`, `int $conversationId`, `string $title`, `string $body`, `array $data`
        - `handle(ResolveFirebaseMessagingAction $resolveMessaging)`:
            - `$messaging = $resolveMessaging->execute('app')` (employee project)
            - Build `CloudMessage` với notification title/body
            - Thêm `badge: 0` trong APNS config: `->withApnsConfig(['aps' => ['badge' => 0]])`
            - `try { $messaging->sendMulticast($message, [$this->fcmToken]); } catch (Throwable $e) { Log::error(...); }`
        - _Requirements: 7_

- [x] 10. Mở rộng `NotifyChatRecipientsAction`
    - [x] 10.1 Thêm method `forGuestSupportCreated(ChatConversation $conversation, ChatMessage $firstMessage): void`
        - Logic giống `forSupportCreated()` nhưng không exclude employee (guest không phải User)
        - Resolve CHAT_ASSIGN admin IDs, dispatch `SendChatMessageFcmJob`
        - _Requirements: 1, 7_

    - [x] 10.2 Thêm method `forGuestMessage(ChatConversation $conversation, ChatMessage $message): void`
        - Gửi FCM đến assigned admins qua `SendChatMessageFcmJob` (tái sử dụng)
        - Nếu không có assigned admin: gửi đến tất cả CHAT_ASSIGN_Admin
        - _Requirements: 3, 7_

    - [x] 10.3 Thêm method `forGuestReopened(ChatConversation $conversation, ChatMessage $systemMessage): void`
        - Gửi FCM đến assigned admins; fallback sang tất cả CHAT_ASSIGN admins nếu không có ai assign
        - Notification type: `CHAT_STATE_CHANGED`, body: `chat.notification.conversation_reopened`
        - _Requirements: 8_

    - [x] 10.4 Mở rộng `forMessage()` để handle khi admin gửi tin nhắn trong `guest_support` conversation
        - Nếu `$conversation->type === GuestSupport`: dispatch thêm `SendGuestChatFcmJob` với `fcm_token` từ `$conversation->guestSupportSession`
        - _Requirements: 7_

---

### Phase 5: HTTP Layer — Controllers và Routes

- [x] 11. Tạo Guest Chat Controllers
    - [x] 11.1 Tạo `backend/app/Http/Controllers/Guest/ChatSupportController.php`
        - `store(Request $request)`: validate input (fcm_token max:500) → call `TurnstileService` → call `CreateGuestSupportConversationAction` → return 201
        - `checkActive(Request $request, TurnstileService $turnstile)`: validate `device_id` + `turnstile_token` → verify Turnstile → query `GuestSupportSession` → return response với `has_active_session` (true chỉ khi conversation open), `conversation_id`, `conversation_status`, `guest_session_token` (decrypted)
        - `show(Request $request, int $id)`: lấy conversation từ `$request->attributes->get('guestSession')` → return detail
        - _Requirements: 1, 4, 5_

    - [x] 11.2 Tạo `backend/app/Actions/Chat/GuestSupport/GetGuestMessagesAction.php` và `backend/app/Http/Controllers/Guest/ChatMessageController.php`
        - `GetGuestMessagesAction::__invoke(GuestSupportSession $session, array $params)`: cursor-based pagination giống `GetMessagesAction`, nhưng không require `User`; với mỗi message có `guest_support_session_id != null`, set `sender_name = $session->guest_name` trong payload (ghi đè null từ `toPayloadArray()` vì `sender` relation là null)
        - `ChatMessageController::index`: lấy `$session` từ request attributes, call action trên
        - `ChatMessageController::store`: validate body → call `SendGuestMessageAction` → return 201
        - Lưu ý: không reuse `GetMessagesAction` vì nó yêu cầu `User $user` làm tham số đầu tiên
        - _Requirements: 3, 4_

    - [x] 11.3 Tạo `backend/app/Http/Controllers/Guest/ChatLifecycleController.php`
        - `reopen(Request $request, int $id, GuestReopenConversationAction $action)`: lấy conversation từ session → call `GuestReopenConversationAction` (không phải `ReopenConversationAction` vì guest không có ChatParticipant) → return response
        - _Requirements: 8_

    - [x] 11.4 Tạo `backend/app/Http/Controllers/Guest/PusherAuthController.php`
        - `__invoke(Request $request)`: validate `socket_id` và `channel_name` — phải match `private-chat.conversation.{id}`
        - Extract `{id}` từ channel name, verify khớp `$session->conversation_id`
        - `$auth = app(\Pusher\Pusher::class)->socket_auth($channelName, $socketId)`
        - Return `response()->json(json_decode($auth))`
        - _Requirements: 6_

- [x] 12. Đăng ký Guest Routes trong `routes/api.php`
    - [x] 12.1 Thêm route group (sau các public auth routes):
        ```php
        // Guest support — no auth:api
        Route::post('/guest/chat/support-conversations', [Guest\ChatSupportController::class, 'store'])
            ->middleware('throttle:5,1');
        Route::post('/guest/chat/conversations/active', [Guest\ChatSupportController::class, 'checkActive'])
            ->middleware('throttle:30,1');   // POST (không phải GET) — yêu cầu Turnstile token

        Route::middleware('auth.guest_support')->group(function () {
            Route::get('/guest/chat/conversations/{id}', [Guest\ChatSupportController::class, 'show']);
            Route::get('/guest/chat/conversations/{id}/messages', [Guest\ChatMessageController::class, 'index']);
            Route::post('/guest/chat/conversations/{id}/messages', [Guest\ChatMessageController::class, 'store'])
                ->middleware('throttle:60,1');
            Route::post('/guest/chat/conversations/{id}/reopen', [Guest\ChatLifecycleController::class, 'reopen'])
                ->middleware('throttle:10,1');
            Route::post('/guest/pusher/auth', Guest\PusherAuthController::class);
        });
        ```
        - _Requirements: 1, 3, 4, 5, 6, 8, 10_

- [x] 13. Checkpoint — Test toàn bộ guest API với HTTP client (Postman / curl)
    - Tạo guest session: verify response có `conversation_id` + `guest_session_token`
    - Check active: verify trả đúng token
    - Gửi tin nhắn: verify broadcast và FCM job được dispatch
    - Pusher auth: verify signature hợp lệ
    - Ensure all tests pass, ask the user if questions arise.

---

### Phase 6: Admin API Extension

- [x] 14. Mở rộng `GetSupportConversationsAction` để include guest conversations
    - [x] 14.1 Sửa `backend/app/Actions/Chat/Support/GetSupportConversationsAction.php`
        - Thay `->where('type', ChatConversationType::Support->value)` bằng `->whereIn('type', [ChatConversationType::Support->value, ChatConversationType::GuestSupport->value])`
        - Thêm eager load: `->with(['guestSupportSession'])`
        - Thêm fields vào response item:
            ```php
            'is_guest' => $conversation->type === ChatConversationType::GuestSupport->value,
            'guest_name' => $conversation->guestSupportSession?->guest_name,
            'guest_email' => $conversation->guestSupportSession?->guest_email,
            ```
        - _Requirements: 9_

- [x] 15. Mở rộng `GetConversationDetailAction` để trả về guest info
    - [x] 15.1 Sửa `backend/app/Actions/Chat/Conversation/GetConversationDetailAction.php`
        - Nếu `$conversation->type === GuestSupport`: thêm `guest_info` vào response:
            ```php
            'guest_info' => $conversation->guestSupportSession ? [
                'name' => $conversation->guestSupportSession->guest_name,
                'email' => $conversation->guestSupportSession->guest_email,
                'device_id' => $conversation->guestSupportSession->device_id,
                'platform' => $conversation->guestSupportSession->platform,
            ] : null,
            ```
        - _Requirements: 9_

- [x] 16. Cập nhật `NewChatMessage` broadcast payload cho guest messages
    - [x] 16.1 Sửa `ChatMessage::toPayloadArray()` (hoặc `broadcastWith()` trong `NewChatMessage`)
        - Nếu `sender_user_id = null` và `guest_support_session_id != null`: set `sender_name = guestSupportSession->guest_name`, `sender_profile_picture = null`
        - _Requirements: 4_

---

### Phase 7: Testing

- [x] 17. Unit Tests
    - [x] 17.1 Tạo `tests/Unit/Services/TurnstileServiceTest.php`
        - Test pass khi Cloudflare API trả `success: true`
        - Test fail khi `success: false`
        - Test trả `false` (không throw) khi `ConnectionException`
        - Test forward `remoteip` khi truyền vào
        - _Requirements: 1, 10_

    - [x] 17.2 Tạo `tests/Unit/Http/Middleware/AuthGuestSupportTest.php`
        - Pass qua khi token hợp lệ và conversation_id khớp
        - 401 khi không có Authorization header
        - 401 khi token không decrypt được (tampered)
        - 403 khi conversation_id trong route không khớp với session
        - _Requirements: 2_

    - [x] 17.3 Tạo `tests/Unit/Actions/Chat/GuestSupport/CreateGuestSupportConversationActionTest.php`
        - Tạo đúng 1 GuestSupportSession, 1 ChatConversation (type=guest_support), 2 ChatMessages
        - `session_token` trong DB là encrypted (khác với plaintext trả về)
        - 409 khi gọi lại với cùng `device_id`
        - `ChatConversationCreated` và `NewChatMessage` được dispatch
        - _Requirements: 1_

    - [x] 17.4 Tạo `tests/Unit/Jobs/SendGuestChatFcmJobTest.php`
        - Dùng employee Firebase project (`app`), không phải `admin`
        - Payload APNS có `badge: 0`
        - Log error khi FCM throw exception, không re-throw
        - _Requirements: 7_

    - [x] 17.5 Tạo `tests/Unit/Actions/Chat/GuestSupport/SendGuestMessageActionTest.php`
        - Tạo ChatMessage với đúng `guest_support_session_id`
        - 422 khi conversation `status = closed`
        - `NewChatMessage` được dispatch
        - _Requirements: 3_

- [x] 18. Integration (Feature) Tests
    - [x] 18.1 Tạo `tests/Feature/GuestSupport/GuestSupportApiTest.php`
        - Full happy-path: tạo session → check active (trả token) → gửi tin nhắn → lấy messages → reopen
        - 409 khi tạo duplicate session cùng device_id
        - 400 khi Turnstile fail (mock `TurnstileService::verify()` trả false)
        - 429 khi vượt rate limit 5 req/phút
        - Pusher auth: trả đúng signature cho channel hợp lệ; 403 cho channel sai
        - Admin endpoint `GET /admin/chat/support-conversations` trả `is_guest: true`, `guest_name`, `guest_email` cho guest conversations
        - Admin endpoint `GET /admin/chat/conversations/{id}` trả `guest_info` cho guest conversations
        - Gửi tin nhắn vào closed guest conversation trả 422
        - _Requirements: 1, 2, 3, 4, 5, 6, 8, 9, 10_

    - [x] 18.2 Tạo `tests/Feature/GuestSupport/GuestFcmNotificationTest.php`
        - Admin gửi tin nhắn → `SendGuestChatFcmJob` được dispatch với đúng `fcm_token`
        - Guest gửi tin nhắn → `SendChatMessageFcmJob` dispatch đến assigned admins
        - `forGuestSupportCreated` dispatch FCM đến tất cả CHAT_ASSIGN admins
        - _Requirements: 7_

- [x] 19. Checkpoint — Chạy toàn bộ PHPUnit suite
    - `docker compose exec php ./vendor/bin/phpunit`
    - Không có test nào fail (kể cả tests hiện tại của authenticated support)
    - Ensure all tests pass, ask the user if questions arise.

---

### Phase 8: Final Validation

- [x] 20. Code Quality
    - [x] 20.1 Chạy `npm run prettier` trong `backend/`
    - [x] 20.2 Chạy `npm run pint` trong `backend/`
    - [x] 20.3 Kiểm tra tất cả `use` imports đều được khai báo ở đầu file (không có inline FQCN)
    - [x] 20.4 Chạy lại `docker compose exec php ./vendor/bin/phpunit` lần cuối để xác nhận

## Notes

- **Thứ tự Phase 3 → Phase 4**: `CreateGuestSupportConversationAction` phải được tạo trước khi mở rộng `NotifyChatRecipientsAction` vì action notification cần biết signature của guest conversation context
- **Circular FK tránh được**: `chat_conversations.guest_support_session_id` dùng `unsignedBigInteger` không có `constrained()` — cùng pattern với `last_message_id` hiện tại trong codebase
- **Task 16 (broadcast payload)**: Đã pre-fix trước khi implement — `ChatMessage::toPayloadArray()` đã được thêm `sender_name` field, và `NewChatMessage::broadcastWith()` đã override `sender_name` từ `guestSupportSession` cho guest messages. Task 16 chỉ cần verify và viết test.
- **`RecordSystemMessageAction` với `actor = null`**: Đã kiểm tra — action đã an toàn, signature là `?User $actor = null` với null-safe operators. Task 7.2 không cần thực hiện.
- **`GetGuestMessagesAction`**: `GetMessagesAction` hiện tại không reuse được vì yêu cầu `User $user` — cần action riêng (đã ghi rõ ở Task 11.2)
- **Convention**: Tất cả test methods dùng camelCase theo quy ước project (`testCreateGuestSessionSuccess`, không phải `test_create_guest_session_success`)
