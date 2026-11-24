# Requirements Document

## Introduction

Feature này mở rộng giao diện web admin để hiển thị và quản lý guest support conversations — loại hội thoại được tạo bởi nhân viên chưa có tài khoản. Giao diện không thay đổi luồng xử lý (triage, assign, chat) mà chỉ bổ sung visual indicator phân biệt guest với authenticated support, và thay thế phần hiển thị thông tin requester cho phù hợp khi không có user account. Ngoài ra, vì guest messages không có participant trong danh sách, cần điều chỉnh cách resolve sender name từ `sender_name` trong message payload thay vì từ participant map.

### Feature Summary
Cập nhật web admin để phân biệt và hiển thị thông tin guest support conversations với i18n label, đồng thời xử lý đúng sender name cho guest messages.

### Business Value
Admin có thể nhận diện nhanh loại hỗ trợ (guest vs. authenticated) ngay từ danh sách, tránh nhầm lẫn khi không thấy thông tin user profile phía requester.

### Scope

**Bao gồm:**
- Thêm `guest_support` vào TypeScript enum `ChatConversationType`
- Thêm guest fields vào type `ChatSupportConversationListItem`
- Thêm field `sender_name` vào type `ChatMessage`
- Support list page: hiển thị i18n label "Guest/ゲスト/Khách" trên guest conversation items
- Support detail page: hiển thị `guest_name`, `guest_email` thay cho employee participant info
- Message rendering: dùng `sender_name` từ message payload thay vì lookup từ participant map cho guest messages
- Translation keys cho guest label (EN/JA/VI)

**Không bao gồm:**
- Trang/tab riêng cho guest support (cùng list với authenticated)
- Thay đổi luồng assign, close, reopen (giữ nguyên)
- File attachment cho guest (chưa hỗ trợ ở backend)

## Glossary

| Term | Definition |
|------|------------|
| Guest_Conversation | ChatConversation có `type = 'guest_support'` và `is_guest = true` trong API response |
| Guest_Label | Tag i18n hiển thị trên UI để phân biệt guest conversation: "Guest" / "ゲスト" / "Khách" |
| Guest_Info | Object `{ name, email, device_id, platform }` trả về từ API detail endpoint cho guest conversations |
| Sender_Name_Field | Field `sender_name` trong `ChatMessage` payload — dùng thay cho participant lookup khi `sender_user_id = null` và message do guest gửi |
| Support_List_Page | Trang `/admin/chat/support` hiển thị danh sách support conversations |
| Support_Detail_Page | Trang `/admin/chat/support/[id]` hiển thị chi tiết và chat interface |
| Requester_Info | Panel bên trái trong Support_Detail_Page hiển thị thông tin người tạo hội thoại |

## Requirements

### Requirement 1: Cập nhật TypeScript Types

**User Story:** As a frontend developer, I want TypeScript types to reflect the new guest_support fields, so that the compiler catches mismatches during development.

#### Acceptance Criteria

1. WHEN `ChatConversationType` được định nghĩa THEN type SHALL bao gồm `'guest_support'` cùng với các values hiện tại (`'support'`, `'admin_group'`, `'shift_group'`, `'user_group'`)
2. WHEN `ChatSupportConversationListItem` được định nghĩa THEN type SHALL bao gồm thêm fields: `is_guest: boolean`, `guest_name: string | null`, `guest_email: string | null`
3. WHEN `ChatMessage` được định nghĩa THEN type SHALL bao gồm thêm field `sender_name: string | null`
4. IF `is_guest = true` THEN `created_by_user_id` và `created_by_name` trong `ChatSupportConversationListItem` SHALL được phép là `null` (đã nullable, cần verify)
5. WHEN type `ChatConversationDetail` trả về guest conversation THEN type SHALL bao gồm optional field `guest_info: { name: string; email: string; device_id: string; platform: string } | null`

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Backend spec đã implement Req 1, 4, 9

---

### Requirement 2: Guest Label trên Support List

**User Story:** As an admin, I want to see a visual label on guest conversations in the support list, so that I can quickly identify which requests come from users without accounts.

#### Acceptance Criteria

1. WHEN Support_List_Page render một conversation item có `is_guest = true` THEN UI SHALL hiển thị Guest_Label dạng badge/tag ngay cạnh tên requester
2. WHEN ngôn ngữ hiển thị là tiếng Anh THEN Guest_Label SHALL hiển thị `"Guest"`
3. WHEN ngôn ngữ hiển thị là tiếng Nhật THEN Guest_Label SHALL hiển thị `"ゲスト"`
4. WHEN ngôn ngữ hiển thị là tiếng Việt THEN Guest_Label SHALL hiển thị `"Khách"`
5. IF `is_guest = true` VÀ `created_by_name` là null THEN Support_List_Page SHALL hiển thị `guest_name` làm tên requester thay thế
6. WHERE conversation có `is_guest = false` THEN Support_List_Page SHALL KHÔNG hiển thị Guest_Label (không thay đổi behavior hiện tại)

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1; translation keys phải được thêm trước khi render

---

### Requirement 3: Guest Info trên Support Detail Page

