# Requirements Document

## Introduction

Tính năng này bổ sung **Support Type** (loại hỗ trợ) vào hệ thống chat hỗ trợ của DGF Connect, cho phép phân loại yêu cầu hỗ trợ theo 4 nhóm (Giấy tờ, Ca làm, Lương, Phương thức đi lại). Kèm theo đó là màn hình cấu hình để quản lý admin phụ trách từng loại, hiển thị nhãn loại trên danh sách và chi tiết support, và cơ chế tự động phân công admin sau 5 phút nếu chưa có ai nhận. Không có breaking change cho mobile app ngoài việc bổ sung field `support_type` vào request tạo support (optional).

### Feature Summary
Thêm 4 loại hỗ trợ có thể chọn khi tạo support request, kèm giao diện quản lý admin phụ trách từng loại và tự động phân công sau 5 phút.

### Business Value
Giúp admin triage nhanh hơn nhờ nhìn thấy loại yêu cầu ngay ở danh sách; giảm thời gian phản hồi nhờ auto-assign đúng admin phụ trách; cho phép phân công trách nhiệm rõ ràng theo chuyên môn.

### Scope

**Included:**
- Backend: enum `SupportType`, migration thêm `support_type` vào `chat_conversations`, bảng `support_type_admins`, API CRUD quản lý admin theo loại
- Backend: cập nhật API tạo support (user và guest) để nhận `support_type`
- Backend: `AutoAssignSupportAdminJob` chạy sau 5 phút
- Frontend: màn hình cấu hình quản lý support type admins
- Frontend: nhãn màu (badge) loại support trên danh sách và chi tiết
- Frontend: filter theo loại trên danh sách support
- Đa ngôn ngữ: vi, en, ja cho 4 loại

**Excluded:**
- Thay đổi logic close/reopen conversation
- Thêm loại support type mới ngoài 4 loại đã định
- Báo cáo/thống kê theo loại support
- Mobile app UI (chỉ cần gửi `support_type` lên API)
- Thêm quyền mới — feature dùng lại `chat.assign` và `config.edit` có sẵn

---

## Glossary

| Term | Definition |
|------|------------|
| Support_Type | Loại yêu cầu hỗ trợ, một trong 4 giá trị: `document`, `shift`, `salary`, `transportation` |
| Support_Type_Admin | User được gán phụ trách một Support_Type cụ thể; lưu trong bảng `support_type_admins` |
| Chat_Assign_Permission | Quyền `chat.assign` ("Phân công chat hỗ trợ") — quyền có sẵn trong hệ thống, dùng lại để kiểm soát ai được thêm/xoá Support_Type_Admin |
| Config_Edit_Permission | Quyền `config.edit` — quyền chỉnh sửa màn hình thiết lập cấu hình |
| Auto_Assign_Job | Job `AutoAssignSupportAdminJob` chạy sau 5 phút, phân công admin nếu chưa có ai nhận |
| Support_Conversation | Chat conversation có type `support` hoặc `guest_support` |
| Support_Type_Config_Screen | Màn hình trong /admin/configuration để quản lý Support_Type_Admin |
| Fallback_Admins | Tất cả user có Chat_Assign_Permission, dùng khi Support_Type không có ai phụ trách |

---

## Requirements

### Requirement 1: Support Type Enum và Lưu Trữ

**User Story:** As a system architect, I want a `support_type` field stored on each support conversation, so that the system can track and route support requests by category.

#### Acceptance Criteria

1. WHEN a support conversation is created THEN `Chat_Conversations_Table` SHALL store `support_type` as a nullable string column with one of four values: `document`, `shift`, `salary`, `transportation`
2. IF `support_type` is omitted in the request THEN `Chat_Conversations_Table` SHALL store `NULL` for that conversation
3. WHERE `support_type` values are used in the system THEN `SupportType_Enum` SHALL be the single source of truth with values: `document`, `shift`, `salary`, `transportation`
4. WHEN displaying a support type label THEN `Support_Type_Label` SHALL render the localized name in the current user language (vi/en/ja)

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Existing `chat_conversations` table

---

### Requirement 2: Màn Hình Quản Lý Support Type Admins

**User Story:** As an admin with config.edit permission, I want to manage which admins are responsible for each support type, so that the auto-assignment can route to the correct people.

