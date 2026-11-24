import React from 'react';
import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserAction from './user_action';
import type { UserResponseData } from '@/pages/admin/user/api';

const { usePermissionsMock, restoreUserMock, syncToCSMock, syncFromCSMock, notificationsShowMock, upcomingShiftsMock } =
    vi.hoisted(() => ({
        usePermissionsMock: vi.fn(),
        restoreUserMock: vi.fn(),
        syncToCSMock: vi.fn(),
        syncFromCSMock: vi.fn(),
        notificationsShowMock: vi.fn(),
        upcomingShiftsMock: vi.fn(),
    }));

vi.mock('next/link', () => ({
    default: ({ children, href }: any) => React.createElement('a', { href }, children),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => {
        const [value, setValue] = React.useState(initial);
        return [value, { open: () => setValue(true), close: () => setValue(false) }];
    },
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, type, onClick, disabled, loading, component, href }: any) =>
        component
            ? React.createElement('a', { href, 'aria-disabled': disabled }, children)
            : React.createElement(
                  'button',
                  { type: type ?? 'button', onClick, disabled: disabled || loading },
                  children,
              ),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title, onClose }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': `modal-${title?.toLowerCase?.().replace(/ /g, '-')}` },
                  children,
              )
            : null,
    Paper: ({ children, h, mb, ...rest }: any) => React.createElement('div', { 'data-testid': 'paper' }, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class ApiError extends Error {
        status: number;
        data: any;

        constructor({ status, message, data }: { status: number; message: string; data: any }) {
            super(message);
            this.status = status;
            this.message = message;
            this.data = data;
        }
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: { getPath: () => '/schedule/1' },
}));

vi.mock('@/enums/error_code.enum', () => ({
    ErrorCodeEnum: {
        USER_EMAIL_ALREADY_EXISTS: { value: 'USER_EMAIL_ALREADY_EXISTS' },
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel, title }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': `confirm-${title?.toLowerCase?.().replace(/ /g, '-')}` },
                  React.createElement('button', { type: 'button', onClick: onConfirm }, 'Confirm'),
                  React.createElement('button', { type: 'button', onClick: onCancel }, 'Cancel'),
              )
            : null,
}));

vi.mock('./reset_password_form', () => ({
    default: () => React.createElement('div', { 'data-testid': 'reset-password-form' }),
}));

vi.mock('./user_lock_form', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-lock-form' }),
}));

vi.mock('./user_unlock_form', () => ({
    default: () => React.createElement('div', { 'data-testid': 'user-unlock-form' }),
}));

vi.mock('react-icons/lu', () => ({
    LuCalendar: () => null,
    LuCloudUpload: () => null,
    LuKeyRound: () => null,
    LuLock: () => null,
    LuLockOpen: () => null,
    LuPencilLine: () => null,
    LuRedo: () => null,
    LuSave: () => null,
    LuX: () => null,
}));

vi.mock('./user_lock_warning_modal', () => ({
    default: ({ opened, shifts, onConfirm, onCancel }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'user-lock-warning' },
                  React.createElement('span', null, `${shifts.length} shifts`),
                  React.createElement('button', { type: 'button', onClick: onConfirm }, 'Continue'),
                  React.createElement('button', { type: 'button', onClick: onCancel }, 'Cancel warning'),
              )
            : null,
}));

vi.mock('@/pages/admin/user/api', () => ({
    useRestoreUserMutation: () => ({ mutateAsync: restoreUserMock }),
    useSyncUserToCrossStaffMutation: () => ({ mutateAsync: syncToCSMock, isPending: false }),
    useSyncUserFromCrossStaffMutation: () => ({ mutateAsync: syncFromCSMock, isPending: false }),
    useGetUserUpcomingShiftsQuery: (...args: unknown[]) => upcomingShiftsMock(...args),
}));

const allPerms = {
    USER_DISABLE: true,
    USER_ENABLE: true,
    USER_RESTORE: true,
    USER_RESET_PASSWORD: true,
    USER_SYNC: true,
    USER_EDIT: true,
};

