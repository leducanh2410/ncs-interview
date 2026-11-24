# Implementation Plan: Support Type & Auto-Assignment

## Overview

Triển khai theo hướng Foundation-First kết hợp Feature-Slice: bắt đầu từ backend (enum → migration → model → actions → job → API), sau đó frontend (types → component dùng chung → config screen → list → detail). Mỗi phase có checkpoint để đảm bảo không có regression trước khi chuyển phase tiếp theo. Backend và frontend có thể triển khai song song sau Phase 1.

### Implementation Strategy
- Backend trước, frontend sau — frontend phụ thuộc vào API response shape
- Reuse `AssignSupportAdminsAction` cho auto-assign, không viết logic mới
- `shift_id` không cần migration — chỉ expose trong validation và response
- `SupportTypeLabel` component viết một lần, dùng chung cho list và detail

---

## Implementation Plan

### Phase 1: Backend Foundation

- [x] 1. SupportType Enum
    - [x] 1.1 Tạo `backend/app/Enums/SupportType.php`
        - 4 case: `Document='document'`, `Shift='shift'`, `Salary='salary'`, `Transportation='transportation'`
        - Dùng `EnumTrait` như các enum khác trong project
        - _Requirements: 1_

- [x] 2. Migrations
    - [x] 2.1 Tạo migration `2026_06_25_000001_add_support_type_to_chat_conversations_table.php`
        - Thêm `$table->string('support_type')->nullable()->after('type')`
        - Thêm index: `$table->index('support_type')`
        - _Requirements: 1_

    - [x] 2.2 Tạo migration `2026_06_25_000002_create_support_type_admins_table.php`
        - Các cột: `id`, `support_type` (string), `user_id` (FK→users, cascadeOnDelete), `timestamps`
        - Unique constraint: `['support_type', 'user_id']`
        - Index trên `support_type`
        - _Requirements: 2_

    - [ ] 2.3 Chạy migration và xác nhận schema đúng
        - `docker compose exec php php artisan migrate`
        - _Requirements: 1, 2_

- [ ] 3. Checkpoint — Backend Foundation
    - Xác nhận cả 2 migration chạy thành công và rollback được (`migrate:rollback`)
    - Xác nhận `SupportType::values()` trả về 4 giá trị đúng

---

### Phase 2: Backend Model & Actions

- [x] 4. SupportTypeAdmin Model
    - [x] 4.1 Tạo `backend/app/Models/SupportTypeAdmin.php`
        - `$fillable = ['support_type', 'user_id']`
        - Relation `user(): BelongsTo` tới `User`
        - _Requirements: 2_

    - [x] 4.2 Thêm `support_type` vào `ChatConversation::$fillable`
        - _Requirements: 1_ (ChatConversation dùng `$guarded = []` nên không cần thay đổi)

- [x] 5. Support Type Admin Actions
    - [x] 5.1 Tạo `backend/app/Actions/Chat/Support/GetSupportTypeAdminsAction.php`
        - Eager load `user:id,name,staff_no,profile_picture`
        - Group theo `support_type`, luôn trả đủ 4 type kể cả khi `admins: []`
        - _Requirements: 2_

    - [x] 5.2 Tạo `backend/app/Actions/Chat/Support/AddSupportTypeAdminAction.php`
        - `firstOrCreate(['support_type', 'user_id'])` — idempotent
        - Load lại `user:id,name,staff_no,profile_picture` trước khi return
        - _Requirements: 2_

    - [x] 5.3 Tạo `backend/app/Actions/Chat/Support/RemoveSupportTypeAdminAction.php`
        - `delete()` — no-op nếu không tồn tại (không throw)
        - _Requirements: 2_

---

### Phase 3: Backend API

