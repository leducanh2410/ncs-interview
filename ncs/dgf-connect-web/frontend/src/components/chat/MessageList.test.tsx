import React from 'react';
import { MantineProvider } from '@mantine/core';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatMessage } from '@/types/models/chat';
import MessageList from './MessageList';

const mockUsePermissions = vi.hoisted(() => vi.fn(() => ({ USER_LIST: true })));
vi.mock('@/hooks/usePermission', () => ({
    usePermissions: mockUsePermissions,
}));

beforeAll(() => {
    window.HTMLElement.prototype.scrollIntoView = vi.fn();
});

vi.mock('next/link', () => ({
    default: ({ children, href, ...rest }: { children: React.ReactNode; href: string; [key: string]: unknown }) =>
        React.createElement('a', { href, ...rest }, children),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/hooks', () => ({
    useReducedMotion: () => false,
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, 'aria-label': ariaLabel }: any) =>
        React.createElement('button', { onClick, 'aria-label': ariaLabel }, children),
    Anchor: ({ children, href, component: _c, size: _s, ...props }: any) =>
        React.createElement('a', { href, ...props }, children),
    Box: ({ children, ...props }: any) => React.createElement('div', props, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    MantineProvider: ({ children }: any) => React.createElement(React.Fragment, null, children),
    Modal: ({ children, opened }: any) => (opened ? React.createElement('div', null, children) : null),
    ScrollArea: ({ children }: any) => React.createElement('div', null, children),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children, span, ...props }: any) =>
        span ? React.createElement('span', props, children) : React.createElement('p', props, children),
    Tooltip: ({ children }: any) => React.createElement(React.Fragment, null, children),
}));

vi.mock('@/components/DAvatar', () => ({
    default: ({ name }: { name: string }) => React.createElement('div', { 'data-testid': 'avatar', 'data-name': name }),
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (_route: string, params: { id: number }) => `/admin/user/${params.id}`,
    },
}));

vi.mock('./ChatEmptyState', () => ({
    default: ({ message }: { message: string }) => React.createElement('div', null, message),
}));

vi.mock('./helpers', () => ({
    formatMessageTime: (v: string | null) => v ?? '',
    systemMessageText: () => '',
}));

vi.mock('@/configs/api.config', () => ({
    ApiConfig: {
        CHAT_ATTACHMENT_DOWNLOAD: '/api/attachment/:id',
        CHAT_ATTACHMENT_THUMBNAIL: '/api/attachment/:id/thumbnail',
    },
}));

vi.mock('@/helpers/api.helper', () => ({
    default: { downloadBinary: vi.fn() },
}));

vi.mock('@/helpers/common.helper', () => ({
    default: { replaceUrlParam: vi.fn() },
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showError: vi.fn() },
}));

const makeMessage = (overrides: Partial<ChatMessage> = {}): ChatMessage => ({
    id: 1,
    conversation_id: 10,
    sender_user_id: 42,
    sender_name: 'Thai KV',
    sender_profile_picture: null,
    client_message_id: null,
    message_type: 'text',
    body: 'Hello',
    system_payload: null,
    attachments: [],
    created_at: '2024-01-01T10:00:00Z',
    ...overrides,
});

