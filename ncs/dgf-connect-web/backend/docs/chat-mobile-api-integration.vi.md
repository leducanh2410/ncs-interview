# Hướng dẫn tích hợp API Chat cho Mobile

Tài liệu được rà soát theo trạng thái backend hiện tại vào ngày 2026-06-08.

## Kết luận sẵn sàng

Mobile có thể bắt đầu tích hợp API cho MVP chat ngay bây giờ.

Backend hiện tại đã sẵn sàng cho:

- Danh sách hội thoại, chi tiết hội thoại, danh sách tin nhắn, gửi tin nhắn văn bản, đánh dấu đã đọc, đóng và mở lại hội thoại cho nhân viên.
- Nhân viên tạo support chat với tin nhắn văn bản ban đầu.
- Admin tạo chat nhóm, chat theo ca, và chat theo nhóm người dùng để nhân viên đọc và phản hồi.
- Cập nhật realtime qua Laravel broadcasting với server Soketi tương thích Pusher.
- FCM push notification cho các sự kiện chat khi người nhận không đang xem chính hội thoại đó.
- Các workflow admin/support nếu mobile cũng cần client cho admin.
- Rate limit chat theo từng user: gửi tin nhắn bị giới hạn 30 request/phút và tạo support chat 5 request/phút, trả về HTTP 429 khi vượt ngưỡng.

Backend hiện tại vẫn cần backend xác nhận thêm cho:

- Release attachment trên mobile. Các code path upload/download attachment và feature test đã có, nhưng mobile nên giữ attachment sau feature flag cho đến khi backend xác nhận các test attachment pass trong môi trường test của project.
- Endpoint riêng để refresh FCM token sau khi login.
- Render system message đầy đủ theo locale/dynamic data từ API. API hiện trả translation key trong `body`, nhưng chưa expose `system_payload`.

## API Base

Tất cả path bên dưới là relative path so với API base URL mà app dùng.

Route Laravel hiện tại không tự động có prefix `/api`, nên path thật là:

```text
/user/chat/...
/admin/chat/...
/broadcasting/auth
```

Nếu deployment expose các path này sau API gateway prefix, mobile base URL cần bao gồm prefix đó. Không dùng các path trong planning doc cũ như `/api/chat/...` trừ khi deployment gateway rewrite sang các route hiện tại.

## Auth

Tất cả endpoint chat yêu cầu guard access-token hiện có: `auth:api`.

Gửi header:

```http
Authorization: Bearer <access_token>
Accept: application/json
Content-Type: application/json
```

Guard cũng chấp nhận cookie `access_token`, nhưng mobile nên dùng bearer token.

Employee login có thể đăng ký FCM token của thiết bị:

```http
POST /auth/login
```

Các field login bắt buộc gồm:

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

Gap hiện tại: nếu FCM token bị rotate sau khi login, chưa có endpoint riêng để update token. Mobile nên gửi token mới nhất khi login. Backend nên thêm một endpoint refresh nhỏ nếu cần độ tin cậy push mà không bắt user login lại.

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