#### Acceptance Criteria

1. WHEN a user with Config_Edit_Permission visits `/admin/configuration` THEN `Support_Type_Config_Screen` SHALL display all 4 support types with their currently assigned admins
2. WHEN a user with Chat_Assign_Permission clicks "Add admin" on a support type THEN `Support_Type_Config_Screen` SHALL allow selecting a user and adding them as Support_Type_Admin for that type
3. IF a user is already assigned to a support type THEN `Support_Type_Config_Screen` SHALL not add duplicates (idempotent)
4. WHEN a user with Chat_Assign_Permission clicks remove on an assigned admin THEN `Support_Type_Config_Screen` SHALL remove that admin from the support type
5. IF the current user does NOT have Chat_Assign_Permission THEN `Support_Type_Config_Screen` SHALL display assigned admins in read-only mode without add/remove controls
6. WHILE the Support_Type_Config_Screen is displayed THEN it SHALL show each type with a localized label in the current language
7. WHERE a support type has no assigned admins THEN `Support_Type_Config_Screen` SHALL display an empty state (not an error)
8. IF the current user does NOT have Config_Edit_Permission THEN `Support_Type_Config_Screen` SHALL not be accessible

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, existing config screen layout, `chat.assign` and `config.edit` permissions

---

### Requirement 3: Tạo Support Request Với Support Type

**User Story:** As an employee or guest, I want to specify a support type when creating a support request, so that my request is routed to the right team.

#### Acceptance Criteria

1. WHEN an authenticated user calls `POST /user/chat/support` with a valid `support_type` THEN `CreateSupportConversation_Action` SHALL store `support_type` on the conversation and dispatch `Auto_Assign_Job` with a 5-minute delay
2. WHEN a guest calls `POST /guest/chat/support` with a valid `support_type` THEN `CreateGuestSupportConversation_Action` SHALL store `support_type` on the conversation and dispatch `Auto_Assign_Job` with a 5-minute delay
3. IF `support_type` is provided but not one of the 4 valid values THEN the API SHALL return `422 Unprocessable Entity`
4. IF `support_type` is omitted THEN the API SHALL accept the request and store `NULL` for `support_type`
5. WHEN a support conversation is created THEN the API response SHALL include `support_type` in the conversation payload

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1, existing CreateSupportConversationAction, CreateGuestSupportConversationAction

---

### Requirement 4: Hiển Thị Loại Support Trên Danh Sách

**User Story:** As an admin, I want to see the support type badge on the support list and filter by type, so that I can quickly identify and triage support requests.

#### Acceptance Criteria

1. WHEN an admin views `/admin/chat/support` THEN `Support_List_Page` SHALL display a color-coded badge for each conversation that has a `support_type`
2. WHERE `support_type` is `document` THEN the badge SHALL use color `blue`; `shift` → `green`; `salary` → `orange`; `transportation` → `violet`
3. IF a conversation has no `support_type` THEN `Support_List_Page` SHALL not display a badge for that row
4. WHEN an admin selects a support type in the filter dropdown THEN `Support_List_Page` SHALL show only conversations of that type
5. WHEN the filter is cleared THEN `Support_List_Page` SHALL show all support conversations regardless of type
6. WHILE a support type filter is active THEN `Support_List_Page` SHALL maintain the existing status filters (unassigned/assigned/all) in combination

#### Additional Details
- **Priority**: High
- **Complexity**: Low
- **Dependencies**: Requirement 1, Requirement 3, existing support list page

---

### Requirement 5: Hiển Thị Loại Support Trên Chi Tiết

**User Story:** As an admin handling a support chat, I want to see the support type label in the support detail screen, so that I know the category of the request at a glance.

#### Acceptance Criteria

1. WHEN an admin opens a support conversation detail page THEN `Support_Detail_Page` SHALL display the support type badge in the header/info section if `support_type` is set
2. IF `support_type` is `NULL` THEN `Support_Detail_Page` SHALL not display a type badge
3. WHERE the badge is displayed THEN it SHALL use the same color scheme and localized label as in Requirement 4

#### Additional Details
- **Priority**: Medium
- **Complexity**: Low
- **Dependencies**: Requirement 4 (shared SupportTypeLabel component)

