# Implementation Plan: Guest Support — Web Admin

## Overview

Implementation theo thứ tự **Types → Translations → UI**, đảm bảo TypeScript compiler luôn hài lòng trước khi viết UI. Tất cả thay đổi nằm trong 4 file hiện có, không tạo file mới. Checkpoint sau mỗi phase để verify typecheck và UI trước khi tiếp tục.

### Implementation Strategy
- **Types-First**: sửa `chat.d.ts` trước để compiler bắt lỗi ngay khi sửa UI
- **Translation-before-render**: thêm translation keys và chạy `trans:prepare/apply` trước khi dùng `t('Guest')` trong JSX
- Mỗi UI task độc lập, có thể verify riêng

## Implementation Plan

### Phase 1: TypeScript Types

- [x] 1. Mở rộng `frontend/src/types/models/chat.d.ts`
    - [x] 1.1 Thêm `'guest_support'` vào `ChatConversationType`
        - Sửa: `export type ChatConversationType = 'admin_group' | 'shift_group' | 'user_group' | 'support' | 'guest_support';`
        - _Requirements: 1_

    - [x] 1.2 Thêm `sender_name: string | null` vào `ChatMessage` interface
        - Thêm sau `sender_user_id`, trước `sender_profile_picture`
        - _Requirements: 1_

    - [x] 1.3 Thêm `ChatGuestInfo` interface và mở rộng `ChatSupportConversationListItem`
        - Thêm interface mới:
          ```typescript
          export interface ChatGuestInfo {
              name: string;
              email: string;
              device_id: string;
              platform: string;
          }
          ```
        - Sửa `ChatSupportConversationListItem`: đổi `created_by_user_id: number` → `number | null`; thêm `is_guest: boolean`, `guest_name: string | null`, `guest_email: string | null`
        - _Requirements: 1_

    - [x] 1.4 Thêm `guest_info: ChatGuestInfo | null` vào `ChatConversationDetail`
        - _Requirements: 1_

- [x] 2. Checkpoint — TypeScript compile
    - Chạy `pnpm typecheck` trong `./frontend`
    - Fix mọi lỗi type xuất hiện do `created_by_user_id` đổi sang nullable
    - Ensure all tests pass, ask the user if questions arise.

---

### Phase 2: Translations

- [x] 3. Thêm translation keys cho Guest Label
    - [x] 3.1 Dùng `t('Guest')` trong code (chưa cần locale file — scanner sẽ detect)
        - Key: `'Guest'` dùng trong support list và detail page
        - _Requirements: 5_

    - [x] 3.2 Chạy `pnpm trans:prepare` trong `./frontend`
        - Verify `Guest` xuất hiện trong `.translation-workflow/translation-batch.json`
        - _Requirements: 5_

    - [x] 3.3 Điền translations vào batch file
        - `vi`: `"Khách"`, `ja`: `"ゲスト"`
        - Giữ nguyên `en: "Guest"`, không sửa `key`
        - _Requirements: 5_

    - [x] 3.4 Chạy `pnpm trans:apply`
        - Verify `vi/translation.json` và `ja/translation.json` có key `Guest`
        - _Requirements: 5_

---

### Phase 3: UI — Support List Page

- [x] 4. Sửa `frontend/src/pages/admin/chat/support/index.page.tsx`
    - [x] 4.1 Hiển thị `guest_name` khi `created_by_name` là null
        - Thay `item.created_by_name` bằng `item.guest_name ?? item.created_by_name ?? '—'` trong requester name cell
        - _Requirements: 2_

    - [x] 4.2 Thêm Guest_Label badge khi `is_guest === true`
        - Import `Badge` từ `@mantine/core` (đã dùng ở nơi khác trong project)
        - Render inline sau tên: `{item.is_guest && <Badge variant="outline" size="xs" ml={4}>{t('Guest')}</Badge>}`
        - _Requirements: 2_

---

### Phase 4: UI — Support Detail Page

- [x] 5. Sửa `frontend/src/pages/admin/chat/support/[id].page.tsx`
    - [x] 5.1 Fix `senderNameById` để fallback sang `message.sender_name`
        - Thực tế: sửa tại `MessageList.tsx` (xem Task 6) vì `senderNameById` được build từ participants và truyền vào component
        - _Requirements: 4_

    - [x] 5.2 Thêm Guest Requester Info panel
        - Điều kiện: nếu `detail.type === 'guest_support'` → render guest info block với tên + email + Guest badge
        - Nếu không phải guest: giữ nguyên render employee info hiện tại
        - _Requirements: 3_

    - [x] 5.3 Ẩn participant entry cho guest trong participants sidebar
        - Backend không trả về ChatParticipant cho guest — không cần filter ở frontend
        - _Requirements: 3_

---

### Phase 5: UI — MessageList Sender Name Fix

- [x] 6. Sửa `frontend/src/components/chat/MessageList.tsx`
    - [x] 6.1 Thêm fallback `msg.sender_name` khi resolve tên người gửi
        - `isSystem` cập nhật: `message.message_type === 'system' || (!message.sender_user_id && !message.sender_name)`
        - `senderName` cập nhật: fallback sang `message.sender_name` khi `sender_user_id` null
        - _Requirements: 4_

- [x] 7. Checkpoint — Verify UI với mock data
    - `pnpm typecheck` pass (không có lỗi mới)
    - UI verification cần dev server — skipped (no browser)

---

### Phase 6: Final Validation

- [x] 8. Code Quality
    - [x] 8.1 Chạy `pnpm lint:fix` trong `./frontend`
    - [x] 8.2 Chạy `pnpm prettier` trong `./frontend`
    - [x] 8.3 Chạy `pnpm typecheck` trong `./frontend` — pass không có lỗi mới
    - [x] 8.4 Chạy `pnpm trans:cleanup` để dọn file tạm dịch thuật

## Notes

- Task 5.1 và Task 6 liên quan chặt: logic fix sender name nằm trong `MessageList.tsx`, nhưng data đến từ `[id].page.tsx` — cần đọc cả hai file trước khi quyết định sửa ở đâu
- `created_by_user_id` đổi sang `number | null` ở Task 1.3 có thể gây lỗi type nếu code khác đang dùng nó như non-null — Task 2 (checkpoint typecheck) bắt được điều này
- Guest Badge dùng `variant="outline"` để phân biệt với status badge (`"filled"`) — nhất quán với design system