- [x] 6. SupportTypeAdminController & Routes
    - [x] 6.1 Tạo `backend/app/Http/Controllers/Admin/Chat/SupportTypeController.php`
        - 3 method: `index`, `addAdmin`, `removeAdmin`
        - Dùng `SupportType::tryFrom($type) ?? abort(422)` để validate path param
        - _Requirements: 2_

    - [x] 6.2 Đăng ký routes trong `backend/routes/api.php`
        - `GET /admin/chat/support-types` → `index`
        - `POST /admin/chat/support-types/{type}/admins` → `addAdmin`
        - `DELETE /admin/chat/support-types/{type}/admins/{userId}` → `removeAdmin`
        - Middleware group: `permission:chat.assign`
        - _Requirements: 2_

- [x] 7. Cập nhật API tạo Support Conversation
    - [x] 7.1 Cập nhật `CreateSupportConversationRequest`
        - Thêm rule: `'support_type' => ['nullable', 'string', Rule::enum(SupportType::class)]`
        - Thêm rule: `'shift_id' => ['nullable', 'integer', 'exists:shifts,id']`
        - _Requirements: 3_

    - [x] 7.2 Cập nhật `CreateSupportConversationAction`
        - Thêm `support_type` và `shift_id` vào `ChatConversation::create()`
        - Dispatch `AutoAssignSupportAdminJob::dispatch($conversation->id)->delay(now()->addMinutes(5))` sau khi tạo conversation
        - Thêm `support_type` và `shift_id` vào `toPayload()`
        - _Requirements: 3, 6_

    - [x] 7.3 Cập nhật validation trong `Guest/ChatSupportController`
        - Thêm rule: `'support_type' => ['nullable', 'string', Rule::enum(SupportType::class)]`
        - Không thêm `shift_id` (guest không có ca làm)
        - _Requirements: 3_

    - [x] 7.4 Cập nhật `CreateGuestSupportConversationAction`
        - Thêm `support_type` vào `ChatConversation::create()`
        - Dispatch `AutoAssignSupportAdminJob`
        - Thêm `support_type` vào response payload
        - _Requirements: 3, 6_

- [x] 8. Cập nhật GetSupportConversationsAction
    - [x] 8.1 Thêm filter `support_type` vào query
        - _Requirements: 4_

    - [x] 8.2 Thêm `support_type` vào response item mapping
        - _Requirements: 4, 5_

---

### Phase 4: AutoAssignSupportAdminJob

- [x] 9. Tạo AutoAssignSupportAdminJob
    - [x] 9.1 Tạo `backend/app/Jobs/AutoAssignSupportAdminJob.php`
        - Implements `ShouldQueue`, dùng `Queueable`
        - Constructor nhận `int $conversationId`
        - Guard 1: conversation không tồn tại → return
        - Guard 2: conversation status = closed → return
        - Guard 3: đã có `ChatParticipant` với role=admin, status=active → return
        - Logic: lấy admins từ `support_type_admins` theo type, fallback về `User::permission(ChatAssign)` nếu rỗng hoặc type null
        - Gọi `AssignSupportAdminsAction` với actor = user đầu tiên có `chat.assign` permission
        - Guard cuối: không có ai để assign → return (không throw)
        - _Requirements: 6_

- [x] 10. Checkpoint — Backend hoàn chỉnh
    - Chạy `npm run prettier && npm run pint` trong `./backend`
    - Test thủ công: tạo support conversation → kiểm tra DB có `support_type`, job được dispatch
    - Test thủ công: GET `/admin/support-types` → trả về 4 type

---

### Phase 5: Backend Tests

- [x] 11. Unit Tests — Actions
    - [x] 11.1 `tests/Unit/Actions/Chat/Support/GetSupportTypeAdminsActionTest.php`
        - `testReturnsAllFourTypesEvenWhenEmpty`
        - `testGroupsAdminsByTypeAndIncludesProfilePicture`
        - _Requirements: 2_

    - [x] 11.2 `tests/Unit/Actions/Chat/Support/AddSupportTypeAdminActionTest.php`
        - `testAddsSupportTypeAdmin`
        - `testIdempotentOnDuplicate`
        - _Requirements: 2_

    - [x] 11.3 `tests/Unit/Actions/Chat/Support/RemoveSupportTypeAdminActionTest.php`
        - `testRemovesSupportTypeAdmin`
        - `testNoopWhenAdminNotAssigned`
        - _Requirements: 2_

