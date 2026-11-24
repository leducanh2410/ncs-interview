import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatParticipant } from '@/types/models/chat';
import ParticipantsDrawer from './ParticipantsDrawer';

const mockAddMutation = vi.hoisted(() => ({ mutateAsync: vi.fn() }));
const mockRemoveMutation = vi.hoisted(() => ({ mutateAsync: vi.fn() }));
const mockAssignRoleMutation = vi.hoisted(() => ({ mutateAsync: vi.fn() }));
const mockParticipantsQuery = vi.hoisted(() => ({
    data: null as unknown,
    isSuccess: false,
    isLoading: false,
    isFetching: false,
    isError: false,
    refetch: vi.fn(),
}));

vi.mock('@/components/chat/api', () => ({
    useAddChatParticipantsMutation: () => mockAddMutation,
    useRemoveChatParticipantMutation: () => mockRemoveMutation,
    useAssignChatParticipantRoleMutation: () => mockAssignRoleMutation,
    useAdminChatParticipantsQuery: () => mockParticipantsQuery,
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/hooks', () => ({
    useDebouncedValue: (value: string) => [value],
    useDisclosure: (initial = false) => {
        const [open, setOpen] = React.useState(initial);
        return [open, { open: () => setOpen(true), close: () => setOpen(false) }];
    },
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, 'aria-label': label, loading }: any) =>
        React.createElement(
            'button',
            { onClick, 'aria-label': label, 'disabled': loading, 'data-loading': loading },
            children,
        ),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Button: ({ children, onClick, loading }: any) =>
        React.createElement('button', { onClick, disabled: loading }, children),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Drawer: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'drawer' }, title, children) : null,
    Group: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    TextInput: ({ value, onChange, placeholder, rightSection }: any) =>
        React.createElement(
            'div',
            null,
            React.createElement('input', {
                'data-testid': 'search-input',
                value,
                onChange,
                placeholder,
            }),
            rightSection,
        ),
}));

vi.mock('@/components/DAvatar', () => ({
    default: ({ name }: { name: string }) => React.createElement('div', { 'data-testid': 'avatar', 'data-name': name }),
}));

vi.mock('@/components/DUserSelectModal', () => ({
    default: () => null,
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: vi.fn(), showError: vi.fn() },
}));

vi.mock('react-icons/lu', () => ({
    LuRefreshCw: () => null,
    LuSearch: () => null,
    LuShield: () => React.createElement('span', { 'data-testid': 'shield-icon' }),
    LuShieldOff: () => React.createElement('span', { 'data-testid': 'shield-off-icon' }),
    LuUserPlus: () => null,
    LuX: () => null,
}));

const makeParticipant = (overrides: Partial<ChatParticipant> = {}): ChatParticipant => ({
    user_id: 1,
    name: 'Test User',
    staff_no: 'EMP001',
    profile_picture: null,
    role: 'employee',
    is_global_admin: false,
    joined_at: null,
    ...overrides,
});

const defaultProps = {
    opened: true,
    onClose: vi.fn(),
    participants: [] as ChatParticipant[],
    currentUserId: 99,
    conversationId: 1,
    canManage: true,
};

beforeEach(() => {
    vi.clearAllMocks();
    mockParticipantsQuery.data = null;
    mockParticipantsQuery.isSuccess = false;
    mockParticipantsQuery.isLoading = false;
    mockParticipantsQuery.isFetching = false;
    mockParticipantsQuery.isError = false;
});