describe('MessageList', () => {
    const senderNameById = new Map([[42, 'Thai KV']]);
    const senderStaffNoById = new Map<number, string | null>([[42, 'E000001']]);

    beforeEach(() => {
        mockUsePermissions.mockReset();
        mockUsePermissions.mockReturnValue({ USER_LIST: true });
    });

    it('shows sender name with staff number when senderStaffNoById is provided', () => {
        const messages = [makeMessage({ id: 1, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                senderStaffNoById={senderStaffNoById}
            />,
        );

        expect(screen.getByText('Thai KV (E000001)')).toBeInTheDocument();
    });

    it('shows sender name without staff number when staff_no is null', () => {
        const noStaffNo = new Map<number, string | null>([[42, null]]);
        const messages = [makeMessage({ id: 1, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                senderStaffNoById={noStaffNo}
            />,
        );

        expect(screen.getByText('Thai KV')).toBeInTheDocument();
        expect(screen.queryByText(/E000/)).not.toBeInTheDocument();
    });

    it('renders avatar as a link to user detail when sender_user_id is not null', () => {
        const messages = [makeMessage({ id: 1, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                senderStaffNoById={senderStaffNoById}
            />,
        );

        const link = screen.getByRole('link', { name: 'Thai KV' });
        expect(link).toHaveAttribute('href', '/admin/user/42');
    });

    it('renders avatar without a link when sender_user_id is null (guest)', () => {
        const guestNameById = new Map<number, string>();
        const messages = [makeMessage({ id: 1, sender_user_id: null, sender_name: 'Guest User' })];
        render(<MessageList messages={messages} currentUserId={99} senderNameById={guestNameById} />);

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByTestId('avatar')).toBeInTheDocument();
    });

    it('makes sender name also a link to user detail when USER_LIST permission is granted', () => {
        const messages = [makeMessage({ id: 1, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                senderStaffNoById={senderStaffNoById}
            />,
        );

        const nameLink = screen.getByRole('link', { name: 'Thai KV (E000001)' });
        expect(nameLink).toHaveAttribute('href', '/admin/user/42');
    });

    it('renders name and avatar without links when USER_LIST permission is absent', () => {
        mockUsePermissions.mockReturnValue({ USER_LIST: false });
        const messages = [makeMessage({ id: 1, sender_user_id: 42 })];
        const { container } = render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                senderStaffNoById={senderStaffNoById}
            />,
        );

        expect(screen.queryByRole('link')).not.toBeInTheDocument();
        expect(screen.getByTestId('avatar')).toBeInTheDocument();
        expect(container.textContent).toContain('Thai KV');
        expect(container.textContent).toContain('E000001');
    });

    it('shows empty state when there are no messages', () => {
        render(<MessageList messages={[]} currentUserId={99} senderNameById={new Map()} />);

        expect(screen.getByText('No messages yet')).toBeInTheDocument();
    });

    it('shows pin button for each message when isAdmin and onPinMessage are provided', () => {
        const messages = [makeMessage({ id: 1, sender_user_id: 42 }), makeMessage({ id: 2, sender_user_id: 42 })];
        const onPinMessage = vi.fn();
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                isAdmin={true}
                onPinMessage={onPinMessage}
            />,
        );

        expect(screen.getAllByRole('button', { name: 'Pin message' })).toHaveLength(2);
    });

    it('hides pin button when isAdmin is false', () => {
        const messages = [makeMessage({ id: 1, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                isAdmin={false}
                onPinMessage={vi.fn()}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Pin message' })).not.toBeInTheDocument();
    });

    it('hides pin button when onPinMessage is not provided', () => {
        const messages = [makeMessage({ id: 1, sender_user_id: 42 })];
        render(<MessageList messages={messages} currentUserId={99} senderNameById={senderNameById} isAdmin={true} />);

        expect(screen.queryByRole('button', { name: 'Pin message' })).not.toBeInTheDocument();
    });

    it('calls onPinMessage with the correct message id when pin button is clicked', async () => {
        const onPinMessage = vi.fn();
        const messages = [makeMessage({ id: 7, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                isAdmin={true}
                onPinMessage={onPinMessage}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Pin message' }));

        expect(onPinMessage).toHaveBeenCalledOnce();
        expect(onPinMessage).toHaveBeenCalledWith(7);
    });

    it('confirms before calling onDeleteMessage with the correct message id', async () => {
        const onDeleteMessage = vi.fn().mockResolvedValue(undefined);
        const messages = [makeMessage({ id: 7, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                canDeleteMessage={true}
                onDeleteMessage={onDeleteMessage}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Delete message' }));

        expect(
            screen.getByText('This message will be permanently deleted and cannot be recovered.'),
        ).toBeInTheDocument();

        await userEvent.click(screen.getByRole('button', { name: 'Delete' }));

        await waitFor(() => {
            expect(onDeleteMessage).toHaveBeenCalledOnce();
            expect(onDeleteMessage).toHaveBeenCalledWith(7);
        });
    });

    it('keeps the delete confirmation open when onDeleteMessage rejects', async () => {
        const onDeleteMessage = vi.fn().mockRejectedValue(new Error('delete failed'));
        const messages = [makeMessage({ id: 7, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                canDeleteMessage={true}
                onDeleteMessage={onDeleteMessage}
            />,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Delete message' }));
        await userEvent.click(screen.getByRole('button', { name: 'Delete' }));

        await waitFor(() => expect(onDeleteMessage).toHaveBeenCalledOnce());
        expect(
            screen.getByText('This message will be permanently deleted and cannot be recovered.'),
        ).toBeInTheDocument();
    });

    it('hides delete button unless the viewer can delete messages', () => {
        const messages = [makeMessage({ id: 7, sender_user_id: 42 })];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={senderNameById}
                canDeleteMessage={false}
                onDeleteMessage={vi.fn()}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Delete message' })).not.toBeInTheDocument();
    });

    it('does not show pin button for system messages', () => {
        const messages = [
            makeMessage({ id: 1, message_type: 'system', sender_user_id: null, sender_name: null, body: null }),
        ];
        render(
            <MessageList
                messages={messages}
                currentUserId={99}
                senderNameById={new Map()}
                isAdmin={true}
                onPinMessage={vi.fn()}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Pin message' })).not.toBeInTheDocument();
    });
});

// URL detection tests use guest messages (sender_user_id: null) so no avatar link
// interferes with link-count assertions.
const makeUrlMessage = (body: string): ChatMessage => makeMessage({ sender_user_id: null, sender_name: 'Alice', body });

const renderList = (messages: ChatMessage[], currentUserId = 1) =>
    render(
        <MantineProvider>
            <MessageList messages={messages} currentUserId={currentUserId} senderNameById={new Map()} />
        </MantineProvider>,
    );

describe('MessageList — URL detection', () => {
    it('renders plain text without any links', () => {
        renderList([makeUrlMessage('Hello world')]);

        expect(screen.queryByRole('link')).toBeNull();
    });

    it('converts an https URL into a clickable link', () => {
        renderList([makeUrlMessage('https://example.com')]);

        const link = screen.getByRole('link', { name: 'https://example.com' });
        expect(link).toHaveAttribute('href', 'https://example.com');
        expect(link).toHaveAttribute('target', '_blank');
        expect(link).toHaveAttribute('rel', 'noopener noreferrer');
    });

    it('converts an http URL into a clickable link', () => {
        renderList([makeUrlMessage('http://example.com/page')]);

        const link = screen.getByRole('link', { name: 'http://example.com/page' });
        expect(link).toHaveAttribute('href', 'http://example.com/page');
    });

    it('strips a trailing period from the URL href', () => {
        renderList([makeUrlMessage('See https://example.com.')]);

        const link = screen.getByRole('link', { name: 'https://example.com' });
        expect(link).toHaveAttribute('href', 'https://example.com');
    });

    it('strips multiple trailing punctuation characters from the URL', () => {
        renderList([makeUrlMessage('Link: https://example.com/path,')]);

        const link = screen.getByRole('link', { name: 'https://example.com/path' });
        expect(link).toHaveAttribute('href', 'https://example.com/path');
    });

    it('renders all URLs in a message with multiple links', () => {
        renderList([makeUrlMessage('Go to https://foo.com or https://bar.com/page')]);

        const links = screen.getAllByRole('link');
        expect(links).toHaveLength(2);
        expect(links[0]).toHaveAttribute('href', 'https://foo.com');
        expect(links[1]).toHaveAttribute('href', 'https://bar.com/page');
    });

    it('preserves text around a URL in the rendered output', () => {
        const { container } = renderList([makeUrlMessage('Visit https://example.com now')]);

        const link = screen.getByRole('link', { name: 'https://example.com' });
        expect(link).toBeInTheDocument();

        expect(container.textContent).toContain('Visit');
        expect(container.textContent).toContain('now');
    });

    it('does not linkify non-URL text that contains dots', () => {
        renderList([makeUrlMessage('version 1.2.3 is out')]);

        expect(screen.queryByRole('link')).toBeNull();
    });

    it('handles a URL with query string and fragment', () => {
        renderList([makeUrlMessage('https://example.com/search?q=foo&page=2#results')]);

        const link = screen.getByRole('link', {
            name: 'https://example.com/search?q=foo&page=2#results',
        });
        expect(link).toHaveAttribute('href', 'https://example.com/search?q=foo&page=2#results');
    });
});
