import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import GroupDetailPage from '@/pages/admin/group/[id].page';

const { useGetGroupQueryMock, deleteMutateAsyncMock, usePermissionsMock, showSuccessMock, showErrorMock, routerMock } =
    vi.hoisted(() => ({
        useGetGroupQueryMock: vi.fn(),
        deleteMutateAsyncMock: vi.fn(),
        usePermissionsMock: vi.fn(),
        showSuccessMock: vi.fn(),
        showErrorMock: vi.fn(),
        routerMock: { query: { id: '7' }, push: vi.fn() },
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({ useRouter: () => routerMock }));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h2', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/configs/route.config', () => ({
    default: {
        HOME: { path: '/' },
        ADMIN_GROUP_LIST: { path: '/admin/group' },
    },
}));

vi.mock('react-icons/lu', () => ({
    LuTrash2: () => React.createElement('span', null, '🗑'),
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement('button', { type: 'button', onClick: onConfirm }, 'Confirm'),
                  React.createElement('button', { type: 'button', onClick: onCancel }, 'Cancel'),
              )
            : null,
}));

vi.mock('@/pages/admin/group/partials/group_manual_view', () => ({
    default: () => React.createElement('div', { 'data-testid': 'group-manual-view' }),
}));

vi.mock('@/pages/admin/group/partials/group_rule_view', () => ({
    default: () => React.createElement('div', { 'data-testid': 'group-rule-view' }),
}));

vi.mock('@/pages/admin/group/partials/group_system_view', () => ({
    default: () => React.createElement('div', { 'data-testid': 'group-system-view' }),
}));

vi.mock('@/utils/group_display_name', () => ({
    getGroupDisplayName: (group: any) => group?.name ?? '',
}));

vi.mock('@/pages/admin/group/api', () => ({
    useGetGroupQuery: (...args: unknown[]) => useGetGroupQueryMock(...args),
    useDeleteGroupMutation: () => ({ mutateAsync: deleteMutateAsyncMock, isPending: false }),
}));

const makeGroup = (kind: string, overrides: Record<string, unknown> = {}) => ({
    id: 7,
    name: 'Team Alpha',
    kind,
    ...overrides,
});

describe('GroupDetailPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = { id: '7' };

        usePermissionsMock.mockReturnValue({ GROUP_VIEW: true, GROUP_EDIT: true });
        useGetGroupQueryMock.mockReturnValue({ data: { data: makeGroup('manual') }, isFetching: false });
    });

    it('shows a loader while fetching and group is not yet available', () => {
        useGetGroupQueryMock.mockReturnValue({ data: undefined, isFetching: true });

        render(<GroupDetailPage />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
    });

    it('renders GroupManualView for a manual group', () => {
        render(<GroupDetailPage />);

        expect(screen.getByTestId('group-manual-view')).toBeInTheDocument();
        expect(screen.queryByTestId('group-rule-view')).not.toBeInTheDocument();
    });

    it('renders GroupRuleView for a rule_based group', () => {
        useGetGroupQueryMock.mockReturnValue({
            data: { data: makeGroup('rule_based') },
            isFetching: false,
        });

        render(<GroupDetailPage />);

        expect(screen.getByTestId('group-rule-view')).toBeInTheDocument();
    });

    it('renders GroupSystemView for a system group', () => {
        useGetGroupQueryMock.mockReturnValue({
            data: { data: makeGroup('worktime') },
            isFetching: false,
        });

        render(<GroupDetailPage />);

        expect(screen.getByTestId('group-system-view')).toBeInTheDocument();
    });

    it('shows the delete button for admin kinds (manual)', () => {
        render(<GroupDetailPage />);

        expect(screen.getByRole('button', { name: 'Delete group' })).toBeInTheDocument();
    });

    it('shows the delete button for admin kinds (rule_based)', () => {
        useGetGroupQueryMock.mockReturnValue({
            data: { data: makeGroup('rule_based') },
            isFetching: false,
        });

        render(<GroupDetailPage />);

        expect(screen.getByRole('button', { name: 'Delete group' })).toBeInTheDocument();
    });

    it('hides the delete button for system groups', () => {
        useGetGroupQueryMock.mockReturnValue({
            data: { data: makeGroup('worktime') },
            isFetching: false,
        });

        render(<GroupDetailPage />);

        expect(screen.queryByRole('button', { name: 'Delete group' })).not.toBeInTheDocument();
    });

    it('disables the delete button without GROUP_EDIT permission', () => {
        usePermissionsMock.mockReturnValue({ GROUP_VIEW: true, GROUP_EDIT: false });

        render(<GroupDetailPage />);

        expect(screen.getByRole('button', { name: 'Delete group' })).toBeDisabled();
    });

    it('opens the confirm modal when delete button is clicked', async () => {
        const user = userEvent.setup();

        render(<GroupDetailPage />);

        await user.click(screen.getByRole('button', { name: 'Delete group' }));

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
    });

    it('calls delete mutation, shows success and redirects on confirm', async () => {
        const user = userEvent.setup();
        deleteMutateAsyncMock.mockResolvedValue({});

        render(<GroupDetailPage />);

        await user.click(screen.getByRole('button', { name: 'Delete group' }));
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(deleteMutateAsyncMock).toHaveBeenCalledWith(7);
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully deleted group');
        expect(routerMock.push).toHaveBeenCalledWith('/admin/group');
    });

    it('shows error notification and closes modal when delete fails', async () => {
        const user = userEvent.setup();
        deleteMutateAsyncMock.mockRejectedValue(new Error('Server error'));

        render(<GroupDetailPage />);

        await user.click(screen.getByRole('button', { name: 'Delete group' }));
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not delete group');
        });
        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });

    it('closes the modal when cancel is clicked', async () => {
        const user = userEvent.setup();

        render(<GroupDetailPage />);

        await user.click(screen.getByRole('button', { name: 'Delete group' }));
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });

    it('uses the group name as the page title', () => {
        render(<GroupDetailPage />);

        expect(screen.getByRole('heading', { name: 'Team Alpha' })).toBeInTheDocument();
    });
});