describe('ParticipantsDrawer role badges', () => {
    it('shows Group leader badge for admin participant', () => {
        const participants = [makeParticipant({ user_id: 2, role: 'admin', name: 'Admin User' })];

        render(<ParticipantsDrawer {...defaultProps} canManage={false} participants={participants} />);

        expect(screen.getByText('Group leader')).toBeTruthy();
    });

    it('shows Deputy badge for deputy participant', () => {
        const participants = [makeParticipant({ user_id: 2, role: 'deputy', name: 'Deputy User' })];

        render(<ParticipantsDrawer {...defaultProps} canManage={false} participants={participants} />);

        expect(screen.getByText('Deputy')).toBeTruthy();
    });

    it('does not show a role badge for employee participant', () => {
        const participants = [makeParticipant({ user_id: 2, role: 'employee', name: 'Regular User' })];

        render(<ParticipantsDrawer {...defaultProps} canManage={false} participants={participants} />);

        expect(screen.queryByText('Group leader')).toBeNull();
        expect(screen.queryByText('Deputy')).toBeNull();
        expect(screen.queryByText('Global admin')).toBeNull();
    });

    it('shows Global admin badge for a global admin participant', () => {
        const participants = [
            makeParticipant({ user_id: 2, role: 'employee', name: 'Admin User', is_global_admin: true }),
        ];

        render(<ParticipantsDrawer {...defaultProps} canManage={false} participants={participants} />);

        expect(screen.getByText('Global admin')).toBeTruthy();
        expect(screen.queryByText('Group leader')).toBeNull();
    });

    it('shows both Group leader and Global admin badges when a participant is both', () => {
        const participants = [
            makeParticipant({ user_id: 2, role: 'admin', name: 'Leader Admin', is_global_admin: true }),
        ];

        render(<ParticipantsDrawer {...defaultProps} canManage={false} participants={participants} />);

        expect(screen.getByText('Group leader')).toBeTruthy();
        expect(screen.getByText('Global admin')).toBeTruthy();
    });

    it('shows the staff number under the participant name', () => {
        const participants = [makeParticipant({ user_id: 2, name: 'Named User', staff_no: 'EMP123' })];

        render(<ParticipantsDrawer {...defaultProps} canManage={false} participants={participants} />);

        expect(screen.getByText('EMP123')).toBeTruthy();
    });
});

describe('ParticipantsDrawer deputy actions', () => {
    beforeEach(() => {
        mockAssignRoleMutation.mutateAsync.mockResolvedValue({});
        mockRemoveMutation.mutateAsync.mockResolvedValue({});
    });

    it('shows assign deputy button for employee when canManageDeputy', () => {
        const participants = [makeParticipant({ user_id: 2, role: 'employee', name: 'Regular User' })];

        render(<ParticipantsDrawer {...defaultProps} participants={participants} canManageDeputy />);

        expect(screen.getByLabelText('Assign deputy')).toBeTruthy();
    });

    it('shows revoke deputy button for deputy when canManageDeputy', () => {
        const participants = [makeParticipant({ user_id: 2, role: 'deputy', name: 'Deputy User' })];

        render(<ParticipantsDrawer {...defaultProps} participants={participants} canManageDeputy />);

        expect(screen.getByLabelText('Revoke deputy')).toBeTruthy();
    });

    it('does not show assign/revoke deputy button for admin', () => {
        const participants = [makeParticipant({ user_id: 2, role: 'admin', name: 'Admin User' })];

        render(<ParticipantsDrawer {...defaultProps} participants={participants} canManageDeputy />);

        expect(screen.queryByLabelText('Assign deputy')).toBeNull();
        expect(screen.queryByLabelText('Revoke deputy')).toBeNull();
    });

    it('calls assignRoleMutation with deputy when assign deputy clicked', async () => {
        const participants = [makeParticipant({ user_id: 2, role: 'employee', name: 'Regular User' })];
        render(<ParticipantsDrawer {...defaultProps} participants={participants} canManageDeputy />);

        fireEvent.click(screen.getByLabelText('Assign deputy'));

        await waitFor(() => {
            expect(mockAssignRoleMutation.mutateAsync).toHaveBeenCalledWith({ userId: 2, role: 'deputy' });
        });
    });

    it('calls assignRoleMutation with employee when revoke deputy clicked', async () => {
        const participants = [makeParticipant({ user_id: 2, role: 'deputy', name: 'Deputy User' })];
        render(<ParticipantsDrawer {...defaultProps} participants={participants} canManageDeputy />);

        fireEvent.click(screen.getByLabelText('Revoke deputy'));

        await waitFor(() => {
            expect(mockAssignRoleMutation.mutateAsync).toHaveBeenCalledWith({ userId: 2, role: 'employee' });
        });
    });

    it('does not show remove button for deputy participant', () => {
        const participants = [makeParticipant({ user_id: 2, role: 'deputy', name: 'Deputy User' })];

        render(<ParticipantsDrawer {...defaultProps} participants={participants} canManageDeputy />);

        expect(screen.queryByLabelText('Remove from chat')).toBeNull();
    });
});

