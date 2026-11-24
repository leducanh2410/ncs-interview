# Requirements Document

## Introduction

Feature này bổ sung khả năng tạo và quản lý hội thoại hỗ trợ dành cho nhân viên chưa có tài khoản trong hệ thống (guest). Hiện tại, tính năng support chat yêu cầu nhân viên phải đăng nhập; feature mới cho phép họ gửi yêu cầu ngay từ màn hình đăng nhập, xác thực bằng Cloudflare Turnstile để chống bot và bằng Guest Session Token cho các tương tác tiếp theo. Backend cần cung cấp một bộ API độc lập, realtime qua Pusher private channel, và push notification qua FCM — tất cả mà không ảnh hưởng đến luồng support hiện tại dành cho nhân viên đã có tài khoản.

### Feature Summary
Cung cấp bộ API backend cho phép guest (nhân viên chưa có tài khoản) tạo và duy trì hội thoại support với admin, được xác thực bằng Guest Session Token.

### Business Value
Loại bỏ rào cản tài khoản trong việc tiếp cận hỗ trợ, giúp nhân viên mới hoặc có vấn đề đăng nhập vẫn có thể liên hệ bộ phận hỗ trợ ngay lập tức.

### Scope

**Bao gồm:**
- API tạo guest support conversation (có Cloudflare Turnstile)
- Guest Session Token: phát hành, lưu trữ (hash), và xác thực
- API gửi/đọc tin nhắn và xem conversation cho guest
- API kiểm tra device đã có session chưa
- Pusher auth endpoint cho guest
- FCM push notification đến guest device (không tính vào badge count)
- FCM push notification từ guest đến admin
- Admin API trả về thêm field phân biệt guest conversation
- API reopen conversation cho guest

**Không bao gồm:**
- UI frontend web admin (spec riêng)
- UI mobile admin app (spec riêng)
- UI employee app (spec riêng)
- Xác thực qua email OTP
- Gửi file/attachment cho guest (chỉ text trong phiên bản đầu)
- Tự động đóng conversation (giữ nguyên logic auto-close hiện tại)

## Glossary

| Term | Definition |
|------|------------|
| Guest | Nhân viên chưa có tài khoản trong hệ thống, chỉ được định danh qua device_id |
| Guest_Support_Session | Bản ghi lưu thông tin guest và session_token trong bảng `guest_support_sessions` |
| Guest_Session_Token | Token 64 ký tự ngẫu nhiên (plaintext) trả về sau khi tạo session; phía backend lưu AES encrypted và SHA-256 HMAC hash |
| Session_Token_Hash | `hash_hmac('sha256', $plaintext, $appKey)` — lưu cùng session, có unique index, dùng để tra cứu O(1) trong middleware |
| Guest_Support_Conversation | ChatConversation có type = `guest_support`, không có `created_by_user_id` |
| Guest_Auth_Middleware | Middleware `auth:guest_support` xác thực Guest_Session_Token từ Authorization header bằng hash lookup |
| Turnstile_Validator | Service gọi Cloudflare Turnstile API để verify token |
| CHAT_ASSIGN_Admin | Admin có permission `CHAT_ASSIGN`, nhận thông báo khi có guest support mới |
| Notification_Service | `NotifyChatRecipientsAction` và các job FCM, xử lý push notification |
| Pusher_Guest_Auth | Endpoint `/guest/pusher/auth` cấp phép cho guest subscribe Pusher private channel |
| device_id | Định danh thiết bị duy nhất, do mobile app tạo ra và gửi kèm khi tạo guest session |
| fcm_token | Firebase Cloud Messaging token của thiết bị, dùng để gửi push notification (max 500 chars) |

## Requirements

### Requirement 1: Tạo Guest Support Conversation

**User Story:** As a guest (nhân viên chưa có tài khoản), I want to submit a support request with my name, email, and message, so that I can receive help without needing a system account.

#### Acceptance Criteria

