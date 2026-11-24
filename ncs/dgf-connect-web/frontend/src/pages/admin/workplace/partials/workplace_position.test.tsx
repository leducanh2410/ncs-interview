import React from 'react';
import { act, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import WorkplacePositionList from './workplace_position';

const {
    useGetWorkplacePositionsQueryMock,
    useDeleteWorkplacePositionMutationMock,
    useReorderWorkplacePositionsMutationMock,
    usePermissionsMock,
    useDisclosureMock,
    notificationsMock,
    dataTableSpy,
    dragDropContextSpy,
    createUpdateSpy,
} = vi.hoisted(() => ({
    useGetWorkplacePositionsQueryMock: vi.fn(),
    useDeleteWorkplacePositionMutationMock: vi.fn(),
    useReorderWorkplacePositionsMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    useDisclosureMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    dataTableSpy: vi.fn(),
    dragDropContextSpy: vi.fn(),
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
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement(
            'button',
            { onClick, 'type': 'button', 'disabled': disabled || loading, 'data-testid': 'header-btn' },
            children,
        ),
    Center: ({ children }: any) => React.createElement('div', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    TableTd: ({ children }: any) => React.createElement('td', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@hello-pangea/dnd', () => ({
    DragDropContext: (props: any) => {
        dragDropContextSpy(props);
        return React.createElement('div', null, props.children);
    },
    Draggable: ({ children }: any) =>
        children({ draggableProps: {}, dragHandleProps: {}, innerRef: () => undefined }, { isDragging: false }),
    Droppable: ({ children }: any) => children({ droppableProps: {}, innerRef: () => undefined, placeholder: null }),
}));

vi.mock('mantine-datatable', () => ({
    DataTable: (props: any) => {
        dataTableSpy(props);
        const body = React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                (props.records ?? []).map((record: any, index: number) =>
                    props.rowFactory({
                        record,
                        index,
                        rowProps: { className: '' },
                        children: props.columns
                            .filter((c: any) => c.accessor !== '')
                            .map((col: any) =>
                                React.createElement(
                                    'td',
                                    { key: col.accessor },
                                    col.render ? col.render(record) : record[col.accessor],
                                ),
                            ),
                    }),
                ),
            ),
        );
        return props.tableWrapper({ children: body });
    },
    DataTableDraggableRow: ({ children }: any) => React.createElement('tr', null, children),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/workplace/api', () => ({
    useGetWorkplacePositionsQuery: (...args: unknown[]) => useGetWorkplacePositionsQueryMock(...args),
    useDeleteWorkplacePositionMutation: () => useDeleteWorkplacePositionMutationMock(),
    useReorderWorkplacePositionsMutation: () => useReorderWorkplacePositionsMutationMock(),
}));

vi.mock('@/pages/admin/workplace/partials/workplace_position_create_update', () => ({
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

vi.mock('@/components/TableEmptyState', () => ({
    default: () => React.createElement('div', { 'data-testid': 'empty-state' }),
}));

vi.mock('react-icons/lu', () => ({
    LuGripVertical: () => React.createElement('span', null, 'grip'),
    LuPencilLine: () => React.createElement('span', null, 'edit'),
    LuPlus: () => React.createElement('span', null, 'plus'),
    LuSave: () => React.createElement('span', null, 'save'),
    LuTrash2: () => React.createElement('span', null, 'trash'),
}));

const makePosition = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        workplace_id: 1,
        name: 'Cashier',
        salary_per_hour: null,
        is_active: true,
        sort_order: 1,
        ...overrides,
    }) as any;

function getOnDragEnd() {
    return dragDropContextSpy.mock.calls[dragDropContextSpy.mock.calls.length - 1][0].onDragEnd;
}

describe('WorkplacePositionList', () => {
    let modalOpen: ReturnType<typeof vi.fn>;
    let modalClose: ReturnType<typeof vi.fn>;

    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue({
            WORKPLACE_POSITION_LIST: true,
            WORKPLACE_POSITION_CREATE: true,
            WORKPLACE_POSITION_EDIT: true,
            WORKPLACE_POSITION_DELETE: true,
        });
        useDeleteWorkplacePositionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useReorderWorkplacePositionsMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useGetWorkplacePositionsQueryMock.mockReturnValue({
            data: { data: [makePosition({ id: 1, name: 'Cashier' }), makePosition({ id: 2, name: 'Manager' })] },
            isLoading: false,
        });

        modalOpen = vi.fn();
        modalClose = vi.fn();
        useDisclosureMock.mockReturnValue([false, { open: modalOpen, close: modalClose }]);
    });

    it('passes WORKPLACE_POSITION_LIST permission to the query', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_POSITION_LIST: false,
            WORKPLACE_POSITION_CREATE: false,
            WORKPLACE_POSITION_EDIT: false,
            WORKPLACE_POSITION_DELETE: false,
        });

        render(<WorkplacePositionList workplaceId="1" />);

        expect(useGetWorkplacePositionsQueryMock).toHaveBeenCalledWith('1', false);
    });

    it('renders a row per position with a dash for missing salary', () => {
        render(<WorkplacePositionList workplaceId="1" />);

        expect(screen.getByText('Cashier')).toBeInTheDocument();
        expect(screen.getByText('Manager')).toBeInTheDocument();
        expect(screen.getAllByText('-')).toHaveLength(2);
    });

    it('renders the salary value when present', () => {
        useGetWorkplacePositionsQueryMock.mockReturnValue({
            data: { data: [makePosition({ id: 1, name: 'Cashier', salary_per_hour: 1200 })] },
            isLoading: false,
        });

        render(<WorkplacePositionList workplaceId="1" />);

        expect(screen.getByText('1200')).toBeInTheDocument();
    });

    it('disables the Add button when WORKPLACE_POSITION_CREATE is false', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_POSITION_LIST: true,
            WORKPLACE_POSITION_CREATE: false,
            WORKPLACE_POSITION_EDIT: true,
            WORKPLACE_POSITION_DELETE: true,
        });

        render(<WorkplacePositionList workplaceId="1" />);

        const [, addBtn] = screen.getAllByTestId('header-btn');
        expect(addBtn).toBeDisabled();
    });

    it('opens the create modal with a null position when Add is clicked', () => {
        render(<WorkplacePositionList workplaceId="1" />);

        const [, addBtn] = screen.getAllByTestId('header-btn');
        fireEvent.click(addBtn);

        expect(modalOpen).toHaveBeenCalled();
        expect(createUpdateSpy).toHaveBeenCalledWith(expect.objectContaining({ position: null }));
    });

    it('opens the edit modal with the row position when the edit icon is clicked', () => {
        render(<WorkplacePositionList workplaceId="1" />);

        const [editIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(editIcon);

        expect(modalOpen).toHaveBeenCalled();
        expect(createUpdateSpy).toHaveBeenCalledWith(
            expect.objectContaining({ position: expect.objectContaining({ id: 1, name: 'Cashier' }) }),
        );
    });

    it('disables the edit and delete icons without permission', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_POSITION_LIST: true,
            WORKPLACE_POSITION_CREATE: true,
            WORKPLACE_POSITION_EDIT: false,
            WORKPLACE_POSITION_DELETE: false,
        });

        render(<WorkplacePositionList workplaceId="1" />);

        const icons = screen.getAllByTestId('action-icon');
        expect(icons[0]).toBeDisabled();
        expect(icons[1]).toBeDisabled();
    });

    it('opens the confirm modal and deletes the position on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteWorkplacePositionMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<WorkplacePositionList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('1');
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('shows an error notification when delete fails', async () => {
        useDeleteWorkplacePositionMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
            isPending: false,
        });

        render(<WorkplacePositionList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not delete position' }),
            );
        });
    });

    it('closes the confirm modal on cancel', () => {
        render(<WorkplacePositionList workplaceId="1" />);

        const [, deleteIcon] = screen.getAllByTestId('action-icon');
        fireEvent.click(deleteIcon);
        fireEvent.click(screen.getByTestId('cancel-btn'));

        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });

    it('keeps the Save order button disabled until the order actually changes', () => {
        render(<WorkplacePositionList workplaceId="1" />);

        const [saveOrderBtn] = screen.getAllByTestId('header-btn');
        expect(saveOrderBtn).toBeDisabled();

        act(() => getOnDragEnd()({ source: { index: 0 }, destination: { index: 0 } }));

        expect(saveOrderBtn).toBeDisabled();
    });

    it('enables Save order after a drag reorder and persists the new order on click', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useReorderWorkplacePositionsMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<WorkplacePositionList workplaceId="1" />);

        act(() => getOnDragEnd()({ source: { index: 0 }, destination: { index: 1 } }));

        const [saveOrderBtn] = screen.getAllByTestId('header-btn');
        expect(saveOrderBtn).toBeEnabled();

        fireEvent.click(saveOrderBtn);

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith([2, 1]);
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('reverts the order and shows an error notification when saving the order fails', async () => {
        useReorderWorkplacePositionsMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockRejectedValue(new Error('boom')),
            isPending: false,
        });

        render(<WorkplacePositionList workplaceId="1" />);

        act(() => getOnDragEnd()({ source: { index: 0 }, destination: { index: 1 } }));
        const [saveOrderBtn] = screen.getAllByTestId('header-btn');
        fireEvent.click(saveOrderBtn);

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not update order' }),
            );
        });
        expect(saveOrderBtn).toBeDisabled();
    });

    it('disables Save order without WORKPLACE_POSITION_EDIT permission even if the order changed', () => {
        usePermissionsMock.mockReturnValue({
            WORKPLACE_POSITION_LIST: true,
            WORKPLACE_POSITION_CREATE: true,
            WORKPLACE_POSITION_EDIT: false,
            WORKPLACE_POSITION_DELETE: true,
        });

        render(<WorkplacePositionList workplaceId="1" />);

        act(() => getOnDragEnd()({ source: { index: 0 }, destination: { index: 1 } }));

        const [saveOrderBtn] = screen.getAllByTestId('header-btn');
        expect(saveOrderBtn).toBeDisabled();
    });

    it('ignores a drag with no destination', () => {
        render(<WorkplacePositionList workplaceId="1" />);

        act(() => getOnDragEnd()({ source: { index: 0 }, destination: null }));

        const [saveOrderBtn] = screen.getAllByTestId('header-btn');
        expect(saveOrderBtn).toBeDisabled();
    });

    it('shows the empty state and a fixed min height when there are no positions', () => {
        useGetWorkplacePositionsQueryMock.mockReturnValue({ data: { data: [] }, isLoading: false });

        render(<WorkplacePositionList workplaceId="1" />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ minHeight: 150 }));
    });

    it('passes fetching state to the DataTable while loading', () => {
        useGetWorkplacePositionsQueryMock.mockReturnValue({ data: undefined, isLoading: true });

        render(<WorkplacePositionList workplaceId="1" />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ fetching: true, records: [] }));
    });
});
