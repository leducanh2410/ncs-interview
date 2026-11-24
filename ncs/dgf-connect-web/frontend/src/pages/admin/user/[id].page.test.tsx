import React from 'react';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/user/[id].page';

const { useGetUserQueryMock, usePermissionsMock, userActionSpy, routerMock } = vi.hoisted(() => ({
    useGetUserQueryMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    userActionSpy: vi.fn(),
    routerMock: { query: { id: '42' }, push: vi.fn() },
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({ useRouter: () => routerMock }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('./[id].module.css', () => ({ default: {} }));

vi.mock('@mantine/core', () => {
    const Tabs = ({ children, onChange }: any) =>
        React.createElement('div', { 'data-testid': 'tabs', 'onClick': () => onChange?.('papers') }, children);
    Tabs.List = ({ children }: any) => React.createElement('div', { 'data-testid': 'tabs-list' }, children);
    Tabs.Tab = ({ children, value, disabled }: any) =>
        React.createElement('button', { 'type': 'button', 'data-tab': value, disabled }, children);
    Tabs.Panel = ({ children, value }: any) => React.createElement('div', { 'data-tab-panel': value }, children);

    return {
        ActionIcon: ({ children, onClick }: any) =>
            React.createElement('button', { type: 'button', onClick }, children),
        Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
        Badge: ({ children }: any) => React.createElement('span', null, children),
        Box: ({ children }: any) => React.createElement('div', null, children),
        Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
        CopyButton: ({ children }: any) => children({ copied: false, copy: vi.fn() }),
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Paper: ({ children }: any) => React.createElement('div', null, children),
        Stack: ({ children }: any) => React.createElement('div', null, children),
        Tabs,
        Text: ({ children }: any) => React.createElement('span', null, children),
        Title: ({ children }: any) => React.createElement('h2', null, children),
        Tooltip: ({ children }: any) => children,
    };
});

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' }, ADMIN_USER_LIST: { path: '/admin/user' } },
}));

vi.mock('@/enums/user_paper_status.enum', () => ({
    UserPaperStatusEnum: {
        APPROVED: { value: 'approved', text: 'Approved' },
        WAIT_FOR_APPROVAL: { value: 'wait_for_approval', text: 'Wait for approval' },
        REGISTERED: { value: 'registered', text: 'Registered' },
        PENDING: { value: 'pending', text: 'Pending' },
        REJECTED: { value: 'rejected', text: 'Rejected' },
    },
    getColor: () => 'blue',
}));

vi.mock('react-icons/lu', () => ({
    LuCheck: () => React.createElement('span', null, '✓'),
    LuCircleAlert: () => React.createElement('span', { 'data-testid': 'wait-for-approval-icon' }),
    LuTriangleAlert: ({ color }: any) =>
        React.createElement('span', { 'data-testid': 'warning-icon', 'data-color': color }),
    LuCopy: () => React.createElement('span', null, '📋'),
    LuX: () => React.createElement('span', null, '✗'),
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ children }: any) => React.createElement('div', { 'data-testid': 'dalert' }, children),
}));

vi.mock('@/pages/admin/user/partials/user_action', () => ({
    default: (props: any) => {
        userActionSpy(props);
        return React.createElement('div', { 'data-testid': 'user-action' });
    },
}));

vi.mock('@/pages/admin/user/partials/user_form', () => ({
    default: React.forwardRef(() => React.createElement('div', { 'data-testid': 'user-form' })),
}));

vi.mock('@/pages/admin/user/partials/user_images', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-images' }),
}));

vi.mock('@/pages/admin/user/partials/user_papers', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-papers' }),
}));

vi.mock('@/pages/admin/user/partials/user_devices', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-devices' }),
}));

vi.mock('@/pages/admin/user/partials/user_workplaces', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-workplaces' }),
}));

vi.mock('@/pages/admin/user/partials/user_roles', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-roles' }),
}));

vi.mock('@/pages/admin/user/partials/user_configs', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-configs' }),
}));

vi.mock('@/pages/admin/user/partials/user_lock_histories', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-lock-histories' }),
}));

vi.mock('@/pages/admin/user/partials/user_groups', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-groups' }),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserQuery: (...args: unknown[]) => useGetUserQueryMock(...args),
}));

const allPerms = {
    USER_EDIT: true,
    USER_PAPER_LIST: true,
    USER_DEVICE_LIST: true,
    USER_WORKPLACE_LIST: true,
    USER_CONFIG_LIST: true,
    USER_ASSIGN_ROLE: true,
    GROUP_VIEW: true,
};

const makeUser = (overrides: Record<string, unknown> = {}) => ({
    id: 42,
    name: 'Tanaka Hiroshi',
    name_kana: 'タナカヒロシ',
    staff_no: 'E-042',
    email: 'tanaka@example.com',
    paper_status: 'approved',
    face_registered: true,
    is_disabled: false,
    residence_card_expiry_alert: false,
    ...overrides,
});