1. WHEN `POST /guest/chat/support-conversations` được gọi với `name`, `email`, `content`, `device_id`, `platform`, `fcm_token`, `turnstile_token` hợp lệ THEN Guest_Support_API SHALL xác thực Turnstile_Validator trước khi xử lý bất kỳ logic nào khác
2. IF `turnstile_token` không hợp lệ THEN Guest_Support_API SHALL trả về HTTP 400 với error code `CAPTCHA_FAILED`
3. IF `device_id` đã có một Guest_Support_Session tồn tại trong hệ thống THEN Guest_Support_API SHALL trả về HTTP 409 với `conversation_id` của session hiện tại
4. WHEN Turnstile validation vượt qua THEN Guest_Support_API SHALL tạo một Guest_Support_Session, một Guest_Support_Conversation (type = `guest_support`), và một ChatMessage chứa nội dung `content`
5. WHEN Guest_Support_Session được tạo THEN Guest_Support_API SHALL tạo Guest_Session_Token (64 ký tự an toàn ngẫu nhiên), lưu hash vào DB, và trả về plaintext token chỉ một lần duy nhất trong response
6. WHEN Guest_Support_Conversation được tạo THEN Guest_Support_API SHALL broadcast event `ChatConversationCreated` đến tất cả CHAT_ASSIGN_Admin
7. WHEN Guest_Support_Conversation được tạo THEN Notification_Service SHALL gửi FCM push notification đến tất cả CHAT_ASSIGN_Admin
8. WHERE `platform` vượt quá 50 ký tự THEN Guest_Support_API SHALL trả về HTTP 422

#### Additional Details
- **Priority**: High
- **Complexity**: High
- **Dependencies**: Cloudflare Turnstile secret key phải được cấu hình trong `.env`; FCM cho employee app phải hoạt động

---

### Requirement 2: Xác Thực Guest Session Token

**User Story:** As a guest with an existing session, I want my subsequent API requests to be authenticated, so that only I can access and interact with my conversation.

#### Acceptance Criteria

1. WHEN request gửi `Authorization: Bearer <token>` đến bất kỳ `/guest/chat/*` endpoint nào THEN Guest_Auth_Middleware SHALL compute `hash_hmac('sha256', $bearer, $appKey)` và query `WHERE session_token_hash = $hash` — O(log n) với unique index, không AES-decrypt toàn bảng
2. IF không tìm thấy session khớp THEN Guest_Auth_Middleware SHALL trả về HTTP 401 Unauthorized
3. IF `conversation_id` trong route không khớp với `conversation_id` của session THEN Guest_Auth_Middleware SHALL trả về HTTP 403 Forbidden
4. WHEN xác thực thành công THEN Guest_Auth_Middleware SHALL đưa GuestSupportSession vào request context để controller sử dụng
5. WHILE session tồn tại trong DB THEN Guest_Session_Token SHALL không có thời gian hết hạn (conversation là ranh giới vòng đời)

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

---

### Requirement 3: Gửi Tin Nhắn Tiếp Theo (Guest)

**User Story:** As an authenticated guest, I want to send follow-up messages in my conversation, so that I can continue communicating with the assigned admin.

#### Acceptance Criteria

1. WHEN `POST /guest/chat/conversations/{id}/messages` được gọi với Guest_Session_Token hợp lệ và `body` không rỗng THEN Chat_API SHALL tạo ChatMessage với `sender_user_id = null` và `guest_support_session_id` trỏ đến session hiện tại
2. IF conversation đang ở trạng thái `closed` THEN Chat_API SHALL trả về HTTP 422 với error `conversation_closed`
3. WHEN tin nhắn được lưu THEN Chat_API SHALL broadcast event `NewChatMessage` lên Pusher private channel `private-chat.conversation.{id}`
4. WHEN tin nhắn được lưu THEN Notification_Service SHALL gửi FCM push notification đến tất cả admin đang tham gia (assigned) trong conversation
5. IF không có admin nào được assign THEN Notification_Service SHALL gửi FCM push đến tất cả CHAT_ASSIGN_Admin (giống luồng tạo ban đầu)

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2

---

### Requirement 4: Đọc Conversation và Tin Nhắn (Guest)

**User Story:** As an authenticated guest, I want to read my conversation details and message history, so that I can review the full conversation.

#### Acceptance Criteria

