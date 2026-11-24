import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import AdminChatPortal from '@/components/AdminChatPortal';

const {
    chatIntentBox,
    setChatIntentMock,
    useAdminChatConversationsQueryMock,
    useAdminChatConversationQueryMock,
    useAdminChatMessagesQueryMock,
    useCreateAdminChatConversationMutationMock,
    useDeleteAdminChatConversationMutationMock,
    useDeleteAdminChatMessageMutationMock,
    useMarkAdminChatReadMutationMock,
    usePinAdminChatMessageMutationMock,
    useRenameAdminChatConversationMutationMock,
    useReopenAdminChatConversationMutationMock,
    useSendAdminChatMessageMutationMock,
    useUnpinAdminChatMessageMutationMock,
} = vi.hoisted(() => {
    const box: { current: unknown } = { current: null };
    return {
        chatIntentBox: box,
        setChatIntentMock: vi.fn((next: unknown) => {
            box.current = typeof next === 'function' ? (next as (prev: unknown) => unknown)(box.current) : next;
        }),
        useAdminChatConversationsQueryMock: vi.fn(),
        useAdminChatConversationQueryMock: vi.fn(),
        useAdminChatMessagesQueryMock: vi.fn(),
        useCreateAdminChatConversationMutationMock: vi.fn(),
        useDeleteAdminChatConversationMutationMock: vi.fn(),
        useDeleteAdminChatMessageMutationMock: vi.fn(),
        useMarkAdminChatReadMutationMock: vi.fn(),
        usePinAdminChatMessageMutationMock: vi.fn(),
        useRenameAdminChatConversationMutationMock: vi.fn(),
        useReopenAdminChatConversationMutationMock: vi.fn(),
        useSendAdminChatMessageMutationMock: vi.fn(),
        useUnpinAdminChatMessageMutationMock: vi.fn(),
    };
});

const FAKE_AUTH = {
    user: { id: 1, name: 'Admin' },
    permissions: ['chat.view', 'chat.create', 'chat.edit', 'chat.delete_message', 'chat.delete_conversation'],
    roles: [],
};

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('jotai', () => ({
    atom: (init: unknown) => ({ init }),
    useAtomValue: (a: unknown) => (a === 'AUTH_ATOM' ? FAKE_AUTH : chatIntentBox.current),
    useAtom: (a: unknown) => (a === 'CHAT_INTENT_ATOM' ? [chatIntentBox.current, setChatIntentMock] : [null, vi.fn()]),
}));

vi.mock('@/pages/global.state', () => ({
    authAtom: 'AUTH_ATOM',
    chatIntentAtom: 'CHAT_INTENT_ATOM',
}));

vi.mock('@tanstack/react-query', () => ({
    useQueryClient: () => ({ invalidateQueries: vi.fn() }),
}));

vi.mock('@/helpers/chat_echo.helper', () => ({
    getChatEcho: () => null,
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: vi.fn(), showError: vi.fn(), showInfo: vi.fn() },
}));

vi.mock('@/components/DAvatar', () => ({
    default: ({ name }: { name: string }) => React.createElement('span', null, name),
}));

vi.mock('@/components/DUserSelectModal', () => ({
    default: () => null,
}));

vi.mock('@/components/chat/MessageList', () => ({
    default: () => React.createElement('div', null, 'messages'),
}));

vi.mock('@/components/chat/MessageComposer', () => ({
    default: () => React.createElement('div', null, 'composer'),
}));

vi.mock('@/components/chat/ParticipantsDrawer', () => ({
    default: () => null,
}));

vi.mock('@/components/chat/PinnedMessageBanner', () => ({
    default: () => null,
}));

vi.mock('@/components/chat/CloseConversationModal', () => ({
    default: () => null,
}));

vi.mock('@/components/chat/EmployeePermissionsModal', () => ({
    default: () => null,
}));

vi.mock('@/pages/admin/group/api', () => ({
    useGetGroupsQuery: () => ({ data: undefined, isLoading: false }),
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useGetActiveShiftsQuery: () => ({ data: undefined, isLoading: false }),
}));

vi.mock('@/utils/group_display_name', () => ({
    getGroupDisplayName: (group: { name?: string }) => group?.name ?? '',
}));

