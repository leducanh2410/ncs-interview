export type ChatBannedKeywordAction = 'block' | 'mask';

export interface ChatBannedKeyword {
    id: number;
    keyword: string;
    locale: string | null;
    action: ChatBannedKeywordAction;
    is_active: boolean;
    created_at: string | null;
    updated_at: string | null;
}

export type ChatConversationType = 'admin_group' | 'shift_group' | 'user_group' | 'support' | 'guest_support';
export type ChatConversationStatus = 'open' | 'closed';
export type ChatParticipantRole = 'admin' | 'deputy' | 'employee' | 'system';
export type ChatMessageType = 'text' | 'image' | 'file' | 'system';
export type ChatMessageAttachmentType = 'image' | 'file';
export type ChatSystemPayload = Record<string, string | number | boolean | null | undefined>;

export interface ChatMessageAttachment {
    id: number;
    attachment_id: number;
    type: ChatMessageAttachmentType;
    url: string | null;
    thumbnail_url: string | null;
    filename: string | null;
    mime_type: string | null;
    size_bytes: number | null;
    position: number;
}

export interface ChatMessage {
    id: number;
    conversation_id: number;
    sender_user_id: number | null;
    sender_name: string | null;
    sender_profile_picture: string | null;
    client_message_id: string | null;
    message_type: ChatMessageType;
    body: string | null;
    system_payload: ChatSystemPayload | null;
    attachments: ChatMessageAttachment[];
    created_at: string | null;
    // Present on send/idempotent-retry responses: the mask keywords that were replaced with '***'.
    masked_keywords?: string[];
}

export interface ChatLastMessage {
    id: number;
    message_type: ChatMessageType;
    body: string | null;
    system_payload: ChatSystemPayload | null;
    sender_user_id: number | null;
    sender_name: string | null;
    sender_profile_picture: string | null;
    created_at: string | null;
}

export type ChatSystemConversationKey = 'admin_internal';

export interface ChatConversationListItem {
    id: number;
    type: ChatConversationType;
    // Set on conversations the system owns and syncs by itself (the internal admin group).
    // Such a conversation cannot be deleted, closed, or have its roster edited by hand.
    system_key: ChatSystemConversationKey | null;
    status: ChatConversationStatus;
    title: string | null;
    participant_count: number;
    unread_count: number;
    last_message_at: string | null;
    last_message: ChatLastMessage | null;
}

export interface ChatParticipant {
    user_id: number;
    name: string | null;
    staff_no: string | null;
    profile_picture: string | null;
    role: ChatParticipantRole;
    // Admin-only badge flag: present on admin conversation-detail / participant-list responses,
    // omitted from the employee-facing endpoints and the assign-role mutation payload.
    is_global_admin?: boolean;
    // Read markers: admin conversation-detail only, used to draw per-message read receipts.
    last_read_message_id?: number | null;
    last_read_at?: string | null;
    joined_at: string | null;
}

export interface ChatReadReceiptUser {
    user_id: number;
    name: string | null;
    staff_no: string | null;
    profile_picture: string | null;
    role: ChatParticipantRole;
    seen_at?: string | null;
}

export interface ChatMessageReadReceipts {
    message_id: number;
    seen_count: number;
    total_count: number;
    seen: ChatReadReceiptUser[];
    unseen: ChatReadReceiptUser[];
}

export interface ChatConversationDetail extends ChatConversationListItem {
    support_type?: SupportType | null;
    // Null on a shift_group means its shift was deleted; such a chat stays closed for good.
    shift_id: number | null;
    closed_by_role: 'admin' | 'deputy' | 'employee' | 'guest' | null;
    permissions: {
        employee_can_send_message: boolean;
        employee_can_send_image: boolean;
        employee_can_send_file: boolean;
        can_employee_reopen: boolean;
    };
    self: {
        role: ChatParticipantRole;
        unread_count: number;
        last_read_message_id: number | null;
        last_read_at: string | null;
    };
    extra_participant_user_ids: number[];
    last_message_id: number | null;
    pinned_message: ChatMessage | null;
    participants: ChatParticipant[];
    guest_info: ChatGuestInfo | null;
}

export interface PinMessageResult {
    conversation_id: number;
    pinned_message: ChatMessage | null;
}

export interface ChatMessagesResponse {
    messages: ChatMessage[];
    has_more: boolean;
    oldest_id: number | null;
    newest_id: number | null;
}

export interface ChatEmployeePermissions {
    employee_can_send_message: boolean;
    employee_can_send_image: boolean;
    employee_can_send_file: boolean;
}

interface CreateChatConversationBase extends Partial<ChatEmployeePermissions> {
    title?: string | null;
}

export interface CreateAdminGroupPayload extends CreateChatConversationBase {
    type: 'admin_group';
    participant_user_ids: number[];
}

export interface CreateShiftGroupPayload extends CreateChatConversationBase {
    type: 'shift_group';
    shift_id: number;
}

export interface CreateUserGroupPayload extends CreateChatConversationBase {
    type: 'user_group';
    user_group_id: number;
}

export type CreateChatConversationPayload = CreateAdminGroupPayload | CreateShiftGroupPayload | CreateUserGroupPayload;

export interface UpdateEmployeePermissionsPayload extends ChatEmployeePermissions {}

export interface CloseConversationPayload {
    close_reason?: string | null;
}

export interface SendChatMessagePayload {
    body?: string;
    client_message_id?: string;
    files?: File[];
}

export type ChatSupportStatusFilter = 'unassigned' | 'assigned' | 'all';

export type SupportType = 'document' | 'shift' | 'salary' | 'transportation' | 'other';

export interface SupportTypeAdminUser {
    user_id: number;
    name: string | null;
    staff_no: string | null;
    profile_picture: string | null;
}

export interface SupportTypeConfig {
    type: SupportType;
    admins: SupportTypeAdminUser[];
}

export interface ChatGuestInfo {
    name: string;
    email: string;
    device_id: string;
    platform: string;
}

export interface ChatSupportConversationListItem {
    id: number;
    type: ChatConversationType;
    status: ChatConversationStatus;
    support_type: SupportType | null;
    title: string | null;
    created_by_user_id: number | null;
    created_by_name: string | null;
    is_guest: boolean;
    guest_name: string | null;
    guest_email: string | null;
    participant_count: number;
    last_message_at: string | null;
    last_message: ChatLastMessage | null;
}

export interface AssignSupportAdminsPayload {
    admin_user_ids: number[];
}

export interface SupportAdminsResult {
    conversation_id: number;
    assigned_user_ids?: number[];
    removed_user_ids?: number[];
}

export interface AddChatParticipantsPayload {
    user_ids: number[];
}

export interface AssignParticipantRolePayload {
    role: 'deputy' | 'employee';
}

export interface ChatParticipantsPaginatedResponse {
    data: ChatParticipant[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
}

export interface ChatParticipantsResult {
    conversation_id: number;
    added_user_ids?: number[];
    removed_user_id?: number;
}

export interface CreatedChatConversation {
    id: number;
    type: ChatConversationType;
    status: ChatConversationStatus;
    title: string | null;
    created_by_user_id: number;
    participants: Array<{ user_id: number; name: string | null; role: ChatParticipantRole; status: string }>;
}
