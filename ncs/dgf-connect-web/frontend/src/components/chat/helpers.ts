import dayjs from 'dayjs';
import {
    ChatConversationType,
    ChatLastMessage,
    ChatMessage,
    ChatParticipant,
    ChatSystemPayload,
} from '@/types/models/chat';

// Mirrors the backend chat.attachments config defaults (config/chat.php). Kept in sync manually
// so the composer can reject oversized/too-many files before hitting the API.
export const CHAT_ATTACHMENT_MAX_FILES = 10;
export const CHAT_ATTACHMENT_MAX_SIZE_KB = 8192;
export const CHAT_ATTACHMENT_ACCEPT = '.jpg,.jpeg,.png,.webp,.heic,.pdf,.doc,.docx,.xls,.xlsx';

export const formatFileSize = (bytes: number | null | undefined) => {
    if (!bytes || bytes <= 0) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};

type ChatTranslator = (key: string, options?: Record<string, unknown>) => string;
type PreviewMessage = Pick<ChatMessage | ChatLastMessage, 'body' | 'message_type' | 'system_payload'>;

export const conversationTitle = (conversation: { title: string | null }, t: ChatTranslator) => {
    return conversation.title || t('Untitled chat');
};

// Pin/unpin is restricted to group leaders (participant role=admin) who hold ChatEdit, matching the
// backend (PinChatMessageAction/UnpinChatMessageAction gate on isActiveAdminParticipant). Keep the
// banner, message actions, and the pin handler in sync through this single predicate.
export const canManagePinnedMessage = (canEdit: boolean, isGroupLeader: boolean, isGroupChat: boolean): boolean => {
    return canEdit && isGroupLeader && isGroupChat;
};

export const conversationTypeLabel = (type: ChatConversationType, t: ChatTranslator) => {
    switch (type) {
        case 'shift_group':
            return t('Shift');
        case 'user_group':
            return t('User group');
        case 'support':
            return t('Support');
        case 'guest_support':
            return t('Guest support');
        case 'admin_group':
        default:
            return t('Group');
    }
};

export const conversationTypeBadgeColor = (type: ChatConversationType): string => {
    switch (type) {
        case 'guest_support':
            return 'teal';
        case 'support':
            return 'blue';
        default:
            return 'gray';
    }
};

export const formatChatTime = (value: string | null) => {
    if (!value) return '';
    const date = dayjs(value);
    return date.isSame(dayjs(), 'day') ? date.format('HH:mm') : date.format('YYYY/MM/DD');
};

export const formatMessageTime = (value: string | null) => {
    if (!value) return '';
    return dayjs(value).format('YYYY/MM/DD HH:mm');
};

const systemPayloadOptions = (payload: ChatSystemPayload | null | undefined, t: ChatTranslator) => {
    const rawPayload = payload ?? {};

    const chatRoleLabel = (role: unknown): string => {
        if (role === 'deputy') return t('Deputy');
        if (role === 'admin') return t('Group leader');
        return t('Employee');
    };

    return {
        ...rawPayload,
        actor_name: rawPayload.actor_name || t('System'),
        title: rawPayload.title || t('Untitled chat'),
        user_name: rawPayload.user_name || t('Unknown user'),
        ...(rawPayload.new_role !== undefined && { new_role: chatRoleLabel(rawPayload.new_role) }),
    };
};

export const systemMessageText = (
    message: Pick<ChatMessage | ChatLastMessage, 'body' | 'system_payload'>,
    t: ChatTranslator,
) => {
    return message.body ? t(message.body, systemPayloadOptions(message.system_payload, t)) : '';
};

export const messagePreview = (message: PreviewMessage | null | undefined, t: ChatTranslator) => {
    if (!message) return '';

    if (message.message_type === 'system') {
        return systemMessageText(message, t);
    }

    if (message.body) {
        return message.body;
    }

    if (message.message_type === 'image') {
        return t('Image');
    }

    if (message.message_type === 'file') {
        return t('File');
    }

    return '';
};

export const uniqueMessages = (messages: ChatMessage[]) => {
    const map = new Map<number, ChatMessage>();
    messages.forEach((message) => map.set(message.id, message));
    return Array.from(map.values()).sort((a, b) => a.id - b.id);
};

export type MessageReadReceipts = Map<number, ChatParticipant[]>;

// How many reader avatars a message shows before the rest collapse into a "+N" chip.
export const READ_RECEIPT_AVATAR_LIMIT = 4;

/**
 * Places each participant's avatar under the last message they have read, the way Messenger and
 * Zalo do: one avatar per reader, never repeated on every older message.
 *
 * Anchored to the newest loaded message at or below their read marker, skipping system rows and
 * the reader's own messages (nobody is shown as having "read" what they just wrote). Readers
 * whose marker is older than everything loaded are left out — their avatar belongs to a message
 * further up the backscroll.
 */
export const buildReadReceipts = (
    messages: ChatMessage[],
    participants: ChatParticipant[],
    currentUserId: number,
): MessageReadReceipts => {
    const receipts: MessageReadReceipts = new Map();

    participants.forEach((participant) => {
        if (participant.user_id === currentUserId) return;

        const lastReadId = participant.last_read_message_id;
        if (lastReadId == null) return;

        for (let index = messages.length - 1; index >= 0; index -= 1) {
            const message = messages[index];
            if (message.id > lastReadId) continue;
            if (message.message_type === 'system') continue;
            if (message.sender_user_id === participant.user_id) continue;

            const bucket = receipts.get(message.id);
            if (bucket) {
                bucket.push(participant);
            } else {
                receipts.set(message.id, [participant]);
            }
            return;
        }
    });

    return receipts;
};