vi.mock('@/components/chat/api', () => ({
    ADMIN_CHAT_CONVERSATIONS_QUERY_KEY: 'admin-chat-conversations',
    ADMIN_CHAT_CONVERSATION_QUERY_KEY: 'admin-chat-conversation',
    ADMIN_CHAT_MESSAGES_QUERY_KEY: 'admin-chat-messages',
    fetchAdminChatMessages: vi.fn(),
    useAdminChatConversationsQuery: (...args: unknown[]) => useAdminChatConversationsQueryMock(...args),
    useAdminChatConversationQuery: (...args: unknown[]) => useAdminChatConversationQueryMock(...args),
    useAdminChatMessagesQuery: (...args: unknown[]) => useAdminChatMessagesQueryMock(...args),
    useAdminChatMessageReceiptsQuery: () => ({ data: undefined, isLoading: false }),
    useCreateAdminChatConversationMutation: () => useCreateAdminChatConversationMutationMock(),
    useDeleteAdminChatConversationMutation: () => useDeleteAdminChatConversationMutationMock(),
    useDeleteAdminChatMessageMutation: () => useDeleteAdminChatMessageMutationMock(),
    useMarkAdminChatReadMutation: () => useMarkAdminChatReadMutationMock(),
    usePinAdminChatMessageMutation: () => usePinAdminChatMessageMutationMock(),
    useRenameAdminChatConversationMutation: () => useRenameAdminChatConversationMutationMock(),
    useReopenAdminChatConversationMutation: () => useReopenAdminChatConversationMutationMock(),
    useSendAdminChatMessageMutation: () => useSendAdminChatMessageMutationMock(),
    useUnpinAdminChatMessageMutation: () => useUnpinAdminChatMessageMutationMock(),
}));

vi.mock('@mantine/hooks', () => ({
    useDebouncedValue: (value: string) => [value],
    useDisclosure: (initial = false) => {
        let opened = initial;
        const open = () => {
            opened = true;
        };
        const close = () => {
            opened = false;
        };
        return [opened, { open, close, toggle: () => (opened = !opened) }];
    },
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, 'aria-label': ariaLabel }: any) =>
        React.createElement('button', { 'type': 'button', onClick, 'aria-label': ariaLabel }, children),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Divider: ({ label }: any) => React.createElement('div', null, label),
    Drawer: ({ children, opened }: any) => (opened ? React.createElement('div', null, children) : null),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', null, 'loading'),
    Menu: Object.assign(({ children }: any) => React.createElement('div', null, children), {
        Target: ({ children }: any) => children,
        Dropdown: ({ children }: any) => React.createElement('div', null, children),
        Divider: () => React.createElement('hr'),
        Item: ({ children, onClick }: any) =>
            React.createElement('button', { type: 'button', role: 'menuitem', onClick }, children),
    }),
    Modal: ({ children, opened }: any) => (opened ? React.createElement('div', null, children) : null),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    ScrollArea: ({ children }: any) => React.createElement('div', null, children),
    Select: ({ label, placeholder }: any) => React.createElement('div', null, label, placeholder),
    SegmentedControl: ({ value, onChange, data }: any) =>
        React.createElement(
            'div',
            null,
            data.map((opt: any) =>
                React.createElement(
                    'button',
                    {
                        'key': opt.value,
                        'type': 'button',
                        'onClick': () => onChange(opt.value),
                        'aria-pressed': value === opt.value,
                    },
                    opt.label,
                ),
            ),
        ),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Switch: ({ label, checked, onChange }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { type: 'checkbox', checked, onChange }),
        ),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ value, onChange, label, placeholder }: any) =>
        React.createElement(
            'label',
            null,
            label,
            React.createElement('input', { 'type': 'text', 'aria-label': label || placeholder, value, onChange }),
        ),
    UnstyledButton: ({ children, onClick }: any) =>
        React.createElement('button', { type: 'button', onClick }, children),
}));