- [x] 12. Unit Tests — AutoAssignSupportAdminJob
    - [x] 12.1 `tests/Unit/Jobs/AutoAssignSupportAdminJobTest.php`
        - `testSkipsIfConversationNotFound`
        - `testSkipsIfConversationClosed`
        - `testSkipsIfAlreadyHasActiveAdmin`
        - `testAssignsTypedAdminsWhenTypeSet`
        - `testFallsBackToAllChatAssignUsersWhenTypeHasNoAdmins`
        - `testFallsBackToAllChatAssignUsersWhenSupportTypeIsNull`
        - `testDoesNothingWhenNoFallbackAdminsExist`
        - _Requirements: 6_

- [x] 13. Feature Tests — SupportTypeAdminController
    - [x] 13.1 `tests/Feature/Chat/Admin/SupportTypeControllerTest.php`
        - `testIndexRequiresAuthentication` → 401
        - `testIndexRequiresChatAssignPermission` → 403
        - `testIndexReturnsAllFourTypesWithAdminsAndProfilePicture`
        - `testAddAdminRequiresChatAssignPermission` → 403
        - `testAddAdminReturns201AndPersists`
        - `testAddAdminIsIdempotent`
        - `testAddAdminReturns422ForInvalidType`
        - `testAddAdminReturns422ForInvalidUserId`
        - `testRemoveAdminRequiresChatAssignPermission` → 403
        - `testRemoveAdminReturns204`
        - `testRemoveAdminNoopWhenNotAssigned` → 204
        - `testRemoveAdminReturns422ForInvalidType`
        - _Requirements: 2_

- [x] 14. Feature Tests — Create Support Conversation
    - [x] 14.1 Extend `tests/Feature/Chat/SupportCreateTest.php`
        - `testCreateSupportConversationWithSupportType` — lưu đúng type, job dispatched
        - `testCreateSupportConversationWithShiftId` — lưu đúng `shift_id`
        - `testCreateSupportConversationWithoutSupportType` — lưu null, job vẫn dispatched
        - `testCreateSupportConversationReturns422ForInvalidType`
        - `testCreateSupportConversationReturns422ForInvalidShiftId`
        - `testResponseIncludesSupportTypeAndShiftId`
        - _Requirements: 3_

    - [x] 14.2 Extend `tests/Feature/GuestSupport/GuestSupportApiTest.php`
        - `testGuestCreateSupportWithSupportType`
        - `testGuestCreateSupportWithoutSupportType`
        - `testGuestCreateSupportReturns422ForInvalidType`
        - `testGuestCreateSupportDoesNotAcceptShiftId` — `shift_id` bị ignore
        - _Requirements: 3_

- [x] 15. Feature Tests — Support Conversation List Filter
    - [x] 15.1 Tạo `tests/Feature/Chat/SupportTypeFilterTest.php`
        - `testFilterBySupportType`
        - `testFilterBySupportTypeCombinedWithStatusFilter`
        - `testFilterByInvalidSupportTypeReturns422`
        - `testResponseItemIncludesSupportTypeField`
        - _Requirements: 4_

- [ ] 16. Checkpoint — Tất cả backend tests pass
    - Chạy `docker compose exec php ./vendor/bin/phpunit`
    - Đảm bảo không có test nào bị break từ code cũ

---

### Phase 6: Frontend Foundation

