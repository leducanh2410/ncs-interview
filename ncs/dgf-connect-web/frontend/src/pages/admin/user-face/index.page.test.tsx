import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import AdminUserFaceIndexPage from '@/pages/admin/user-face/index.page';

const {
    useGetUserFacesQueryMock,
    updateMutateAsyncMock,
    deleteMutateAsyncMock,
    usePermissionsMock,
    showSuccessMock,
    showErrorMock,
    routerMock,
} = vi.hoisted(() => ({
    useGetUserFacesQueryMock: vi.fn(),
    updateMutateAsyncMock: vi.fn(),
    deleteMutateAsyncMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    routerMock: { query: {}, push: vi.fn() },
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
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled }, children),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, disabled, onClick }: any) =>
        React.createElement('button', { type: 'button', disabled, onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h2', null, children),
    Tooltip: ({ children }: any) => children,
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => {
        const [isOpen, setIsOpen] = React.useState(initial);
        return [isOpen, { open: () => setIsOpen(true), close: () => setIsOpen(false) }];
    },
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

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class ApiError extends Error {
        constructor({ message }: { status: number; message: string; data: Record<string, any> }) {
            super(message);
        }
    },
}));

vi.mock('@/enums/error_code.enum', () => ({
    ErrorCodeEnum: {
        USER_FACE_NO_APPROVED_PORTRAIT: { value: 'USER_FACE_NO_APPROVED_PORTRAIT' },
        USER_FACE_REMOVAL_FAILED: { value: 'USER_FACE_REMOVAL_FAILED' },
        USER_FACE_UPDATE_FAILED: { value: 'USER_FACE_UPDATE_FAILED' },
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (date: string) => date ?? '-' },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' } },
}));

vi.mock('react-icons/lu', () => ({
    LuPlus: () => React.createElement('span', null, '+'),
    LuRefreshCw: () => React.createElement('span', null, 'refresh'),
    LuTrash2: () => React.createElement('span', null, 'trash'),
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: ({ elements }: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            elements.map((el: any) => React.createElement('span', { key: el.id }, el.label)),
        ),
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) => {
        const records = Array.isArray(data) ? data : (data?.data ?? []);

        return React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                records.map((record: any) =>
                    React.createElement(
                        'tr',
                        { key: record.id },
                        columns.map((col: any) =>
                            React.createElement(
                                'td',
                                { key: col.key },
                                col.render ? col.render(record) : record[col.key],
                            ),
                        ),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user?.name ?? '-'),
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, title, onConfirm }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement('span', null, title),
                  React.createElement('button', { type: 'button', onClick: onConfirm }, 'Confirm'),
              )
            : null,
}));

vi.mock('@/pages/admin/user-face/partials/create_user_face_modal', () => ({
    default: () => null,
}));

vi.mock('@/pages/admin/user-face/api', () => ({
    useGetUserFacesQuery: (...args: unknown[]) => useGetUserFacesQueryMock(...args),
    useUpdateUserFaceMutation: () => ({ mutateAsync: updateMutateAsyncMock, isPending: false }),
    useDeleteUserFaceMutation: () => ({ mutateAsync: deleteMutateAsyncMock, isPending: false }),
}));

const fakeFace = {
    id: 1,
    user_id: 42,
    user: { id: 42, name: 'John Doe', staff_no: 'E-001' },
    vector_id: 'vec-abc123',
    provider: 'rekognition',
    updated_at: '2024-01-01T00:00:00Z',
};

describe('AdminUserFaceIndexPage', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            USER_FACE_LIST: true,
            USER_FACE_CREATE: true,
            USER_FACE_UPDATE: true,
            USER_FACE_DELETE: true,
        });

        useGetUserFacesQueryMock.mockReturnValue({
            data: { data: { data: [fakeFace], current_page: 1, last_page: 1, per_page: 15, total: 1 } },
            isPending: false,
        });
    });

    it('renders the search form with name and employee ID filters', () => {
        render(<AdminUserFaceIndexPage />);

        expect(screen.getByTestId('search-box')).toBeInTheDocument();
        expect(screen.getByText('Name')).toBeInTheDocument();
        expect(screen.getByText('Employee ID')).toBeInTheDocument();
    });

    it('renders face records in the table', () => {
        render(<AdminUserFaceIndexPage />);

        expect(screen.getByText('John Doe')).toBeInTheDocument();
        expect(screen.getByText('vec-abc123')).toBeInTheDocument();
    });

    it('disables the create button without USER_FACE_CREATE permission', () => {
        usePermissionsMock.mockReturnValue({
            USER_FACE_LIST: true,
            USER_FACE_CREATE: false,
            USER_FACE_UPDATE: true,
            USER_FACE_DELETE: true,
        });

        render(<AdminUserFaceIndexPage />);

        expect(screen.getByRole('button', { name: 'Create User Face' })).toBeDisabled();
    });

    it('opens update confirm modal and calls mutation on confirm', async () => {
        const user = userEvent.setup();
        updateMutateAsyncMock.mockResolvedValue({});

        render(<AdminUserFaceIndexPage />);

        await user.click(screen.getByRole('button', { name: 'refresh' }));

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
        expect(screen.getByText('Update')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(updateMutateAsyncMock).toHaveBeenCalledWith(42);
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully updated user face');
    });

    it('shows specific error message when update fails with no approved portrait', async () => {
        const user = userEvent.setup();
        updateMutateAsyncMock.mockRejectedValue(
            new ApiError({ status: 400, message: 'USER_FACE_NO_APPROVED_PORTRAIT', data: {} }),
        );

        render(<AdminUserFaceIndexPage />);

        await user.click(screen.getByRole('button', { name: 'refresh' }));
        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|No approved portrait image found');
        });
    });

    it('opens delete confirm modal and calls mutation on confirm', async () => {
        const user = userEvent.setup();
        deleteMutateAsyncMock.mockResolvedValue({});

        render(<AdminUserFaceIndexPage />);

        await user.click(screen.getByRole('button', { name: 'trash' }));

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
        expect(screen.getByText('Delete')).toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Confirm' }));

        await waitFor(() => {
            expect(deleteMutateAsyncMock).toHaveBeenCalledWith(42);
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully deleted user face');
    });
});