1. WHEN `GET /guest/chat/conversations/{id}` được gọi với Guest_Session_Token hợp lệ THEN Chat_API SHALL trả về `status`, `title`, `participant_count` (số admin tham gia), `last_message_at`, và thông tin guest (`guest_name`, `guest_email`)
2. WHEN `GET /guest/chat/conversations/{id}/messages` được gọi THEN Chat_API SHALL trả về danh sách tin nhắn phân trang theo cursor (cùng cấu trúc response với endpoint authenticated)
3. WHERE tin nhắn có `guest_support_session_id` không null THEN response SHALL hiển thị `sender_name` bằng `guest_name` từ Guest_Support_Session (không để null)
4. IF tin nhắn là system message (`sender_user_id = null` và `guest_support_session_id = null`) THEN response SHALL xử lý như system message bình thường

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 2

---

### Requirement 5: Kiểm Tra Trạng Thái Session Theo Device

**User Story:** As the employee app, I want to check whether a device already has an active guest session, so that the UI can show the correct button on the login screen.

#### Acceptance Criteria

1. WHEN `POST /guest/chat/conversations/active` được gọi với `device_id` và `turnstile_token` hợp lệ THEN Guest_Support_API SHALL verify Turnstile trước khi tra cứu session
2. IF `turnstile_token` không hợp lệ THEN Guest_Support_API SHALL trả về HTTP 400 với error code `CAPTCHA_FAILED` (không tra cứu DB)
3. IF tồn tại session VÀ conversation đang `open` THEN response SHALL trả về `has_active_session: true`, `conversation_id`, `conversation_status: "open"`, và `guest_session_token` (giải mã từ encrypted value)
4. IF tồn tại session NHƯNG conversation đã `closed` THEN response SHALL trả về `has_active_session: false`, `conversation_id`, `conversation_status: "closed"`, và `guest_session_token` (để app có thể reopen)
5. IF không tồn tại session THEN response SHALL trả về `has_active_session: false` với các field còn lại là null
6. WHILE endpoint không yêu cầu Guest_Session_Token THEN Guest_Support_API SHALL giới hạn rate theo IP (tối đa 30 request/phút)

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: TurnstileService phải được inject; Cloudflare Turnstile site key phải được configure trên mobile app

---

### Requirement 6: Pusher Authentication cho Guest

**User Story:** As an authenticated guest, I want to receive real-time messages via WebSocket, so that I see new admin messages instantly without polling.

#### Acceptance Criteria

1. WHEN `POST /guest/pusher/auth` được gọi với Guest_Session_Token hợp lệ và `channel_name = private-chat.conversation.{id}` THEN Pusher_Guest_Auth SHALL xác minh `{id}` khớp với `conversation_id` của session
2. IF xác minh thành công THEN Pusher_Guest_Auth SHALL trả về Pusher auth response hợp lệ để subscribe channel
3. IF Guest_Session_Token không hợp lệ hoặc channel không khớp THEN Pusher_Guest_Auth SHALL trả về HTTP 403 Forbidden
4. WHERE channel name không theo format `private-chat.conversation.*` THEN Pusher_Guest_Auth SHALL trả về HTTP 403

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 2; Pusher credentials phải được cấu hình

---

### Requirement 7: Push Notification FCM cho Guest

**User Story:** As a guest, I want to receive FCM push notifications when an admin sends a message, so that I'm notified of responses even when the app is in the background.

#### Acceptance Criteria

1. WHEN admin gửi tin nhắn trong một Guest_Support_Conversation THEN Notification_Service SHALL gửi FCM push đến `fcm_token` lưu trong Guest_Support_Session của conversation đó
2. WHEN gửi FCM đến guest device THEN Notification_Service SHALL dùng Firebase project của employee app (không phải admin app)
3. WHEN gửi FCM đến guest device THEN Notification_Service SHALL đặt `badge_count = 0` trong payload (không ảnh hưởng badge unread trong app)
4. IF `fcm_token` của guest là null hoặc gặp lỗi FCM THEN Notification_Service SHALL bỏ qua push notification mà không fail request của admin
5. WHILE guest đang active trong conversation (presence heartbeat) THEN Notification_Service SHALL bỏ qua FCM push cho guest (cùng logic suppress hiện tại)

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1; Firebase employee project phải được cấu hình

---

### Requirement 8: Mở Lại Conversation (Reopen)

**User Story:** As a guest or admin, I want to reopen a closed guest support conversation, so that communication can resume after a conversation has been closed.

#### Acceptance Criteria

