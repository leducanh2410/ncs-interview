import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplaceShiftList from './workplace_shift';

const {
    useGetWorkplaceShiftsQueryMock,
    useDeleteWorkplaceShiftMutationMock,
    usePermissionsMock,
    useDisclosureMock,
    notificationsMock,
    dataTableSpy,
    createUpdateSpy,
} = vi.hoisted(() => ({
    useGetWorkplaceShiftsQueryMock: vi.fn(),
    useDeleteWorkplaceShiftMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    useDisclosureMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    dataTableSpy: vi.fn(),
    createUpdateSpy: vi.fn(),
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
    ActionIcon: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, disabled, 'data-testid': 'action-icon' }, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, disabled, 'data-testid': 'add-btn' }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        formatWorkHour: (
            startTime: string,
            endTime: string,
            startBreakTime: string | null = null,
            endBreakTime: string | null = null,
        ) => ({ startTime, endTime, startBreakTime, endBreakTime }),
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplaceShiftsQuery: (...args: unknown[]) => useGetWorkplaceShiftsQueryMock(...args),
    useDeleteWorkplaceShiftMutation: () => useDeleteWorkplaceShiftMutationMock(),
}));

vi.mock('@/pages/admin/workplace/partials/workplace_shift_create_update', () => ({
    default: (props: any) => {
        createUpdateSpy(props);
        return props.opened ? React.createElement('div', { 'data-testid': 'create-update-modal' }) : null;
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

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
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
                        props.columns.map((col: any) =>
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

vi.mock('react-icons/lu', () => ({
    LuPencilLine: () => React.createElement('span', null, 'edit'),
    LuPlus: () => React.createElement('span', null, 'plus'),
    LuTrash2: () => React.createElement('span', null, 'trash'),
}));

const makeShift = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        workplace_id: 1,
        start_time: '09:00:00',
        end_time: '18:00:00',
        start_break_time: null,
        end_break_time: null,
        break_duration: 0,
        salary_per_hour: null,
        check_in_round_type: 1,
        check_in_round_minutes: 15,
        check_out_round_type: 1,
        check_out_round_minutes: 15,
        date_adjustment: 0,
        leaders: [],
        ...overrides,
    }) as any;

describe('WorkplaceShiftList', () => {
    let modalOpen: ReturnType<typeof vi.fn>;
    let modalClose: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            WORKPLACE_SHIFT_LIST: true,
            WORKPLACE_SHIFT_CREATE: true,
            WORKPLACE_SHIFT_EDIT: true,
            WORKPLACE_SHIFT_DELETE: true,
        });
        useDeleteWorkplaceShiftMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useGetWorkplaceShiftsQueryMock.mockReturnValue({ data: { data: [makeShift()] }, isLoading: false });

        modalOpen = vi.fn();
        modalClose = vi.fn();
        useDisclosureMock.mockReturnValue([false, { open: modalOpen, close: modalClose }]);
    });

    it('passes WORKPLACE_SHIFT_LIST permission to the query', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_SHIFT_LIST: false,
            WORKPLACE_SHIFT_CREATE: false,
            WORKPLACE_SHIFT_EDIT: false,
            WORKPLACE_SHIFT_DELETE: false,
        });

        render(<WorkplaceShiftList workplaceId="1" />);

        expect(useGetWorkplaceShiftsQueryMock).toHaveBeenCalledWith('1', false);
    });

    it('renders the work time column', () => {
        render(<WorkplaceShiftList workplaceId="1" />);

        expect(screen.getByText('09:00:00 ~ 18:00:00')).toBeInTheDocument();
    });

    it('shows a dash for break time when there is no break', () => {
        render(<WorkplaceShiftList workplaceId="1" />);

        expect(screen.getAllByText('-')).toHaveLength(2);
    });

    it('renders the break time range and duration when a break is set', () => {
        useGetWorkplaceShiftsQueryMock.mockReturnValue({
            data: {
                data: [
                    makeShift({
                        start_break_time: '12:00:00',
                        end_break_time: '13:00:00',
                        break_duration: 60,
                    }),
                ],
            },
            isLoading: false,
        });

        render(<WorkplaceShiftList workplaceId="1" />);

        expect(screen.getByText('12:00:00 ~ 13:00:00 (60 minutes)')).toBeInTheDocument();
    });

    it('shows a dash for salary per hour when it is null', () => {
        render(<WorkplaceShiftList workplaceId="1" />);

        const cells = screen.getAllByText('-');
        expect(cells.length).toBeGreaterThan(0);
    });

    it('disables the Add button when WORKPLACE_SHIFT_CREATE is false', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_SHIFT_LIST: true,
            WORKPLACE_SHIFT_CREATE: false,
            WORKPLACE_SHIFT_EDIT: true,
            WORKPLACE_SHIFT_DELETE: true,
        });

        render(<WorkplaceShiftList workplaceId="1" />);

        expect(screen.getByTestId('add-btn')).toBeDisabled();
    });

    it('opens the create modal with a null shift when Add is clicked', () => {
        render(<WorkplaceShiftList workplaceId="1" />);

        fireEvent.click(screen.getByTestId('add-btn'));

        expect(modalOpen).toHaveBeenCalled();
        expect(createUpdateSpy).toHaveBeenCalledWith(expect.objectContaining({ shift: null }));
    });

    it('opens the edit modal with the row shift when the edit icon is clicked', () => {
        const shift = makeShift({ id: 9 });
        useGetWorkplaceShiftsQueryMock.mockReturnValue({ data: { data: [shift] }, isLoading: false });

        render(<WorkplaceShiftList workplaceId="1" />);

        const [editIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(editIcon);

        expect(modalOpen).toHaveBeenCalled();
        expect(createUpdateSpy).toHaveBeenCalledWith(expect.objectContaining({ shift }));
    });

    it('disables the edit and delete icons without permission', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_SHIFT_LIST: true,
            WORKPLACE_SHIFT_CREATE: true,
            WORKPLACE_SHIFT_EDIT: false,
            WORKPLACE_SHIFT_DELETE: false,
        });

        render(<WorkplaceShiftList workplaceId="1" />);

        const icons = screen.getAllByTestId('action-icon');
        expect(icons[0]).toBeDisabled();
        expect(icons[1]).toBeDisabled();
    });

    it('opens the confirm modal and deletes the shift on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteWorkplaceShiftMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetWorkplaceShiftsQueryMock.mockReturnValue({ data: { data: [makeShift({ id: 4 })] }, isLoading: false });

        render(<WorkplaceShiftList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('4');
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('shows an error notification when delete fails', async () => {
        useDeleteWorkplaceShiftMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
            isPending: false,
        });

        render(<WorkplaceShiftList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not delete shift' }),
            );
        });
    });

    it('resets the current shift and closes the modal via onClose', () => {
        render(<WorkplaceShiftList workplaceId="1" />);

        expect(createUpdateSpy).toHaveBeenCalledWith(expect.objectContaining({ shift: null }));

        const lastCall = createUpdateSpy.mock.calls[createUpdateSpy.mock.calls.length - 1][0];
        lastCall.onClose();

        expect(modalClose).toHaveBeenCalled();
    });

    it('passes isFetching to DDataTable while loading', () => {
        useGetWorkplaceShiftsQueryMock.mockReturnValue({ data: undefined, isLoading: true });

        render(<WorkplaceShiftList workplaceId="1" />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ isFetching: true, data: [] }));
    });
});
