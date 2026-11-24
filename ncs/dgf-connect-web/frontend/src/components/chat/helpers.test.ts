import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import i18n from '@/locales/i18n';
import { ChatMessage, ChatParticipant } from '@/types/models/chat';
import { buildReadReceipts, canManagePinnedMessage, systemMessageText } from './helpers';

const t = (key: string, options?: Record<string, unknown>) => i18n.t(key, options);

describe('systemMessageText', () => {
    let initialLanguage: string;

    beforeAll(() => {
        initialLanguage = i18n.language;
    });

    afterAll(async () => {
        await i18n.changeLanguage(initialLanguage);
    });

    it('renders conversation_closed_by_admin in vi', async () => {
        await i18n.changeLanguage('vi');
        const text = systemMessageText({ body: 'chat.system.conversation_closed_by_admin', system_payload: null }, t);
        expect(text).toBe('Admin đã đóng phiên hỗ trợ');
    });

    it('renders conversation_closed_by_employee in vi', async () => {
        await i18n.changeLanguage('vi');
        const text = systemMessageText(
            { body: 'chat.system.conversation_closed_by_employee', system_payload: null },
            t,
        );
        expect(text).toBe('Nhân viên đã đóng phiên hỗ trợ');
    });

    it('renders conversation_closed_by_admin in ja', async () => {
        await i18n.changeLanguage('ja');
        const text = systemMessageText({ body: 'chat.system.conversation_closed_by_admin', system_payload: null }, t);
        expect(text).toBe('管理者がサポートセッションをクローズしました');
    });

    it('renders conversation_closed_by_employee in ja', async () => {
        await i18n.changeLanguage('ja');
        const text = systemMessageText(
            { body: 'chat.system.conversation_closed_by_employee', system_payload: null },
            t,
        );
        expect(text).toBe('従業員がサポートセッションをクローズしました');
    });

    it('renders conversation_closed_by_admin in en', async () => {
        await i18n.changeLanguage('en');
        const text = systemMessageText({ body: 'chat.system.conversation_closed_by_admin', system_payload: null }, t);
        expect(text).toBe('Admin closed this support session');
    });

    it('renders conversation_closed_by_employee in en', async () => {
        await i18n.changeLanguage('en');
        const text = systemMessageText(
            { body: 'chat.system.conversation_closed_by_employee', system_payload: null },
            t,
        );
        expect(text).toBe('Employee closed this support session');
    });

    it('still renders legacy conversation_closed key', async () => {
        await i18n.changeLanguage('vi');
        const text = systemMessageText({ body: 'chat.system.conversation_closed', system_payload: null }, t);
        expect(text).toBe('Cuộc trò chuyện đã đóng');
    });

    it('returns empty string when body is null', () => {
        const text = systemMessageText({ body: null, system_payload: null }, t);
        expect(text).toBe('');
    });
});

describe('canManagePinnedMessage', () => {
    it('allows a group leader with ChatEdit in a group chat', () => {
        expect(canManagePinnedMessage(true, true, true)).toBe(true);
    });

    it('denies a non-leader (employee/deputy) even with ChatEdit', () => {
        expect(canManagePinnedMessage(true, false, true)).toBe(false);
    });

    it('denies a group leader without ChatEdit', () => {
        expect(canManagePinnedMessage(false, true, true)).toBe(false);
    });

    it('denies pinning outside of a group chat', () => {
        expect(canManagePinnedMessage(true, true, false)).toBe(false);
    });
});

const makeMessage = (id: number, senderUserId: number | null, messageType: ChatMessage['message_type'] = 'text') =>
    ({
        id,
        conversation_id: 1,
        sender_user_id: senderUserId,
        sender_name: null,
        sender_profile_picture: null,
        client_message_id: null,
        message_type: messageType,
        body: 'hello',
        system_payload: null,
        attachments: [],
        created_at: '2026-09-17T09:00:00+09:00',
    }) as ChatMessage;

const makeParticipant = (userId: number, lastReadMessageId: number | null) =>
    ({
        user_id: userId,
        name: `User ${userId}`,
        staff_no: null,
        profile_picture: null,
        role: 'admin',
        last_read_message_id: lastReadMessageId,
        last_read_at: lastReadMessageId === null ? null : '2026-09-17T09:05:00+09:00',
        joined_at: null,
    }) as ChatParticipant;

describe('buildReadReceipts', () => {
    const messages = [makeMessage(1, 10), makeMessage(2, 10), makeMessage(3, 20)];

    it('places each reader on the last message they read', () => {
        const receipts = buildReadReceipts(messages, [makeParticipant(20, 2), makeParticipant(30, 3)], 10);

        expect(receipts.get(2)?.map((p) => p.user_id)).toEqual([20]);
        expect(receipts.get(3)?.map((p) => p.user_id)).toEqual([30]);
        expect(receipts.get(1)).toBeUndefined();
    });

    it('never shows a reader on their own message', () => {
        const receipts = buildReadReceipts(messages, [makeParticipant(20, 3)], 10);

        // Message 3 is the reader's own, so their avatar falls back to message 2.
        expect(receipts.get(3)).toBeUndefined();
        expect(receipts.get(2)?.map((p) => p.user_id)).toEqual([20]);
    });

    it('skips system messages and the current user', () => {
        const withSystem = [makeMessage(1, 10), makeMessage(2, null, 'system')];
        const receipts = buildReadReceipts(withSystem, [makeParticipant(20, 2), makeParticipant(10, 2)], 10);

        expect(receipts.get(2)).toBeUndefined();
        expect(receipts.get(1)?.map((p) => p.user_id)).toEqual([20]);
    });

    it('leaves out readers whose marker is older than the loaded page', () => {
        const receipts = buildReadReceipts(
            [makeMessage(5, 10)],
            [makeParticipant(20, 2), makeParticipant(30, null)],
            10,
        );

        expect(receipts.size).toBe(0);
    });

    it('groups several readers on the same message', () => {
        const receipts = buildReadReceipts(messages, [makeParticipant(20, 2), makeParticipant(30, 2)], 10);

        expect(receipts.get(2)?.map((p) => p.user_id)).toEqual([20, 30]);
    });
});