Các lỗi chat thường gặp:

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
429 Too Many Requests (rate limit gửi chat và tạo support chat)
```

Response `429` theo cơ chế throttle của Laravel và có header `Retry-After` (giây). Mobile nên back off và retry sau khoảng delay đó thay vì gọi liên tục.

Tất cả timestamp là ISO 8601 string hoặc `null`.

## Enum

Conversation type:

```text
admin_group
shift_group
user_group
support
```

Conversation status:

```text
open
closed
```

Participant role:

```text
admin
employee
```

Message type:

```text
file
image
system
text
```

Text và system message là path ổn định cho MVP. Image/file message nên giữ sau feature flag cho đến khi backend xác nhận slice attachment hiện tại.

## Payload chính

Conversation list item:

```json
{
  "id": 123,
  "type": "support",
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

Conversation detail:

```json
{
  "id": 123,
  "type": "support",
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

Hạn chế hiện tại của system message: `body` là translation key, và `system_payload` có lưu trong database nhưng chưa được return bởi `toPayloadArray()`. Mobile nên map các key đã biết sang text local dạng generic, hoặc tạm ẩn system message cho đến khi backend expose `system_payload`.

Các system key đã biết:

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

## Endpoint cho nhân viên

### List Conversations

```http
GET /user/chat/conversations?page=1&page_size=50
```

Query:

```text
page_size: optional integer, 1 to 100, default 15
page: optional Laravel pagination page
```

Trả về danh sách conversation dạng paginated. Chỉ trả các conversation mà user là active participant. Participant đã bị remove không thấy lại conversation cũ.

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

Response là payload conversation vừa tạo, không phải payload tin nhắn đầu tiên.

Rate limit: endpoint này bị giới hạn 5 request/phút theo từng user. Vượt ngưỡng sẽ trả `429 Too Many Requests` kèm header `Retry-After`.

Caveat quan trọng khi retry: endpoint này hiện chưa idempotent nếu gọi lặp lại `POST /user/chat/support-conversations`. Nếu network drop sau khi gửi request, không tự động retry create request một cách mù quáng. Nên fetch `/user/chat/conversations` để kiểm tra support chat mới đã được tạo chưa, hoặc yêu cầu backend thêm conversation-level idempotency key trước khi bật auto retry cho tạo support chat.

### Conversation Detail

```http
GET /user/chat/conversations/{conversation_id}
```

Trả metadata conversation, permissions, read state của chính user, và active participants.

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

- Không gửi `before_id` và `after_id` cùng lúc. Backend trả `CHAT_INVALID_CURSOR`.
- Request mặc định trả page mới nhất, response được sort tăng dần theo id.
- `before_id` dùng cho backscroll.
- `after_id` dùng cho reconnect hoặc sync tin nhắn bị miss.

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
files: feature-flagged attachment field, optional array, max 10 by default
files.*: feature-flagged attachment field, file, configured mime list, max 8192 KB by default
```

Với path text-chat ổn định, gửi JSON gồm `body` và `client_message_id`. Tạo UUID phía client cho mỗi tin nhắn. Backend enforce uniqueness theo từng conversation và trả về message đã tồn tại nếu gửi lại cùng `client_message_id` trong cùng conversation. Vì vậy retry message thường là an toàn.

Với attachment đang feature-flagged, dùng multipart form data thay vì JSON. Không bật phần này trong production mobile app cho đến khi backend xác nhận test attachment và storage behavior.

Send có thể fail với:

```text
404 E_NOT_FOUND: user cannot access this conversation
403 E_FORBIDDEN: employee message permission disabled
422 CHAT_CONVERSATION_CLOSED: conversation is closed
422 E_UNPROCESSABLE_ENTITY: validation error
429 Too Many Requests: vượt rate limit gửi tin nhắn (30/phút theo từng user); tuân theo Retry-After
```

### Mark Read

```http
POST /user/chat/conversations/{conversation_id}/read
```

Body có explicit marker:

```json
{
  "message_id": 456
}
```

Body cũng có thể rỗng:

```json
{}
```

Behavior:

- Nếu không gửi `message_id`, backend đánh dấu đã đọc đến `last_message_id`.
- Nếu `message_id` thuộc conversation khác, backend trả `CHAT_MESSAGE_NOT_IN_CONVERSATION`.
- Read marker không bao giờ đi lùi.
- Response gồm `last_read_message_id`, `last_read_at`, và `unread_count`.

Khuyến nghị cho mobile: gọi endpoint này khi mở màn hình chat và khi tin nhắn mới nhất đang visible thay đổi.

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

Nhân viên có thể đóng support conversation của chính mình. Admin participant đóng qua admin endpoint.

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

Nhân viên chỉ có thể reopen support conversation của chính mình khi `can_employee_reopen` là true. Admin reopen từ admin endpoint.

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

Mục đích: suppress FCM push khi user đang chủ động xem chính conversation đó.

TTL hiện tại được config bởi `CHAT_ACTIVE_CONVERSATION_TTL`, default 45 giây. Mobile nên:

- POST một lần khi vào màn hình chat.
- Lặp lại khoảng mỗi 30 giây khi màn hình còn visible.
- DELETE khi rời màn hình chat.
- Dừng heartbeat khi app vào background.

Heartbeat này không đánh dấu tin nhắn là đã đọc. Dùng endpoint mark-read riêng.

## Endpoint cho admin

Mobile employee integration có thể bỏ qua phần này nếu không có app mobile cho admin/manager.

Admin endpoint cần cùng bearer token và permission middleware.

### Admin List Conversations

```http
GET /admin/chat/conversations?page=1&page_size=50
```

Yêu cầu `chat.view`.

### Admin Create Conversation

```http
POST /admin/chat/conversations
```

Yêu cầu `chat.create`.

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

- `participant_user_ids` chỉ áp dụng cho `admin_group`.
- `shift_id` chỉ áp dụng cho `shift_group`.
- `user_group_id` chỉ áp dụng cho `user_group`.
- Creator không được nằm trong `participant_user_ids`.
- Shift-group chat chỉ include shift users đã approved.
- User-group chat snapshot member hiện tại tại thời điểm tạo.

### Manager Direct Conversation Lookup

```http
GET /manage/chat/direct-conversation?user_id={employee_id}
```

Yêu cầu `chat.view`. Nằm dưới prefix `/manage` (app mobile cho manager) thay vì `/admin`, nhưng dùng chung bearer token và permission model.

Tra cứu hội thoại 1-1 đã tồn tại giữa người gọi và `user_id`. Không có type "direct" riêng — một cuộc chat riêng thực chất là một conversation `admin_group` chỉ có đúng 2 thành viên active là người gọi và `user_id` (cùng hình dạng mà "Admin Create Conversation" ở trên tạo ra khi chọn đúng 1 nhân viên). Hội thoại đã đóng vẫn được trả về — API chi tiết và UI đã xử lý trạng thái closed.

Tìm thấy (200):

```json
{
  "data": {
    "conversation_id": 123
  }
}
```

Nếu có nhiều hội thoại 1-1 khớp (tạo `admin_group` không chống trùng), thread hoạt động gần nhất được trả về (`last_activity_at`, rồi `id`).

Không tìm thấy (404) — **đây là kết quả bình thường, không phải lỗi**:

```json
{
  "error": "E_NOT_FOUND",
  "data": null
}
```

Mobile nên coi 404 này là "chưa có hội thoại riêng" và fallback sang `POST /admin/chat/conversations` (`type: "admin_group"`, `participant_user_ids: [user_id]`) để tạo mới — không hiển thị lỗi cho người dùng.

Nhánh tạo mới đó yêu cầu quyền `chat.create`, độc lập với `chat.view` dùng để gate lookup. Nếu tài khoản không có `chat.create`, client nên ẩn/disable nút bắt đầu chat thay vì gọi API tạo rồi nhận 403.

`user_id` không hợp lệ (422):

```json
{
  "error": "E_UNPROCESSABLE_ENTITY",
  "data": {
    "user_id": ["The selected user id is invalid."]
  }
}
```

Tra cứu chính `id` của người gọi, thiếu id, hoặc user không tồn tại đều trả cùng mã `error`; chỉ chuỗi message trong `user_id` khác nhau. **Không** fallback sang tạo hội thoại trong các trường hợp này: `participant_user_ids` không được chứa creator nên nhánh đó cũng sẽ 422. Chỉ 404 ở trên mới là tín hiệu được phép fallback tạo mới.

Client nên tự kiểm tra `user_id === me` trước khi gọi API. Bấm vào chính mình là tình huống UI bình thường — bỏ qua request, không toast lỗi, cũng không fallback tạo mới. Thiếu `user_id` hoặc user không tồn tại mới là lỗi thật.

### Admin Conversation Detail, Messages, Send, Read

```http
GET  /admin/chat/conversations/{conversation_id}
GET  /admin/chat/conversations/{conversation_id}/messages
POST /admin/chat/conversations/{conversation_id}/messages
POST /admin/chat/conversations/{conversation_id}/read
```

Yêu cầu `chat.view`.

Payload giống endpoint employee.

### Read Receipt Của Tin Nhắn

```http
GET /admin/chat/conversations/{conversation_id}/messages/{message_id}/receipts
```

Yêu cầu `chat.view` và phải là thành viên đang active của conversation.

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

Tính từ `last_read_message_id` của từng participant: ai có marker chạm tới tin nhắn thì coi là đã
xem. Cả hai danh sách đều bỏ người gửi, người đang gọi API, và người vào nhóm sau khi tin nhắn được
gửi. `seen_at` là thời điểm mark-read gần nhất của người đó, nên với tin cũ nó là cận trên chứ không
phải đúng lúc họ xem.

Việc vẽ avatar dưới tin nhắn không cần gọi endpoint này — conversation detail đã trả marker của mọi
participant. Chỉ gọi khi người dùng mở danh sách "ai đã xem".

### Nhóm Nội Bộ Admin

Có đúng một conversation mang `system_key: "admin_internal"`. Về bản chất nó là `admin_group` bình
thường, nhưng do backend sở hữu: thành viên là các tài khoản có `manager.auth` qua role, được đồng bộ
mỗi khi role đổi hoặc tài khoản bị vô hiệu hoá/bật lại. API từ chối xoá, đóng, hay sửa thành viên
nhóm này, và chỉ global admin mới được đổi tên, nên client nên ẩn các thao tác đó thay vì hiện nút
chắc chắn lỗi.

Danh sách conversation ghim nhóm này lên đầu trang đầu tiên, ưu tiên hơn thứ tự theo thời gian, để nó
không rơi khỏi trang khi vắng tin nhắn. Client nên giữ nguyên thứ tự server trả về thay vì tự sắp xếp.

### Update Employee Permissions

```http
PATCH /admin/chat/conversations/{conversation_id}/employee-permissions
```

Yêu cầu `chat.edit`.

Body:

```json
{
  "employee_can_send_message": true,
  "employee_can_send_image": true,
  "employee_can_send_file": true
}
```

Rules:

- Chỉ active admin participant có thể update các flag này.
- Support conversation reject permission update.
- Image/file flags ảnh hưởng đến feature-flagged attachment send path.

### Admin Close/Reopen

```http
POST /admin/chat/conversations/{conversation_id}/close
POST /admin/chat/conversations/{conversation_id}/reopen
```

Yêu cầu `chat.edit`.

Close body:

```json
{
  "close_reason": "Optional reason",
  "can_employee_reopen": false
}
```

Với support chat, admin có thể set `can_employee_reopen` khi đóng conversation.

### Support Triage List

```http
GET /admin/chat/support-conversations?status=unassigned&page_size=50
```

Yêu cầu `chat.assign`.

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

- Admin có `chat.assign` có thể xem unassigned support chat và messages.
- Họ chưa thể gửi cho đến khi được assign thành active admin participant.
- Assignment admin có thể assign chính mình hoặc các admin eligible khác.

### Assign Support Admins

```http
POST /admin/chat/support-conversations/{conversation_id}/assign-admins
```

Yêu cầu `chat.assign`.

Body:

```json
{
  "admin_user_ids": [20, 21]
}
```

Chỉ user có `chat.assign` mới được assign. Assignment idempotent với admin đã active.

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

Yêu cầu `chat.assign`.

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

Admin bị remove mất quyền access conversation/messages.

### Admin Active Conversation Heartbeat

```http
POST /admin/chat/active
DELETE /admin/chat/active
```

Semantics giống employee heartbeat, nhưng yêu cầu `chat.view`.

## Tích hợp realtime

Backend broadcast bằng Laravel broadcasting với `pusher` driver và Soketi. Mobile nên dùng client tương thích Pusher, ví dụ `pusher_channels_flutter`.

Các giá trị deployment cần được cung cấp theo từng môi trường:

```text
Pusher/Soketi app key
WebSocket host
WebSocket port
TLS on/off
Auth endpoint: <API_BASE>/broadcasting/auth
```

Default development trong repo:

```text
PUSHER_APP_KEY=dgf-connect
PUSHER_APP_ID=1
PUSHER_HOST=soketi
PUSHER_PORT=6001
PUSHER_SCHEME=http
```

### Broadcast Auth

Auth endpoint:

```http
POST /broadcasting/auth
```

Headers:

```http
Authorization: Bearer <access_token>
Accept: application/json
```

Body được Pusher client gửi:

```json
{
  "socket_id": "1234.5678",
  "channel_name": "private-chat.conversation.123"
}
```

Response success có field `auth`. Nếu 403 nghĩa là user hiện tại không được subscribe private channel đó.

### Channels

Conversation channel:

```text
private-chat.conversation.{conversation_id}
```

User channel:

```text
private-user-{user_id}
```

Laravel Echo subscribe bằng tên không có prefix `private-`, nhưng nhiều Pusher client trên mobile cần full channel name. Với Flutter, dự kiến subscribe `private-chat.conversation.$id` và `private-user-$userId`.

Conversation channel authorization:

- Active participant có thể subscribe.
- Admin có `chat.assign` có thể subscribe support conversation, bao gồm unassigned support chat.
- Removed participant và user không liên quan bị reject.

User channel authorization:

- Chỉ authenticated user tương ứng mới subscribe được `private-user-{user_id}`.

### Events

New message:

```text
event: chat.message.new
channel: private-chat.conversation.{conversation_id}
payload: ChatMessage
```

Dùng event này để append message mới nếu chưa có trong local state. De-duplicate bằng `id`, và có thể thêm `client_message_id` cho optimistic sends.

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

Dùng event này để refresh conversation list hoặc insert conversation mới.

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

Dùng event này để disable composer khi conversation closed và refresh conversation detail.

Read marker di chuyển (read receipt):

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

Chỉ bắn khi marker của participant thực sự tiến lên. Cập nhật `last_read_message_id` của participant
đó trong roster đang giữ rồi vẽ lại read receipt; không cần gọi lại API.

Đây là channel riêng, không phải channel của conversation: read marker chỉ dành cho admin, giống như
conversation detail phía nhân viên không trả về chúng. Subscribe cần thêm quyền `chat.view` ngoài
quyền truy cập conversation, nên client nhân viên không được subscribe channel này.

Lưu ý event name: Laravel Echo listen với dấu chấm đầu, ví dụ `.chat.message.new`. Pusher mobile library thường deliver event name không có dấu chấm đầu, ví dụ `chat.message.new`.

## Chiến lược sync cho mobile

Recommended startup/resume flow:

1. Authenticate và lưu access token.
2. Fetch `/user/chat/conversations?page_size=50`.
3. Subscribe `private-user-{user_id}`.
4. Subscribe `private-chat.conversation.{id}` cho các conversation visible hoặc conversation đang mở.
5. Khi mở conversation, fetch detail và latest messages.
6. Start active heartbeat cho conversation đó.
7. Mark read sau khi messages visible.

Recommended reconnect flow:

1. Reconnect Pusher/Soketi.
2. Refresh conversation list.
3. Với conversation đang mở, gọi:

```http
GET /user/chat/conversations/{conversation_id}/messages?after_id={last_known_message_id}
```

4. Append messages trả về theo thứ tự tăng dần và de-duplicate bằng `id`.

Hiện chưa có global chat sync endpoint. Dùng per-conversation `after_id` để lấy missed messages.

Recommended backscroll flow:

```http
GET /user/chat/conversations/{conversation_id}/messages?before_id={oldest_loaded_message_id}&limit=50
```

Prepend messages trả về. Dừng khi `has_more` là false.

## Push Notifications

Chat FCM là push-only. Nó không tạo notification-center row.

Push được gửi đến active conversation participants trừ sender. Với support chat mới, push được gửi đến admin có `chat.assign`. Push bị suppress nếu recipient có active heartbeat cho cùng conversation.

FCM data payload chứa các giá trị string:

```json
{
  "type": "chat_message",
  "conversation_id": "123",
  "message_id": "456"
}
```

Các chat push type có thể có:

```text
chat_message
chat_support_created
chat_admin_assigned
chat_state_changed
```

Khi tap push:

1. Parse `conversation_id`.
2. Mở màn hình chat.
3. Fetch conversation detail.
4. Fetch messages, tốt nhất dùng local cursor nếu có.
5. Mark read sau khi message visible.

Lưu ý local development: `SendChatMessageFcmJob` bỏ qua việc gửi Firebase thật khi `APP_ENV=local`. Backend tests assert dispatch/suppression logic, nhưng mobile sẽ không nhận local FCM từ job này trừ khi environment không phải local và Firebase đã được config.

## Trạng thái Attachment

Attachments đã được implement trong work tree backend hiện tại và có dedicated feature tests, nhưng mobile nên xem đây là feature-flagged cho đến khi backend xác nhận test pass trong môi trường test của project và storage settings đã deploy.

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

Default limits từ `config/chat.php`:

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

Download route nằm sau cùng `auth:api`, disabled-user check, và conversation-view access check. Mobile phải gửi bearer token và xử lý response như binary.

Attachment permission behavior:

- Trong non-support chat, employee text/image/file send bị gate bởi `employee_can_send_message`, `employee_can_send_image`, và `employee_can_send_file`.
- Admin participant không bị gate bởi employee permission flags.
- Support chat không dùng các employee permission flags này.

Caveat hiện tại: attachment feature tests có cover upload, message type derivation, attachment-only messages, permission denial, idempotent multipart retry, participant download, removed-user denial, non-participant denial, và support-view-admin download. Không ship attachment UI cho đến khi backend xác nhận các test này pass trong CI hoặc shared backend environment.

## Yêu cầu deployment backend cho Realtime/Push

Đây không phải task của mobile, nhưng mobile testing phụ thuộc vào chúng:

- `BROADCAST_CONNECTION=pusher`.
- `pusher/pusher-php-server` đã install. Package này có trong `composer.json`.
- Soketi reachable từ mobile client qua host/port/TLS đã config.
- `/broadcasting/auth` reachable qua API base URL với bearer auth.
- Queue worker chạy cho `ShouldBroadcast` events và FCM jobs.
- Redis/cache store available cho active-conversation heartbeat. Default chat presence store là `redis`.
- Firebase credentials được config ở non-local environment để gửi FCM.
- Scheduler chạy cho support và shift-chat auto-close jobs.

## Checklist tích hợp cho mobile

- Dùng bearer token auth cho REST và broadcast auth.
- Dùng path `/user/chat/...` cho employee app chat.
- Tạo UUID `client_message_id` cho mỗi normal text message.
- Không auto-retry support chat creation nếu chưa kiểm tra chat đã được tạo hay chưa.
- Dùng message `id` làm primary cursor và de-duplication key.
- Dùng `before_id` cho older history và `after_id` cho missed messages.
- Mark read khi conversation visible.
- Start active heartbeat khi màn hình chat visible và stop khi leave/background.
- Subscribe `private-user-{user_id}` để nhận conversation mới.
- Subscribe `private-chat.conversation.{conversation_id}` để nhận message và state events.
- Xử lý `system` messages như generic keys, hoặc ẩn cho đến khi `system_payload` được expose.
- Xử lý `429 Too Many Requests` khi gửi tin nhắn (30/phút) và tạo support chat (5/phút) bằng cách back off theo khoảng thời gian `Retry-After`.
- Giữ attachment upload/download sau feature flag cho đến khi backend xác nhận attachment tests pass.