---

### Requirement 6: Tự Động Phân Công Admin

**User Story:** As an admin manager, I want the system to automatically assign admins to a support conversation after 5 minutes if no admin has joined, so that no support request is left unattended.

#### Acceptance Criteria

1. WHEN a support conversation is created THEN `Auto_Assign_Job` SHALL be dispatched with a 5-minute delay
2. WHEN `Auto_Assign_Job` runs and the conversation has no active admin participant AND `support_type` is set THEN `Auto_Assign_Job` SHALL assign all Support_Type_Admin users for that type
3. IF the conversation's `support_type` has no Support_Type_Admin assigned THEN `Auto_Assign_Job` SHALL fall back and assign all Fallback_Admins (users with Chat_Assign_Permission)
4. IF `support_type` is NULL THEN `Auto_Assign_Job` SHALL assign all Fallback_Admins
5. IF the conversation already has at least one active admin participant when the job runs THEN `Auto_Assign_Job` SHALL take no action
6. IF the conversation is closed when the job runs THEN `Auto_Assign_Job` SHALL take no action
7. IF there are no Fallback_Admins in the system THEN `Auto_Assign_Job` SHALL complete without error and without assigning anyone
8. WHEN admins are auto-assigned THEN `Auto_Assign_Job` SHALL use the same `AssignSupportAdmins_Action` as manual assignment (notifications, system messages, broadcasts)

#### Additional Details
- **Priority**: High
- **Complexity**: Medium
- **Dependencies**: Requirement 1, Requirement 3, existing AssignSupportAdminsAction, existing queue infrastructure

---

## Non-Functional Requirements

### Performance Requirements
- WHEN `Auto_Assign_Job` runs THEN it SHALL complete within 5 seconds per conversation
- WHEN loading the support list with type filter THEN the API SHALL respond within 500ms for up to 1000 conversations
- WHEN loading the Support_Type_Config_Screen THEN the API SHALL respond within 300ms

### Security Requirements
- WHEN a request to add/remove Support_Type_Admin arrives without Chat_Assign_Permission THEN the API SHALL return `403 Forbidden`
- WHEN a request to view Support_Type_Config_Screen arrives without Config_Edit_Permission THEN the frontend SHALL redirect to 403/unauthorized page
- WHEN the Auto_Assign_Job assigns admins THEN it SHALL use existing AssignSupportAdmins_Action which enforces role validation

### Usability Requirements
- WHEN a user selects a support type filter THEN the filter SHALL update the list without full page reload
- WHEN a support type badge is displayed THEN it SHALL use consistent color and label across all screens

### Reliability Requirements
- WHEN `Auto_Assign_Job` fails due to a transient error THEN Laravel queue SHALL retry the job according to the default retry policy
- WHEN duplicate add-admin requests arrive THEN the system SHALL handle them idempotently (no 500 error, no duplicate rows)

---

## Constraints and Assumptions

### Technical Constraints
- Support types are a fixed enum; adding new types requires a code change (not a runtime config)
- The job queue (Laravel queue worker) must be running for Auto_Assign_Job to execute
- Frontend uses Mantine UI v7+ for Badge component

### Assumptions
- Mobile app will be updated separately to send `support_type` in the request body
- All 4 support types are always displayed in the config screen (even if empty)
- A user can be Support_Type_Admin for multiple support types simultaneously
- The 5-minute delay is measured from conversation creation time, not from the job dispatch time (Laravel's `delay()` covers this)
- The config screen section for support types sits within the existing `/admin/configuration` page

---

## Success Criteria

### Definition of Done
- [ ] All acceptance criteria are met
- [ ] Non-functional requirements are satisfied
- [ ] `pnpm typecheck` passes with no errors
- [ ] `pnpm lint:fix` and `pnpm prettier` pass
- [ ] `npm run prettier` and `npm run pint` pass on backend
- [ ] PHPUnit tests cover: AutoAssignSupportAdminJob (6 scenarios), SupportTypeAdminController (CRUD + auth), updated CreateSupportConversation
- [ ] Translations exist for vi, en, ja for all 4 support types and new UI labels
- [ ] Feature works end-to-end: create support with type → wait 5 min (or test with short delay) → admin auto-assigned