const makeUser = (overrides: Record<string, unknown> = {}) =>
    ({
        id: 42,
        email: 'worker@example.com',
        is_disabled: false,
        ...overrides,
    }) as unknown as UserResponseData;

const defaultProps = {
    canManageAccount: true,
    canSyncCrossStaff: true,
    id: '42',
    user: makeUser(),
    isLoading: false,
    isReadOnly: true,
    onEdit: vi.fn(),
    onCancel: vi.fn(),
    formId: 'user-form',
    isSaving: false,
};

describe('UserAction', () => {
    const setUpcomingShifts = (shifts: { id: number }[]) => {
        const response = { data: { data: shifts }, isFetching: false, refetch: vi.fn() };
        response.refetch.mockResolvedValue({ data: { data: shifts } });
        upcomingShiftsMock.mockReturnValue(response);
    };

    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue(allPerms);
        setUpcomingShifts([]);
    });

    it('keeps the lock form closed and notifies when the upcoming shifts cannot be loaded', async () => {
        const refetch = vi.fn().mockResolvedValue({ data: undefined, isError: true });
        upcomingShiftsMock.mockReturnValue({ data: undefined, isFetching: false, refetch });
        const user = userEvent.setup();
        render(<UserAction {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        expect(screen.queryByTestId('user-lock-form')).not.toBeInTheDocument();
        expect(screen.queryByTestId('user-lock-warning')).not.toBeInTheDocument();
        expect(notificationsShowMock).toHaveBeenCalledWith({
            color: 'red',
            message: 'error|Could not load the upcoming shifts of this account',
        });
    });

    it('opens the lock form directly when the user has no upcoming shifts', async () => {
        const user = userEvent.setup();
        render(<UserAction {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        expect(screen.getByTestId('user-lock-form')).toBeInTheDocument();
        expect(screen.queryByTestId('user-lock-warning')).not.toBeInTheDocument();
    });

    it('warns about upcoming shifts before opening the lock form', async () => {
        setUpcomingShifts([{ id: 1 }, { id: 2 }]);
        const user = userEvent.setup();
        render(<UserAction {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));

        const warning = screen.getByTestId('user-lock-warning');
        expect(within(warning).getByText('2 shifts')).toBeInTheDocument();
        expect(screen.queryByTestId('user-lock-form')).not.toBeInTheDocument();

        await user.click(within(warning).getByRole('button', { name: 'Continue' }));

        expect(screen.queryByTestId('user-lock-warning')).not.toBeInTheDocument();
        expect(screen.getByTestId('user-lock-form')).toBeInTheDocument();
    });

    it('cancelling the upcoming-shift warning does not open the lock form', async () => {
        setUpcomingShifts([{ id: 1 }]);
        const user = userEvent.setup();
        render(<UserAction {...defaultProps} />);

        await user.click(screen.getByRole('button', { name: 'Lock account' }));
        await user.click(screen.getByRole('button', { name: 'Cancel warning' }));

        expect(screen.queryByTestId('user-lock-warning')).not.toBeInTheDocument();
        expect(screen.queryByTestId('user-lock-form')).not.toBeInTheDocument();
    });

    it('renders a skeleton paper when isLoading is true', () => {
        render(<UserAction {...defaultProps} isLoading={true} user={null} />);

        // The Paper skeleton has no content (no action buttons)
        expect(screen.queryByRole('button')).not.toBeInTheDocument();
    });

    it('renders action buttons for an active user', () => {
        render(<UserAction {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Lock account' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reset password' })).toBeInTheDocument();
    });

    it('shows "Unlock account" button instead of "Lock account" for disabled user', () => {
        render(<UserAction {...defaultProps} user={makeUser({ is_disabled: true })} />);

        expect(screen.getByRole('button', { name: 'Unlock account' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Lock account' })).not.toBeInTheDocument();
    });

    it('Lock account button is disabled without USER_DISABLE permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_DISABLE: false });

        render(<UserAction {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Lock account' })).toBeDisabled();
    });

    it('disables every account action except locking when canManageAccount=false', () => {
        render(<UserAction {...defaultProps} canManageAccount={false} canSyncCrossStaff={false} />);

        expect(screen.getByRole('link', { name: 'View schedule' })).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByRole('button', { name: 'Lock account' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Reset password' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Sync to CrossStaff' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Sync from CrossStaff' })).toBeDisabled();
    });

    it('enables every account action when canManageAccount=true', () => {
        render(<UserAction {...defaultProps} canManageAccount={true} />);

        expect(screen.getByRole('link', { name: 'View schedule' })).toHaveAttribute('aria-disabled', 'false');
        expect(screen.getByRole('button', { name: 'Lock account' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Reset password' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Sync to CrossStaff' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Sync from CrossStaff' })).toBeEnabled();
    });

    it('disables only the CrossStaff buttons when the account may be managed but not synced', () => {
        render(<UserAction {...defaultProps} canSyncCrossStaff={false} />);

        expect(screen.getByRole('button', { name: 'Sync to CrossStaff' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Sync from CrossStaff' })).toBeDisabled();
        // The local actions are unaffected: they touch nothing outside this app.
        expect(screen.getByRole('link', { name: 'View schedule' })).toHaveAttribute('aria-disabled', 'false');
        expect(screen.getByRole('button', { name: 'Lock account' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Reset password' })).toBeEnabled();
    });

    it('Unlock account button stays enabled when canManageAccount=false', () => {
        render(<UserAction {...defaultProps} canManageAccount={false} user={makeUser({ is_disabled: true })} />);

        expect(screen.getByRole('button', { name: 'Unlock account' })).toBeEnabled();
    });

    it('Lock account button is still gated by USER_DISABLE when canManageAccount=false', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_DISABLE: false });
        render(<UserAction {...defaultProps} canManageAccount={false} />);

        expect(screen.getByRole('button', { name: 'Lock account' })).toBeDisabled();
    });

    it('Unlock account button is still gated by USER_ENABLE when canManageAccount=false', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_ENABLE: false });
        render(<UserAction {...defaultProps} canManageAccount={false} user={makeUser({ is_disabled: true })} />);

        expect(screen.getByRole('button', { name: 'Unlock account' })).toBeDisabled();
    });

    it('Lock account button is enabled with USER_DISABLE but without USER_EDIT permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_EDIT: false });

        render(<UserAction {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Lock account' })).toBeEnabled();
        expect(screen.getByRole('button', { name: 'Edit' })).toBeDisabled();
    });

    it('Unlock account button is gated by USER_ENABLE, not USER_DISABLE', () => {
        // Has lock permission but not unlock: the Unlock button must stay disabled.
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_ENABLE: false });

        render(<UserAction {...defaultProps} user={makeUser({ is_disabled: true })} />);

        expect(screen.getByRole('button', { name: 'Unlock account' })).toBeDisabled();
    });

    it('Unlock account button is enabled with USER_ENABLE but without USER_DISABLE', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_DISABLE: false });

        render(<UserAction {...defaultProps} user={makeUser({ is_disabled: true })} />);

        expect(screen.getByRole('button', { name: 'Unlock account' })).toBeEnabled();
    });

    it('shows "Restore account" button for soft-deleted users (del<timestamp>_ prefix)', () => {
        render(<UserAction {...defaultProps} user={makeUser({ email: 'del1234567890_worker@example.com' })} />);

        expect(screen.getByRole('button', { name: 'Restore account' })).toBeInTheDocument();
    });

    it('does not show "Restore account" for normal email addresses', () => {
        render(<UserAction {...defaultProps} />);

        expect(screen.queryByRole('button', { name: 'Restore account' })).not.toBeInTheDocument();
    });

    it('hides "Unlock account" for a soft-deleted user so USER_DISABLE cannot bypass USER_RESTORE', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_RESTORE: false });

        render(
            <UserAction
                {...defaultProps}
                user={makeUser({ email: 'del1234567890_worker@example.com', is_disabled: true })}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Unlock account' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Restore account' })).toBeDisabled();
    });

    it('still shows "Unlock account" for a merely locked (not deleted) user', () => {
        render(<UserAction {...defaultProps} user={makeUser({ is_disabled: true })} />);

        expect(screen.getByRole('button', { name: 'Unlock account' })).toBeEnabled();
        expect(screen.queryByRole('button', { name: 'Restore account' })).not.toBeInTheDocument();
    });

    it('does not show Reset password button without USER_RESET_PASSWORD permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_RESET_PASSWORD: false });

        render(<UserAction {...defaultProps} />);

        expect(screen.queryByRole('button', { name: 'Reset password' })).not.toBeInTheDocument();
    });

    it('clicking Unlock account opens the unlock reason modal instead of unlocking straight away', async () => {
        const user = userEvent.setup();

        render(<UserAction {...defaultProps} user={makeUser({ is_disabled: true })} />);

        expect(screen.queryByTestId('user-unlock-form')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Unlock account' }));

        expect(screen.getByTestId('modal-unlock-account')).toBeInTheDocument();
        expect(screen.getByTestId('user-unlock-form')).toBeInTheDocument();
        expect(screen.queryByTestId('confirm-unlock-account')).not.toBeInTheDocument();
    });

    it('shows Edit button when isReadOnly', () => {
        render(<UserAction {...defaultProps} isReadOnly={true} />);

        expect(screen.getByRole('button', { name: 'Edit' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Save changes' })).not.toBeInTheDocument();
    });

    it('shows Cancel and Save changes buttons when not isReadOnly', () => {
        render(<UserAction {...defaultProps} isReadOnly={false} />);

        expect(screen.getByRole('button', { name: 'Cancel' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Save changes' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument();
    });

    it('Sync buttons are disabled without USER_SYNC permission', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, USER_SYNC: false });

        render(<UserAction {...defaultProps} />);

        expect(screen.getByRole('button', { name: 'Sync to CrossStaff' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Sync from CrossStaff' })).toBeDisabled();
    });

    it('hides both Sync buttons for a disabled user', () => {
        render(<UserAction {...defaultProps} user={makeUser({ is_disabled: true })} />);

        expect(screen.queryByRole('button', { name: 'Sync to CrossStaff' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Sync from CrossStaff' })).not.toBeInTheDocument();
    });

    describe('sync to CrossStaff', () => {
        it('shows a success notification after syncing to CrossStaff', async () => {
            const user = userEvent.setup();
            syncToCSMock.mockResolvedValue({});

            render(<UserAction {...defaultProps} />);
            await user.click(screen.getByRole('button', { name: 'Sync to CrossStaff' }));

            await waitFor(() => expect(syncToCSMock).toHaveBeenCalledTimes(1));
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Successfully synced to CrossStaff' }),
            );
        });

        it('shows a plain error notification for a non-api failure', async () => {
            const user = userEvent.setup();
            syncToCSMock.mockRejectedValue(new Error('network'));

            render(<UserAction {...defaultProps} />);
            await user.click(screen.getByRole('button', { name: 'Sync to CrossStaff' }));

            await waitFor(() =>
                expect(notificationsShowMock).toHaveBeenCalledWith(
                    expect.objectContaining({ color: 'red', message: 'error|Could not sync to CrossStaff' }),
                ),
            );
        });

        it('appends the remote reason from an ApiError', async () => {
            const user = userEvent.setup();
            const { ApiError } = await import('@/helpers/api.helper');
            syncToCSMock.mockRejectedValue(
                new ApiError({ status: 500, message: 'E_INTERNAL_SERVER_ERROR', data: 'remote rejected' as any }),
            );

            render(<UserAction {...defaultProps} />);
            await user.click(screen.getByRole('button', { name: 'Sync to CrossStaff' }));

            await waitFor(() =>
                expect(notificationsShowMock).toHaveBeenCalledWith(
                    expect.objectContaining({
                        color: 'red',
                        message: 'error|Could not sync to CrossStaff: remote rejected',
                    }),
                ),
            );
        });
    });

    describe('sync from CrossStaff', () => {
        it('shows a success notification after syncing from CrossStaff', async () => {
            const user = userEvent.setup();
            syncFromCSMock.mockResolvedValue({});

            render(<UserAction {...defaultProps} />);
            await user.click(screen.getByRole('button', { name: 'Sync from CrossStaff' }));

            await waitFor(() => expect(syncFromCSMock).toHaveBeenCalledTimes(1));
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Successfully synced from CrossStaff' }),
            );
        });

        it('shows an error notification when it fails', async () => {
            const user = userEvent.setup();
            syncFromCSMock.mockRejectedValue(new Error('boom'));

            render(<UserAction {...defaultProps} />);
            await user.click(screen.getByRole('button', { name: 'Sync from CrossStaff' }));

            await waitFor(() =>
                expect(notificationsShowMock).toHaveBeenCalledWith(
                    expect.objectContaining({ color: 'red', message: 'error|Could not sync from CrossStaff' }),
                ),
            );
        });
    });

    describe('restore account', () => {
        const deletedUser = makeUser({ email: 'del1700000000_worker@example.com' });

        const openRestoreModal = async (user: ReturnType<typeof userEvent.setup>) => {
            await user.click(screen.getByRole('button', { name: 'Restore account' }));
            return screen.getByTestId('confirm-restore-account');
        };

        it('restores the account and shows a success notification', async () => {
            const user = userEvent.setup();
            restoreUserMock.mockResolvedValue({});

            render(<UserAction {...defaultProps} user={deletedUser} />);
            const modal = await openRestoreModal(user);
            await user.click(within(modal).getByRole('button', { name: 'Confirm' }));

            await waitFor(() => expect(restoreUserMock).toHaveBeenCalledTimes(1));
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'teal', message: 'Successfully restore account' }),
            );
            expect(screen.queryByTestId('confirm-restore-account')).not.toBeInTheDocument();
        });

        it('shows a generic error when restoring fails', async () => {
            const user = userEvent.setup();
            restoreUserMock.mockRejectedValue(new Error('boom'));

            render(<UserAction {...defaultProps} user={deletedUser} />);
            const modal = await openRestoreModal(user);
            await user.click(within(modal).getByRole('button', { name: 'Confirm' }));

            await waitFor(() =>
                expect(notificationsShowMock).toHaveBeenCalledWith(
                    expect.objectContaining({ color: 'red', message: 'error|Could not restore account' }),
                ),
            );
        });

        it('explains an email conflict returned by the api', async () => {
            const user = userEvent.setup();
            const { ApiError } = await import('@/helpers/api.helper');
            restoreUserMock.mockRejectedValue(
                new ApiError({ status: 400, message: 'USER_EMAIL_ALREADY_EXISTS', data: {} }),
            );

            render(<UserAction {...defaultProps} user={deletedUser} />);
            const modal = await openRestoreModal(user);
            await user.click(within(modal).getByRole('button', { name: 'Confirm' }));

            await waitFor(() =>
                expect(notificationsShowMock).toHaveBeenCalledWith(
                    expect.objectContaining({
                        color: 'red',
                        message: 'error|Email already exists, cannot restore account',
                    }),
                ),
            );
        });

        it('closes the modal without restoring when cancelled', async () => {
            const user = userEvent.setup();

            render(<UserAction {...defaultProps} user={deletedUser} />);
            const modal = await openRestoreModal(user);
            await user.click(within(modal).getByRole('button', { name: 'Cancel' }));

            expect(restoreUserMock).not.toHaveBeenCalled();
            expect(screen.queryByTestId('confirm-restore-account')).not.toBeInTheDocument();
        });
    });
});