- [x] 17. TypeScript Types
    - [x] 17.1 Cập nhật `frontend/src/types/models/chat.d.ts`
        - Thêm `SupportType = 'document' | 'shift' | 'salary' | 'transportation'`
        - Thêm `SupportTypeConfig` interface (type, admins với profile_picture)
        - Extend `ChatSupportConversationListItem` với `support_type: SupportType | null`
        - Extend `ChatConversationDetail` với `support_type?: SupportType | null`
        - _Requirements: 1, 2, 4_

    - [x] 17.2 Cập nhật `frontend/src/configs/api.config.ts`
        - Thêm `ADMIN_CHAT_SUPPORT_TYPES`, `ADMIN_CHAT_SUPPORT_TYPE_ADMINS`, `ADMIN_CHAT_SUPPORT_TYPE_ADMIN`
        - _Requirements: 2_

- [x] 18. API Hooks
    - [x] 18.1 Bổ sung hooks vào `frontend/src/pages/admin/chat/support/api.ts`
        - `useSupportTypeConfigsQuery()`
        - `useAddSupportTypeAdminMutation()`
        - `useRemoveSupportTypeAdminMutation()`
        - _Requirements: 2_

    - [x] 18.2 Cập nhật `frontend/src/pages/admin/chat/support/api.ts`
        - `useSupportConversationsQuery()` đã pass `router.query` toàn bộ — filter `support_type` tự động included
        - _Requirements: 4_

- [x] 19. SupportTypeLabel Component
    - [x] 19.1 Tạo `frontend/src/components/SupportTypeLabel.tsx`
        - Color map: `document→blue`, `shift→green`, `salary→orange`, `transportation→violet`
        - Dùng Mantine `Badge` với `variant="light"`
        - Props: `type: SupportType`, `size?: 'xs' | 'sm' | 'md'` (default `'sm'`)
        - Label dùng `t(`support_type.${type}`)` — dynamic key
        - _Requirements: 1, 4, 5_

    - [x] 19.2 Đăng ký dynamic keys trong `frontend/src/locales/for_scanner.ts`
        - Thêm 4 dòng: `t('support_type|document')` ... (dùng `|` vì đó là `nsSeparator`)
        - Thêm namespace `support_type` vào `i18next-scanner.config.js` và `i18n.ts`
        - _Requirements: 1_

---

### Phase 7: Frontend Translations

- [x] 20. Chuẩn bị và apply translations
    - [ ] 20.1 Cập nhật tất cả code dùng `t(...)` cho các string mới (xem danh sách ở bước 19, 21, 22, 23)
    - [ ] 20.2 Chạy `pnpm trans:prepare` trong `./frontend`
    - [ ] 20.3 Điền `vi` và `ja` vào `.translation-workflow/translation-batch.json`

        | Key | en | vi | ja |
        |-----|----|----|-----|
        | `support_type.document` | Document | Giấy tờ | 書類 |
        | `support_type.shift` | Shift | Ca làm | シフト |
        | `support_type.salary` | Salary | Lương | 給与 |
        | `support_type.transportation` | Transportation | Phương thức đi lại | 交通手段 |
        | `Support types` | Support types | Loại hỗ trợ | サポート種類 |
        | `All types` | All types | Tất cả loại | すべての種類 |
        | `Add admin` | Add admin | Thêm admin | 管理者を追加 |

    - [ ] 20.4 Chạy `pnpm trans:apply`
    - _Requirements: 1, 2, 4_

---

### Phase 8: Frontend UI

- [x] 21. Config Screen — Support Type Section
    - [x] 21.1 Tạo `frontend/src/pages/admin/configuration/partials/support_type_config.tsx`
        - Dùng `useSupportTypeConfigsQuery()` để fetch data
        - Hiển thị 4 card/section, mỗi section có `SupportTypeLabel` và danh sách admins (Avatar + tên)
        - Nếu có `chat.assign` permission: hiển thị nút "Add admin" và nút xoá (x) trên mỗi admin
        - Nếu không có `chat.assign`: read-only
        - Empty state khi type không có admin
        - _Requirements: 2_

    - [x] 21.2 Tích hợp vào trang configuration chính
        - Import và render `SupportTypeConfig` trong file configuration page/form
        - Guard bằng `config.edit` permission
        - _Requirements: 2_

