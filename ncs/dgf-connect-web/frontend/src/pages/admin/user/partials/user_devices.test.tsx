import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import UserDevices from './user_devices';

const { useGetUserDevicesQueryMock, deleteDeviceMock, usePermissionsMock, notificationsShowMock } = vi.hoisted(() => ({
    useGetUserDevicesQueryMock: vi.fn(),
    deleteDeviceMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsShowMock: vi.fn(),
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
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Title: ({ children }: any) => React.createElement('h3', null, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
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

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) =>
        React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                (data ?? []).map((row: any, ri: number) =>
                    React.createElement(
                        'tr',
                        { key: ri },
                        (columns ?? []).map((col: any, ci: number) =>
                            React.createElement('td', { key: ci }, col.render ? col.render(row) : row[col.key]),
                        ),
                    ),
                ),
            ),
        ),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserDevicesQuery: (...args: unknown[]) => useGetUserDevicesQueryMock(...args),
    useDeleteDeviceMutation: () => ({ mutateAsync: deleteDeviceMock }),
}));

vi.mock('react-icons/lu', () => ({
    LuTrash2: () => React.createElement('span', null, '🗑'),
}));

const fakeDevices = [
    { id: 10, platform: 'iOS', name: 'iPhone 14' },
    { id: 11, platform: 'Android', name: 'Galaxy S23' },
];

describe('UserDevices', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({ USER_DEVICE_LIST: true, USER_DEVICE_DELETE: true });
        useGetUserDevicesQueryMock.mockReturnValue({ data: { data: fakeDevices }, isPending: false });
    });

    it('renders the Device title', () => {
        render(<UserDevices isApproved={true} id="42" />);

        expect(screen.getByText('Device')).toBeInTheDocument();
    });

    it('renders device data in the table', () => {
        render(<UserDevices isApproved={true} id="42" />);

        expect(screen.getByText('iPhone 14')).toBeInTheDocument();
        expect(screen.getByText('Galaxy S23')).toBeInTheDocument();
        expect(screen.getByText('iOS')).toBeInTheDocument();
    });

    it('delete button is disabled without USER_DEVICE_DELETE permission', () => {
        usePermissionsMock.mockReturnValue({ USER_DEVICE_LIST: true, USER_DEVICE_DELETE: false });

        render(<UserDevices isApproved={true} id="42" />);

        const deleteButtons = screen.getAllByRole('button', { name: '🗑' });
        deleteButtons.forEach((btn) => expect(btn).toBeDisabled());
    });

    it('delete button is disabled when isApproved=false', () => {
        render(<UserDevices isApproved={false} id="42" />);

        const deleteButtons = screen.getAllByRole('button', { name: '🗑' });
        deleteButtons.forEach((btn) => expect(btn).toBeDisabled());
    });

    it('clicking delete opens confirm modal', async () => {
        const user = userEvent.setup();

        render(<UserDevices isApproved={true} id="42" />);

        await user.click(screen.getAllByRole('button', { name: '🗑' })[0]);

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
    });

    it('confirm calls deleteDevice and shows success notification', async () => {
        const user = userEvent.setup();
        deleteDeviceMock.mockResolvedValue({});

        render(<UserDevices isApproved={true} id="42" />);

        await user.click(screen.getAllByRole('button', { name: '🗑' })[0]);
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(deleteDeviceMock).toHaveBeenCalledWith('10');
        });
        expect(notificationsShowMock).toHaveBeenCalledWith(
            expect.objectContaining({ color: 'teal', message: 'Successfully deleted device' }),
        );
    });

    it('shows error notification when deleteDevice fails', async () => {
        const user = userEvent.setup();
        deleteDeviceMock.mockRejectedValue(new Error('Server error'));

        render(<UserDevices isApproved={true} id="42" />);

        await user.click(screen.getAllByRole('button', { name: '🗑' })[0]);
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(notificationsShowMock).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not delete device' }),
            );
        });
    });
});