describe('ParticipantsDrawer pagination', () => {
    it('shows load more button when has more pages', () => {
        mockParticipantsQuery.isSuccess = true;
        mockParticipantsQuery.data = {
            data: {
                data: [makeParticipant({ user_id: 2, name: 'User' })],
                current_page: 1,
                last_page: 2,
                per_page: 20,
                total: 25,
            },
        };

        render(<ParticipantsDrawer {...defaultProps} participants={[]} />);

        expect(screen.getByText('Load more')).toBeTruthy();
    });

    it('does not show load more button on last page', () => {
        mockParticipantsQuery.isSuccess = true;
        mockParticipantsQuery.data = {
            data: {
                data: [makeParticipant({ user_id: 2, name: 'User' })],
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 1,
            },
        };

        render(<ParticipantsDrawer {...defaultProps} participants={[]} />);

        expect(screen.queryByText('Load more')).toBeNull();
    });

    it('renders fetched participants instead of the empty state when query succeeds', () => {
        mockParticipantsQuery.isSuccess = true;
        mockParticipantsQuery.data = {
            data: {
                data: [makeParticipant({ user_id: 2, name: 'Fetched User' })],
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 1,
            },
        };

        render(<ParticipantsDrawer {...defaultProps} participants={[]} />);

        expect(screen.getByText('Fetched User')).toBeTruthy();
        expect(screen.queryByText('No participants found')).toBeNull();
    });

    it('shows the empty state when the query genuinely returns no participants', () => {
        mockParticipantsQuery.isSuccess = true;
        mockParticipantsQuery.data = {
            data: {
                data: [],
                current_page: 1,
                last_page: 1,
                per_page: 20,
                total: 0,
            },
        };

        render(<ParticipantsDrawer {...defaultProps} participants={[]} />);

        expect(screen.getByText('No participants found')).toBeTruthy();
    });
});

describe('ParticipantsDrawer scoped participant management', () => {
    it('shows remove button for any employee participant in admin_group', () => {
        render(
            <ParticipantsDrawer
                {...defaultProps}
                conversationType="admin_group"
                participants={[makeParticipant({ user_id: 2 })]}
            />,
        );

        expect(screen.getByRole('button', { name: 'Remove from chat' })).toBeTruthy();
    });

    it('does not show remove button for admin participant in admin_group', () => {
        render(
            <ParticipantsDrawer
                {...defaultProps}
                conversationType="admin_group"
                participants={[makeParticipant({ user_id: 2, role: 'admin' })]}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Remove from chat' })).toBeNull();
    });

    it('shows Add people label for admin_group', () => {
        render(<ParticipantsDrawer {...defaultProps} conversationType="admin_group" participants={[]} />);

        expect(screen.getByRole('button', { name: 'Add people' })).toBeTruthy();
    });

    it('shows remove button only for extra participants in shift_group', () => {
        const extra = makeParticipant({ user_id: 10 });
        const scoped = makeParticipant({ user_id: 20 });

        render(
            <ParticipantsDrawer
                {...defaultProps}
                conversationType="shift_group"
                participants={[extra, scoped]}
                extraParticipantUserIds={[10]}
            />,
        );

        expect(screen.getAllByRole('button', { name: 'Remove from chat' })).toHaveLength(1);
    });

    it('does not show remove button for scoped shift employee', () => {
        const scoped = makeParticipant({ user_id: 20 });

        render(
            <ParticipantsDrawer
                {...defaultProps}
                conversationType="shift_group"
                participants={[scoped]}
                extraParticipantUserIds={[]}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Remove from chat' })).toBeNull();
    });

    it('shows Add extra participant label for shift_group', () => {
        render(<ParticipantsDrawer {...defaultProps} conversationType="shift_group" participants={[]} />);

        expect(screen.getByRole('button', { name: 'Add extra participant' })).toBeTruthy();
    });

    it('shows remove button only for extra participants in user_group', () => {
        const extra = makeParticipant({ user_id: 10 });
        const scoped = makeParticipant({ user_id: 20 });

        render(
            <ParticipantsDrawer
                {...defaultProps}
                conversationType="user_group"
                participants={[extra, scoped]}
                extraParticipantUserIds={[10]}
            />,
        );

        expect(screen.getAllByRole('button', { name: 'Remove from chat' })).toHaveLength(1);
    });

    it('shows Add extra participant label for user_group', () => {
        render(<ParticipantsDrawer {...defaultProps} conversationType="user_group" participants={[]} />);

        expect(screen.getByRole('button', { name: 'Add extra participant' })).toBeTruthy();
    });

    it('hides add button and remove buttons when canManage=false', () => {
        render(
            <ParticipantsDrawer
                {...defaultProps}
                canManage={false}
                conversationType="admin_group"
                participants={[makeParticipant({ user_id: 2 })]}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Add people' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Remove from chat' })).toBeNull();
    });
});