- [x] 22. Support List — Badge và Filter
    - [x] 22.1 Cập nhật `frontend/src/pages/admin/chat/support/index.page.tsx`
        - Thêm `SupportTypeLabel` vào mỗi row (chỉ render nếu `item.support_type` không null)
        - Thêm `Select` filter dropdown bên trên table với 4 option + "All types"
        - Khi filter thay đổi: update query param, reset về page 1
        - _Requirements: 4_

- [x] 23. Support Detail — Badge
    - [x] 23.1 Cập nhật `frontend/src/pages/admin/chat/support/[id].page.tsx`
        - Thêm `SupportTypeLabel` trong header/info section (chỉ render nếu `support_type` không null)
        - _Requirements: 5_

---

### Phase 9: Frontend Tests

- [ ] 24. Unit Tests — Components
    - [ ] 24.1 Tạo `frontend/src/components/chat/SupportTypeLabel.test.tsx`
        - Render badge đúng màu cho từng type (4 test)
        - Render đúng label (mock i18n)
        - Prop `size` được pass xuống Badge
        - _Requirements: 1, 4, 5_

    - [ ] 24.2 Tạo `frontend/src/pages/admin/configuration/partials/support_type_config.test.tsx`
        - Hiển thị 4 section
        - Hiển thị admins đã gán với avatar
        - Hiển thị empty state khi không có admin
        - Ẩn add/remove khi thiếu `chat.assign`
        - Hiển thị add/remove khi có `chat.assign`
        - Gọi remove mutation khi click xoá
        - _Requirements: 2_

    - [ ] 24.3 Extend support list test
        - Hiển thị badge khi `support_type` có giá trị
        - Không hiển thị badge khi `support_type` là null
        - Filter dropdown trigger đúng query param
        - _Requirements: 4_

- [ ] 25. Chạy `pnpm test` và đảm bảo tất cả pass
    - _Requirements: 1, 2, 3, 4, 5, 6_

---

### Phase 10: Final Validation

- [ ] 26. Code Quality
    - [ ] 26.1 Chạy `pnpm lint:fix` trong `./frontend`
    - [ ] 26.2 Chạy `pnpm prettier` trong `./frontend`
    - [ ] 26.3 Chạy `pnpm typecheck` trong `./frontend` — không có lỗi type
    - [ ] 26.4 Chạy `npm run prettier && npm run pint` trong `./backend`

- [ ] 27. Checkpoint — End-to-end validation
    - Tạo support conversation có `support_type=shift` và `shift_id` → kiểm tra DB và response
    - Tạo guest support với `support_type=salary` → kiểm tra DB
    - Mở support list → badge hiển thị, filter hoạt động
    - Mở support detail → badge hiển thị
    - Vào config screen → thêm/xoá admin cho từng type
    - Simulate auto-assign: tạo conversation, giảm delay job xuống 10 giây để test, xác nhận admin được assign

---

## Notes

- **Thứ tự Phase 5 và 6 có thể song song**: Backend tests (Phase 5) và Frontend Foundation (Phase 6-7) độc lập nhau — 2 người có thể làm đồng thời
- **`shift_id` không cần migration**: Cột đã tồn tại trong `chat_conversations` từ trước
- **AutoAssignSupportAdminJob dùng actor hệ thống**: `AssignSupportAdminsAction` yêu cầu một `User` actor — dùng user đầu tiên có `chat.assign` permission; nếu không tìm được actor thì skip (không throw)
- **Risk — Queue worker phải chạy**: Nếu queue worker không chạy, job sẽ không được xử lý. Cần đảm bảo queue worker active trong môi trường production
- **Dynamic translation keys**: `t('support_type.${type}')` không được scanner tự detect — đã đăng ký thủ công trong `for_scanner.ts` (bước 19.2)