describe('IndexPage (user detail)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue(allPerms);
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser() },
            isPending: false,
        });
    });

    it('renders the user detail page with tabs', () => {
        render(<IndexPage />);

        expect(screen.getByTestId('tabs-list')).toBeInTheDocument();
        expect(screen.getByText('Information')).toBeInTheDocument();
        expect(screen.getByText('Papers')).toBeInTheDocument();
        expect(screen.getByText('Roles')).toBeInTheDocument();
    });

    it('shows the groups tab when GROUP_VIEW permission is granted', () => {
        render(<IndexPage />);

        expect(screen.getByText('Groups')).toBeInTheDocument();
    });

    it('hides the groups tab without GROUP_VIEW permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, GROUP_VIEW: false });

        render(<IndexPage />);

        expect(screen.queryByText('Groups')).not.toBeInTheDocument();
    });

    it('renders the information panel content by default', () => {
        render(<IndexPage />);

        expect(screen.getByTestId('user-action')).toBeInTheDocument();
        expect(screen.getByTestId('user-form')).toBeInTheDocument();
    });

    it('passes canManageAccount=true to UserAction when paper status is approved', () => {
        render(<IndexPage />);

        expect(userActionSpy.mock.calls[0][0].canManageAccount).toBe(true);
    });

    it('passes canManageAccount=true to UserAction when paper status is rejected', () => {
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser({ paper_status: 'rejected' }) },
            isPending: false,
        });

        render(<IndexPage />);

        expect(userActionSpy.mock.calls[0][0].canManageAccount).toBe(true);
    });

    it('holds the CrossStaff buttons back for a file rejected before it ever got a staff no', () => {
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser({ paper_status: 'rejected', staff_no: null }) },
            isPending: false,
        });

        render(<IndexPage />);

        // Nothing was ever hired here, and syncing would file an HR record for them
        // in CrossStaff — a write this app cannot take back.
        expect(userActionSpy.mock.calls[0][0].canManageAccount).toBe(true);
        expect(userActionSpy.mock.calls[0][0].canSyncCrossStaff).toBe(false);
    });

    it('still syncs a rejected file that holds a staff no, since that employee is on the roster', () => {
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser({ paper_status: 'rejected', staff_no: 'E-042' }) },
            isPending: false,
        });

        render(<IndexPage />);

        expect(userActionSpy.mock.calls[0][0].canSyncCrossStaff).toBe(true);
    });

    it('leaves an approved account without a staff no able to sync, as managers always could', () => {
        // CreateManagerAction writes paper_status approved and no staff no, so gating
        // the sync buttons on staff no alone would have taken this away.
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser({ paper_status: 'approved', staff_no: null }) },
            isPending: false,
        });

        render(<IndexPage />);

        expect(userActionSpy.mock.calls[0][0].canSyncCrossStaff).toBe(true);
    });

    it.each(['registered', 'pending', 'wait_for_approval'])(
        'passes canManageAccount=false to UserAction when paper status is %s',
        (paperStatus) => {
            useGetUserQueryMock.mockReturnValue({
                data: { data: makeUser({ paper_status: paperStatus }) },
                isPending: false,
            });

            render(<IndexPage />);

            expect(userActionSpy.mock.calls[0][0].canManageAccount).toBe(false);
        },
    );

    it('passes canManageAccount=true to UserAction without USER_EDIT permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_EDIT: false });

        render(<IndexPage />);

        expect(userActionSpy.mock.calls[0][0].canManageAccount).toBe(true);
    });

    it('shows wait-for-approval icon on Papers tab when status is waiting_for_approval', () => {
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser({ paper_status: 'wait_for_approval' }) },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('wait-for-approval-icon')).toBeInTheDocument();
    });

    /**
     * The employee this marks is approved and working: nothing puts their file in the review
     * queue, so the tab is the only thing that can say the roster has no way to tell whether
     * they are still enrolled.
     */
    it('marks the Papers tab when the student card has no expiry date', () => {
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser({ paper_status: 'approved', has_missing_student_card_expiry: true }) },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByTestId('warning-icon')).toHaveAttribute('data-color', 'var(--mantine-color-red-7)');
    });

    it('leaves the Papers tab unmarked when the date is on file', () => {
        useGetUserQueryMock.mockReturnValue({
            data: { data: makeUser({ paper_status: 'approved' }) },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.queryByTestId('warning-icon')).not.toBeInTheDocument();
    });

    it('adds papers panel to visited tabs when Papers tab is clicked', async () => {
        const user = userEvent.setup();

        render(<IndexPage />);

        await user.click(screen.getByRole('button', { name: 'Papers' }));

        // Papers panel renders once the tab is visited
        expect(screen.getByTestId('user-papers')).toBeInTheDocument();
    });
});