**User Story:** As an admin viewing a guest support conversation, I want to see the guest's name and email in the requester panel, so that I know who I'm helping and can follow up via email if needed.

#### Acceptance Criteria

1. WHEN Support_Detail_Page load một Guest_Conversation THEN Requester_Info panel SHALL hiển thị `guest_name` và `guest_email` từ `guest_info` trong API response
2. WHEN Requester_Info render guest THEN UI SHALL hiển thị Guest_Label badge cạnh tên để nhận biết đây là guest
3. WHERE conversation là guest THEN Requester_Info SHALL KHÔNG render link đến user profile (không có user account)
4. IF `guest_info` là null hoặc chưa load THEN Requester_Info SHALL hiển thị skeleton loader (cùng pattern với authenticated support)
5. WHEN Support_Detail_Page render participants sidebar THEN guest SHALL KHÔNG xuất hiện trong danh sách participants (không có ChatParticipant record phía guest)

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1; Backend API trả về `guest_info` trong conversation detail response

---

### Requirement 4: Sender Name cho Guest Messages

**User Story:** As an admin reading a guest support conversation, I want guest messages to display the guest's name as the sender, so that I can follow the conversation clearly.

#### Acceptance Criteria

1. WHEN MessageList render một message có `sender_user_id = null` VÀ `sender_name` không null THEN UI SHALL hiển thị `sender_name` làm tên người gửi
2. WHEN `senderNameById` map được build trong Support_Detail_Page THEN logic SHALL ưu tiên `message.sender_name` cho messages có `sender_user_id = null` thay vì lookup từ participant map
3. IF `sender_user_id = null` VÀ `sender_name = null` THEN MessageList SHALL xử lý như system message (không hiển thị sender name) — đây là case system message hiện tại
4. WHERE message do admin gửi (`sender_user_id != null`) THEN sender name resolution SHALL KHÔNG thay đổi so với behavior hiện tại
5. WHEN realtime event `chat.message.new` nhận được message từ guest THEN UI SHALL hiển thị đúng `sender_name` từ event payload mà không cần refetch conversation detail

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1; Backend đã thêm `sender_name` vào broadcast payload và message list response

---

### Requirement 5: Translation Keys

**User Story:** As a developer, I want all guest-related UI text to use the translation function, so that the app remains fully localized.

#### Acceptance Criteria

1. WHEN Guest_Label được hiển thị THEN code SHALL dùng `t('Guest')` thay vì hardcode string
2. WHEN email label trong Guest_Info panel được hiển thị THEN code SHALL dùng `t('Email')` hoặc key phù hợp
3. WHEN tất cả translation keys mới được thêm THEN `pnpm trans:prepare` SHALL detect chúng và generate batch file
4. IF translation key mới cần thêm vào vi/ja locale THEN SHALL follow quy trình `trans:prepare` → fill batch → `trans:apply`

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Không có — phải làm trước khi implement UI text

## Non-Functional Requirements

### Performance Requirements
- WHEN Support_List_Page render danh sách kết hợp authenticated + guest conversations THEN render time SHALL không tăng đáng kể so với hiện tại (guest fields chỉ là thêm text/badge)

### Security Requirements
- WHEN web admin hiển thị `guest_email` THEN UI SHALL chỉ render text thuần, không tạo mailto link tự động (tránh tracking pixel / email injection)

### Usability Requirements
- WHEN Guest_Label được hiển thị THEN badge SHALL có màu sắc phân biệt nhưng không gây mất tập trung — dùng màu neutral (gray hoặc outline style) thay vì màu cảnh báo
- WHEN admin hover vào Guest_Label THEN KHÔNG cần tooltip (label đã đủ rõ ràng)

### Reliability Requirements
- IF API trả về `is_guest = true` nhưng `guest_info = null` THEN UI SHALL KHÔNG crash — render fallback gracefully

## Constraints and Assumptions

### Technical Constraints
- Không tạo route mới hay trang mới — guest conversations xuất hiện trong cùng support list và detail pages
- `MessageList` component là shared — sửa đổi phải backward-compatible với non-guest conversations
- Mantine UI component library — Guest_Label dùng `Badge` component với `variant="outline"` để phân biệt với status badges (đang dùng filled variant)

### Assumptions
- Backend đã implement và return đúng `is_guest`, `guest_name`, `guest_email`, `guest_info`, `sender_name` theo Backend spec
- Guest conversations xuất hiện trong cùng endpoint `admin/chat/support-conversations` không cần param mới
- Admin không cần filter riêng "chỉ xem guest" trong phiên bản đầu (filter hiện tại: unassigned/assigned/all là đủ)

## Success Criteria

### Definition of Done
- [ ] TypeScript compile không có lỗi mới (`pnpm typecheck` pass)
- [ ] Guest_Label hiển thị đúng trên list và detail page
- [ ] Guest info (name + email) hiển thị trong Requester_Info panel
- [ ] Guest messages hiển thị đúng `sender_name`
- [ ] Ba ngôn ngữ (EN/JA/VI) đã có translation cho guest label
- [ ] `pnpm lint:fix`, `pnpm prettier`, `pnpm typecheck` tất cả pass