describe('AdminChatPortal chat intent handling', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        chatIntentBox.current = null;
        useAdminChatConversationsQueryMock.mockReturnValue({
            data: undefined,
            isLoading: false,
            isError: false,
            refetch: vi.fn(),
        });
        useAdminChatConversationQueryMock.mockReturnValue({
            data: undefined,
            isLoading: true,
            isError: false,
            refetch: vi.fn(),
        });
        useAdminChatMessagesQueryMock.mockReturnValue({ data: undefined, isLoading: true, isError: false });
        useCreateAdminChatConversationMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useDeleteAdminChatConversationMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useDeleteAdminChatMessageMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useMarkAdminChatReadMutationMock.mockReturnValue({ mutate: vi.fn(), isPending: false });
        usePinAdminChatMessageMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useRenameAdminChatConversationMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useReopenAdminChatConversationMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useSendAdminChatMessageMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
        useUnpinAdminChatMessageMutationMock.mockReturnValue({ mutateAsync: vi.fn(), isPending: false });
    });

    it('applies an open intent by selecting the conversation and clears the atom', async () => {
        chatIntentBox.current = { kind: 'open', conversationId: 42 };

        render(<AdminChatPortal opened onClose={vi.fn()} />);

        await waitFor(() => {
            expect(useAdminChatConversationQueryMock).toHaveBeenCalledWith(42, true);
        });
        expect(setChatIntentMock).toHaveBeenCalledWith(null);
    });

    const buildClosedShiftGroupDetail = (shiftId: number | null) => ({
        id: 42,
        type: 'shift_group',
        status: 'closed',
        title: 'Shift chat',
        shift_id: shiftId,
        participant_count: 1,
        unread_count: 0,
        last_message_at: null,
        last_message: null,
        closed_by_role: null,
        permissions: {
            employee_can_send_message: true,
            employee_can_send_image: true,
            employee_can_send_file: true,
            can_employee_reopen: false,
        },
        self: { role: 'admin', unread_count: 0, last_read_message_id: null, last_read_at: null },
        extra_participant_user_ids: [],
        last_message_id: null,
        pinned_message: null,
        participants: [
            { user_id: 1, name: 'Admin', staff_no: '001', profile_picture: null, role: 'admin', joined_at: null },
        ],
        guest_info: null,
    });

    const renderClosedShiftGroup = (shiftId: number | null) => {
        chatIntentBox.current = { kind: 'open', conversationId: 42 };
        useAdminChatConversationQueryMock.mockReturnValue({
            data: { data: buildClosedShiftGroupDetail(shiftId) },
            isLoading: false,
            isError: false,
            refetch: vi.fn(),
        });
        useAdminChatMessagesQueryMock.mockReturnValue({
            data: { data: { messages: [], has_more: false, oldest_id: null, newest_id: null } },
            isLoading: false,
            isError: false,
        });
        render(<AdminChatPortal opened onClose={vi.fn()} />);
    };

    it('lets an admin reopen a closed shift group that still belongs to its shift', async () => {
        renderClosedShiftGroup(7);

        expect(await screen.findByText('Closed')).toBeInTheDocument();
        expect(screen.getByRole('menuitem', { name: 'Reopen chat' })).toBeInTheDocument();
    });

    it('hides the reopen control for a shift group whose shift was deleted', async () => {
        renderClosedShiftGroup(null);

        expect(await screen.findByText('Closed (shift deleted)')).toBeInTheDocument();
        expect(screen.queryByRole('menuitem', { name: 'Reopen chat' })).not.toBeInTheDocument();
        expect(screen.queryByRole('menuitem', { name: 'Close chat' })).not.toBeInTheDocument();
    });

    it('applies a create-direct intent by prefilling participants and an editable title, then clears the atom', async () => {
        chatIntentBox.current = {
            kind: 'create-direct',
            user: { id: 7, name: 'Employee Nguyen', staff_no: '007' },
            defaultTitle: 'Chat with Employee Nguyen',
        };

        render(<AdminChatPortal opened onClose={vi.fn()} />);

        expect(await screen.findByText('Create chat')).toBeInTheDocument();
        expect(screen.getByDisplayValue('Chat with Employee Nguyen')).toBeInTheDocument();
        expect(screen.getAllByText('Employee Nguyen').length).toBeGreaterThan(0);
        expect(screen.getByText('007')).toBeInTheDocument();

        await waitFor(() => {
            expect(setChatIntentMock).toHaveBeenCalledWith(null);
        });
    });
});