1. WHEN `POST /guest/chat/conversations/{id}/reopen` được gọi với Guest_Session_Token hợp lệ THEN Chat_API SHALL mở lại conversation nếu `status = closed`
2. IF conversation đang ở trạng thái `open` THEN Chat_API SHALL trả về HTTP 422
3. WHEN guest reopen thành công THEN Chat_API SHALL: (a) reset `closed_at`, `closed_by_user_id`, `close_reason` về null, (b) ghi `ChatAuditLog` với `actor_role: 'guest'` và `actor_user_id: null`, (c) tạo system message `ConversationReopened`, (d) dispatch `ChatConversationStateChanged` và `NewChatMessage` broadcast, (e) gửi FCM push notification đến assigned admins (hoặc tất cả CHAT_ASSIGN admins nếu chưa có ai được assign)
4. WHEN admin gọi `POST /admin/chat/conversations/{id}/reopen` trên một Guest_Support_Conversation THEN Chat_API SHALL xử lý giống với authenticated support conversation (không thay đổi logic hiện tại)
5. WHEN guest reopen thành công THEN endpoint SHALL trả về `{ "data": { "id": ..., "status": "open" } }`

#### Additional Details
- **Priority**: Medium
- **Complexity**: Medium (cần GuestReopenConversationAction riêng vì không có ChatParticipant phía guest)
- **Dependencies**: Requirement 2; `GuestReopenConversationAction`, `RecordSystemMessageAction`, `NotifyChatRecipientsAction`

---

### Requirement 9: Admin API nhận diện Guest Conversation

**User Story:** As an admin, I want the existing admin support API to include guest conversations with identifying information, so that I can triage both types of support in one place.

#### Acceptance Criteria

1. WHEN `GET /admin/chat/support-conversations` được gọi THEN Admin_API SHALL trả về cả `support` và `guest_support` conversations trong cùng danh sách phân trang
2. WHEN response item là một Guest_Support_Conversation THEN Admin_API SHALL bao gồm thêm fields: `is_guest: true`, `guest_name`, `guest_email`
3. WHEN response item là authenticated support THEN Admin_API SHALL trả về `is_guest: false` (hoặc field không tồn tại)
4. WHEN `GET /admin/chat/conversations/{id}` được gọi với ID của Guest_Support_Conversation THEN Admin_API SHALL trả về thêm `guest_info: { name, email, device_id, platform }` trong response
5. WHERE conversation type là `guest_support` THEN Admin_API SHALL NOT trả về user profile link hay user_id trong response (không có tài khoản tồn tại)

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1

---

### Requirement 10: Bảo Mật và Rate Limiting

**User Story:** As a system operator, I want the guest support endpoint to be protected against abuse, so that it cannot be spammed by bots or malicious actors.

#### Acceptance Criteria

1. WHEN `POST /guest/chat/support-conversations` được gọi quá 5 lần/phút từ cùng IP THEN Guest_Support_API SHALL trả về HTTP 429 Too Many Requests
2. IF `turnstile_token` bị thiếu trong request THEN Guest_Support_API SHALL trả về HTTP 422
3. IF `device_id` đã có một Guest_Support_Session với conversation đang ở trạng thái `open` THEN Guest_Support_API SHALL trả về HTTP 409 và SHALL NOT tạo thêm session; IF conversation đã `closed` THEN Guest_Support_API SHALL cho phép tạo session mới
4. WHEN Guest_Session_Token được tạo THEN Guest_Support_API SHALL lưu song song: (a) AES-256 encrypted (`session_token`) để endpoint `/active` có thể trả lại token cho app, (b) SHA-256 HMAC hash (`session_token_hash`, unique index) để middleware tra cứu O(log n) mà không cần AES-decrypt toàn bảng
5. WHERE guest API endpoints (trừ `/guest/chat/conversations/active` và `/guest/chat/support-conversations`) THEN tất cả SHALL yêu cầu Guest_Auth_Middleware
6. WHEN `POST /guest/chat/conversations/{id}/messages` được gọi quá 60 lần/phút từ cùng IP THEN Guest_Support_API SHALL trả về HTTP 429
7. WHEN `POST /guest/chat/conversations/{id}/reopen` được gọi quá 10 lần/phút từ cùng IP THEN Guest_Support_API SHALL trả về HTTP 429
8. WHERE `fcm_token` được lưu THEN Guest_Support_API SHALL chấp nhận chuỗi tối đa 500 ký tự (FCM registration token không đảm bảo ≤255 chars)

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Không có

