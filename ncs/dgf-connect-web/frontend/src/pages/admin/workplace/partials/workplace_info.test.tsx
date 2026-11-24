import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceInfo from './workplace_info';

const {
    useDeleteWorkplaceMutationMock,
    usePermissionsMock,
    useDisclosureMock,
    notificationsMock,
    workplaceImagesSpy,
    workplaceFormSpy,
} = vi.hoisted(() => ({
    useDeleteWorkplaceMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    useDisclosureMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    workplaceImagesSpy: vi.fn(),
    workplaceFormSpy: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (...args: unknown[]) => useDisclosureMock(...args),
}));

vi.mock('@mantine/core', () => ({
    Button: ({ children, onClick, loading, disabled }: any) =>
        React.createElement(
            'button',
            { onClick, 'disabled': disabled || loading, 'data-testid': 'delete-btn' },
            children,
        ),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useDeleteWorkplaceMutation: () => useDeleteWorkplaceMutationMock(),
}));

vi.mock('@/pages/admin/workplace/partials/workplace_images', () => ({
    default: (props: any) => {
        workplaceImagesSpy(props);
        return React.createElement('div', { 'data-testid': 'workplace-images' });
    },
}));

vi.mock('@/pages/admin/workplace/partials/workplace_form', () => ({
    default: (props: any) => {
        workplaceFormSpy(props);
        return React.createElement('div', { 'data-testid': 'workplace-form' });
    },
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel }: any) => {
        if (!opened) return null;
        return React.createElement(
            'div',
            { 'data-testid': 'confirm-modal' },
            React.createElement('button', { 'onClick': onConfirm, 'data-testid': 'confirm-btn' }, 'Confirm'),
            React.createElement('button', { 'onClick': onCancel, 'data-testid': 'cancel-btn' }, 'Cancel'),
        );
    },
}));

vi.mock('react-icons/lu', () => ({
    LuTrash2: () => React.createElement('span', null, 'trash-icon'),
}));

const makeWorkplace = (overrides = {}) =>
    ({
        id: 1,
        name: 'Tokyo HQ',
        ...overrides,
    }) as any;

describe('WorkplaceInfo', () => {
    let openDelete: ReturnType<typeof vi.fn>;
    let closeDelete: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({ WORKPLACE_DELETE: true });
        useDeleteWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });

        openDelete = vi.fn();
        closeDelete = vi.fn();
        useDisclosureMock.mockReturnValue([false, { open: openDelete, close: closeDelete }]);
    });

    it('renders WorkplaceImages with the workplace id', () => {
        render(<WorkplaceInfo id="1" workplace={makeWorkplace()} isFetching={false} />);

        expect(screen.getByTestId('workplace-images')).toBeInTheDocument();
        expect(workplaceImagesSpy).toHaveBeenCalledWith(expect.objectContaining({ workplaceId: '1' }));
    });

    it('renders WorkplaceForm with id, workplace and isFetching', () => {
        const workplace = makeWorkplace();
        render(<WorkplaceInfo id="1" workplace={workplace} isFetching={true} />);

        expect(screen.getByTestId('workplace-form')).toBeInTheDocument();
        expect(workplaceFormSpy).toHaveBeenCalledWith(
            expect.objectContaining({ id: '1', workplace, isFetching: true }),
        );
    });

    it('disables the delete button when WORKPLACE_DELETE is false', () => {
        usePermissionsMock.mockReturnValue({ WORKPLACE_DELETE: false });

        render(<WorkplaceInfo id="1" workplace={makeWorkplace()} isFetching={false} />);

        expect(screen.getByTestId('delete-btn')).toBeDisabled();
    });

    it('opens the delete confirmation when the delete button is clicked', () => {
        render(<WorkplaceInfo id="1" workplace={makeWorkplace()} isFetching={false} />);

        fireEvent.click(screen.getByTestId('delete-btn'));

        expect(openDelete).toHaveBeenCalled();
    });

    it('calls deleteMutation and shows success notification on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteWorkplaceMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useDisclosureMock.mockReturnValue([true, { open: openDelete, close: closeDelete }]);

        render(<WorkplaceInfo id="1" workplace={makeWorkplace()} isFetching={false} />);

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('1');
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
            expect(closeDelete).toHaveBeenCalled();
        });
    });

    it('shows a specific error message when the workplace has shifts', async () => {
        const { ApiError } = await import('@/helpers/api.helper');
        const error = new ApiError({
            status: 400,
            message: 'WORKPLACE_CANNOT_DELETE_WITH_SHIFT',
            data: {},
        });
        useDeleteWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(error),
            isPending: false,
        });
        useDisclosureMock.mockReturnValue([true, { open: openDelete, close: closeDelete }]);

        render(<WorkplaceInfo id="1" workplace={makeWorkplace()} isFetching={false} />);

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({
                    color: 'red',
                    message: 'error|Could not delete workplace: Workplace has shifts',
                }),
            );
        });
    });

    it('shows a generic error message for other failures', async () => {
        useDeleteWorkplaceMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
            isPending: false,
        });
        useDisclosureMock.mockReturnValue([true, { open: openDelete, close: closeDelete }]);

        render(<WorkplaceInfo id="1" workplace={makeWorkplace()} isFetching={false} />);

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not delete workplace' }),
            );
        });
    });

    it('closes the confirm modal when cancel is clicked', () => {
        useDisclosureMock.mockReturnValue([true, { open: openDelete, close: closeDelete }]);

        render(<WorkplaceInfo id="1" workplace={makeWorkplace()} isFetching={false} />);

        fireEvent.click(screen.getByTestId('cancel-btn'));

        expect(closeDelete).toHaveBeenCalled();
    });
});