## Non-Functional Requirements

### Performance Requirements
- WHEN `POST /guest/chat/support-conversations` được xử lý THEN Guest_Support_API SHALL hoàn thành trong vòng 3 giây (bao gồm cả Turnstile verification)
- WHEN Turnstile_Validator gọi Cloudflare API THEN thời gian timeout SHALL không quá 10 giây
- WHEN Guest_Auth_Middleware xác thực token THEN thời gian xử lý SHALL không quá 50ms (1 DB lookup với index)

### Security Requirements
- WHEN Guest_Session_Token được lưu vào DB THEN hệ thống SHALL lưu song song: AES-256 encrypted (để trả lại cho app) và SHA-256 HMAC hash với app key (để middleware tra cứu O(log n) — giống pattern Sanctum)
- WHEN Guest_Auth_Middleware xác thực token THEN SHALL dùng hash lookup (không decrypt toàn bảng) để tránh O(n) crypto trên public endpoint
- WHEN Endpoint `/active` được gọi THEN SHALL yêu cầu Turnstile verification trước khi tra cứu DB và trả về token (không public hoàn toàn)
- WHEN Turnstile verification thất bại THEN hệ thống SHALL log attempt kèm IP nhưng SHALL NOT log `turnstile_token` plaintext
- WHEN guest truy cập conversation của người khác THEN Guest_Auth_Middleware SHALL trả về 403 (không phải 404 để tránh enumeration)
- IF `device_id` chứa ký tự đặc biệt hoặc vượt quá 255 ký tự THEN Guest_Support_API SHALL trả về 422

### Usability Requirements
- WHEN Guest_Support_API trả về lỗi validation THEN response SHALL dùng cùng format với validation errors của Laravel hiện tại (field-keyed errors)

### Reliability Requirements
- WHEN FCM push notification thất bại THEN Guest_Support_API SHALL không fail toàn bộ request (fire-and-forget, log lỗi)
- WHEN Pusher broadcast thất bại THEN Guest_Support_API SHALL không fail message creation (graceful degrade)
- WHEN Cloudflare Turnstile API không phản hồi (ConnectionException) THEN Guest_Support_API SHALL trả về `false` (captcha fail → 400 CAPTCHA_FAILED)

## Constraints and Assumptions

### Technical Constraints
- `chat_participants.user_id` là NOT NULL FK — guest sẽ không có ChatParticipant; guest context được quản lý qua `guest_support_sessions` table
- `chat_messages.sender_user_id` đã có thể là null (dùng cho system messages) — guest messages cũng null nhưng phân biệt qua `guest_support_session_id`
- Chỉ employee Firebase project được dùng để gửi FCM đến guest device
- Cloudflare Turnstile secret key phải được thêm vào `.env` (`CAPTCHA_TURNSTILE_SECRET_KEY`); site key chỉ cần phía mobile client

### Assumptions
- Employee app sẽ lưu `guest_session_token` trong Flutter Secure Storage ngay sau khi nhận từ API
- Nếu app bị gỡ cài đặt và cài lại, `device_id` vẫn giữ nguyên (Flutter plugin `device_info_plus` giữ nguyên trên Android; iOS reset sau reinstall nhưng đây là edge case chấp nhận được)
- Guest không cần upload file attachment (chỉ text); sẽ xem xét mở rộng trong tương lai
- Auto-close logic hiện tại áp dụng cho `guest_support` mà không cần thay đổi

## Success Criteria

### Definition of Done
- [ ] Tất cả acceptance criteria được đáp ứng
- [ ] 10 requirements đều có test coverage (unit + integration)
- [ ] API endpoints được document (không cần Swagger, comment trong route file là đủ)
- [ ] Turnstile secret key được cấu hình trong `.env.example` (`CAPTCHA_TURNSTILE_SECRET_KEY`)
- [ ] Không có regression trên các API support authenticated hiện tại
- [ ] PHPUnit tests pass toàn bộ
- [ ] `npm run prettier` và `npm run pint` pass
