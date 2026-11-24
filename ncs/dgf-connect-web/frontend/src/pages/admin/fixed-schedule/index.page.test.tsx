import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/fixed-schedule/index.page';

const {
    dataTableSpy,
    commonSearchBoxSpy,
    useGetFixedSchedulesQueryMock,
    useDeleteFixedScheduleMutationMock,
    usePermissionsMock,
    useDisclosureMock,
    notificationsMock,
    routerMock,
    filterWorkplacesByAccessMock,
    useAtomMock,
} = vi.hoisted(() => ({
    dataTableSpy: vi.fn(),
    commonSearchBoxSpy: vi.fn(),
    useGetFixedSchedulesQueryMock: vi.fn(),
    useDeleteFixedScheduleMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    useDisclosureMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    routerMock: { query: {}, push: vi.fn() },
    filterWorkplacesByAccessMock: vi.fn((..._args: unknown[]): any[] => []),
    useAtomMock: vi.fn((..._args: unknown[]): [any, any] => [{ workplaces: [], workplace_access: {} }, vi.fn()]),
}));

vi.mock('next/link', () => ({
    default: ({ children, href }: { children: React.ReactNode; href: string }) =>
        React.createElement('a', { href }, children),
}));

vi.mock('next/router', () => ({
    useRouter: () => routerMock,
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    ActionIcon: ({ children, onClick, disabled, color }: any) =>
        React.createElement(
            'button',
            { onClick, disabled, 'data-testid': 'action-icon', 'data-color': color },
            children,
        ),
    Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick, disabled }: any) =>
        React.createElement('button', { onClick, 'aria-disabled': disabled, 'data-testid': 'create-btn' }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children, span, tt }: any) => React.createElement(span ? 'span' : 'span', { 'data-tt': tt }, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (...args: unknown[]) => useDisclosureMock(...args),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('jotai', () => ({
    useAtom: (...args: unknown[]) => useAtomMock(...args),
    atom: (init: any) => ({ init }),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/fixed-schedule/api', () => ({
    useGetFixedSchedulesQuery: (...args: unknown[]) => useGetFixedSchedulesQueryMock(...args),
    useDeleteFixedScheduleMutation: () => useDeleteFixedScheduleMutationMock(),
}));

vi.mock('@/pages/admin/fixed-schedule/partials/fixed_schedule_create_update', () => ({
    default: ({ opened, fixedSchedule }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'create-update-modal', 'data-schedule-id': fixedSchedule?.id ?? null },
                  'modal',
              )
            : null,
}));

vi.mock('@/components/CommonSearchBox', () => ({
    default: (props: any) => {
        commonSearchBoxSpy(props);
        return React.createElement(
            'div',
            { 'data-testid': 'search-box' },
            props.elements.map((el: any) => React.createElement('span', { key: el.id }, el.label)),
        );
    },
    userRenderItem: vi.fn(),
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

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', { 'data-testid': 'user-avatar' }, user?.name ?? ''),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (d: string) => `formatted:${d}`,
        getDayOfWeek: (day: number) => `day${day}`,
        formatWorkHour: (start: string, end: string) => ({ startTime: start, endTime: end }),
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: {
        toSelectOptions: () => [],
        filterWorkplacesByAccess: (...args: unknown[]) => filterWorkplacesByAccessMock(...args),
    },
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (_name: string, params: any) => `/admin/workplace/${params.id}`,
    },
}));

vi.mock('@/pages/global.state', () => ({
    masterAtom: { init: { workplaces: [] } },
}));

const makeSchedule = (overrides: Record<string, any> = {}) => ({
    id: 1,
    workplace_id: 10,
    user_id: 20,
    started_at: '2026-01-01',
    workplace: { id: 10, name: 'Tokyo HQ' },
    user: { id: 20, name: 'Alice' },
    details: [
        {
            id: 100,
            fixed_schedule_id: 1,
            day: 1,
            workplace_shift_id: 5,
            workplace_shift: { start_time: '09:00:00', end_time: '18:00:00' },
            workplace_position_id: 31,
            workplace_position: { id: 31, name: 'Picking' },
        },
    ],
    ...overrides,
});

const allPerms = {
    FIXED_SCHEDULE_LIST: true,
    FIXED_SCHEDULE_CREATE: true,
    FIXED_SCHEDULE_EDIT: true,
    FIXED_SCHEDULE_DELETE: true,
    WORKPLACE_SHIFT_LIST: true,
};

describe('Fixed schedule index page', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        usePermissionsMock.mockReturnValue(allPerms);
        useDeleteFixedScheduleMutationMock.mockReturnValue({ mutateAsync: vi.fn().mockResolvedValue(undefined) });
        useGetFixedSchedulesQueryMock.mockReturnValue({
            data: { data: { data: [makeSchedule()], total: 1, current_page: 1, last_page: 1, per_page: 15 } },
            isPending: false,
        });
        useDisclosureMock.mockReturnValue([false, { open: vi.fn(), close: vi.fn() }]);
        filterWorkplacesByAccessMock.mockReturnValue([]);
        useAtomMock.mockReturnValue([{ workplaces: [], workplace_access: {} }, vi.fn()]);
    });

    /****************************************
     * Search box                           *
     ****************************************/

    it('renders the search box with Workplace and User fields', () => {
        render(<IndexPage />);

        expect(screen.getByTestId('search-box')).toBeInTheDocument();
        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                elements: expect.arrayContaining([
                    expect.objectContaining({ id: 'workplace_id', name: 'workplace_id' }),
                    expect.objectContaining({ id: 'user_id', name: 'user_id' }),
                ]),
            }),
        );
    });

    /****************************************
     * Workplace scoping                    *
     ****************************************/

    it('scopes the Workplace filter to accessible workplaces via the fixed_schedule access group', () => {
        const workplaces = [{ id: 1, name: 'Tokyo HQ' }];
        const workplaceAccess = { fixed_schedule: [1] };
        useAtomMock.mockReturnValue([{ workplaces, workplace_access: workplaceAccess }, vi.fn()]);

        render(<IndexPage />);

        expect(filterWorkplacesByAccessMock).toHaveBeenCalledWith(workplaces, workplaceAccess, 'fixed_schedule');
    });

    it('shows all workplaces for GlobalAdmin (null access)', () => {
        const workplaces = [
            { id: 1, name: 'Tokyo HQ' },
            { id: 2, name: 'Osaka Branch' },
        ];
        filterWorkplacesByAccessMock.mockReturnValue(workplaces);
        useAtomMock.mockReturnValue([{ workplaces, workplace_access: { fixed_schedule: null } }, vi.fn()]);

        render(<IndexPage />);

        expect(filterWorkplacesByAccessMock).toHaveReturnedWith(workplaces);
    });

    /****************************************
     * Table rows                           *
     ****************************************/

    it('renders a link to the workplace detail page', () => {
        render(<IndexPage />);

        expect(screen.getByRole('link', { name: 'Tokyo HQ' })).toHaveAttribute('href', '/admin/workplace/10');
    });

    it('renders the user avatar in the user column', () => {
        render(<IndexPage />);

        expect(screen.getByTestId('user-avatar')).toHaveTextContent('Alice');
    });

    it('renders schedule details with day and time range', () => {
        render(<IndexPage />);

        expect(screen.getByText(/day1/)).toBeInTheDocument();
        expect(screen.getByText('09:00:00 ~ 18:00:00')).toBeInTheDocument();
    });

    it('renders the position next to the time range when a detail has one', () => {
        render(<IndexPage />);

        expect(screen.getByText('· Picking')).toBeInTheDocument();
    });

    it('omits the position when a detail has none', () => {
        useGetFixedSchedulesQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [
                        makeSchedule({
                            details: [
                                {
                                    id: 100,
                                    fixed_schedule_id: 1,
                                    day: 1,
                                    workplace_shift_id: 5,
                                    workplace_shift: { start_time: '09:00:00', end_time: '18:00:00' },
                                    workplace_position_id: null,
                                    workplace_position: null,
                                },
                            ],
                        }),
                    ],
                },
            },
            isPending: false,
        });
        render(<IndexPage />);

        expect(screen.getByText('09:00:00 ~ 18:00:00')).toBeInTheDocument();
        expect(screen.queryByText(/Picking/)).not.toBeInTheDocument();
    });

    it('sorts Sunday (day 0) to the end of the schedule list', () => {
        useGetFixedSchedulesQueryMock.mockReturnValue({
            data: {
                data: {
                    data: [
                        makeSchedule({
                            details: [
                                {
                                    id: 101,
                                    fixed_schedule_id: 1,
                                    day: 0,
                                    workplace_shift_id: 5,
                                    workplace_shift: { start_time: '09:00:00', end_time: '18:00:00' },
                                },
                                {
                                    id: 102,
                                    fixed_schedule_id: 1,
                                    day: 2,
                                    workplace_shift_id: 5,
                                    workplace_shift: { start_time: '10:00:00', end_time: '19:00:00' },
                                },
                            ],
                        }),
                    ],
                    total: 1,
                    current_page: 1,
                    last_page: 1,
                    per_page: 15,
                },
            },
            isPending: false,
        });

        render(<IndexPage />);

        const days = screen.getAllByText(/^day\d/);
        expect(days[days.length - 1]).toHaveTextContent('day0');
    });

    it('does not render a Started at column', () => {
        render(<IndexPage />);

        expect(screen.queryByText('formatted:2026-01-01')).not.toBeInTheDocument();
    });

    it('passes an empty array to the table while data is loading', () => {
        useGetFixedSchedulesQueryMock.mockReturnValue({ data: undefined, isPending: true });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ data: [] }));
    });

    /****************************************
     * Permissions                          *
     ****************************************/

    it('disables the Create schedule button when FIXED_SCHEDULE_CREATE is false', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, FIXED_SCHEDULE_CREATE: false });

        render(<IndexPage />);

        expect(screen.getByTestId('create-btn')).toHaveAttribute('aria-disabled', 'true');
    });

    it('disables the Create schedule button when WORKPLACE_SHIFT_LIST is false', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, WORKPLACE_SHIFT_LIST: false });

        render(<IndexPage />);

        expect(screen.getByTestId('create-btn')).toHaveAttribute('aria-disabled', 'true');
    });

    it('disables the edit action icon when FIXED_SCHEDULE_EDIT is false', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, FIXED_SCHEDULE_EDIT: false });

        render(<IndexPage />);

        const editBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'blue');
        expect(editBtn).toBeDisabled();
    });

    it('disables the delete action icon when FIXED_SCHEDULE_DELETE is false', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, FIXED_SCHEDULE_DELETE: false });

        render(<IndexPage />);

        const deleteBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'red');
        expect(deleteBtn).toBeDisabled();
    });

    it('passes FIXED_SCHEDULE_LIST permission to useGetFixedSchedulesQuery', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, FIXED_SCHEDULE_LIST: false });

        render(<IndexPage />);

        expect(useGetFixedSchedulesQueryMock).toHaveBeenCalledWith(expect.anything(), false);
    });

    it('passes noPermission=true to DDataTable when FIXED_SCHEDULE_LIST is false', () => {
        usePermissionsMock.mockReturnValue({ ...allPerms, FIXED_SCHEDULE_LIST: false });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
    });

    /****************************************
     * Create / Edit modal                  *
     ****************************************/

    it('renders the create/update modal when disclosure is open', () => {
        useDisclosureMock.mockReturnValue([true, { open: vi.fn(), close: vi.fn() }]);

        render(<IndexPage />);

        expect(screen.getByTestId('create-update-modal')).toBeInTheDocument();
    });

    it('opens the create/update modal with no current schedule on Create button click', () => {
        const open = vi.fn();
        useDisclosureMock.mockReturnValue([false, { open, close: vi.fn() }]);

        render(<IndexPage />);

        fireEvent.click(screen.getByTestId('create-btn'));

        expect(open).toHaveBeenCalled();
    });

    it('opens the create/update modal with the row schedule on edit icon click', () => {
        const open = vi.fn();
        useDisclosureMock.mockReturnValue([true, { open, close: vi.fn() }]);

        render(<IndexPage />);

        const editBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'blue')!;
        fireEvent.click(editBtn);

        expect(open).toHaveBeenCalled();
        expect(screen.getByTestId('create-update-modal')).toHaveAttribute('data-schedule-id', '1');
    });

    /****************************************
     * Delete flow                          *
     ****************************************/

    it('opens the confirm modal when the delete icon is clicked', () => {
        render(<IndexPage />);

        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();

        const deleteBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'red')!;
        fireEvent.click(deleteBtn);

        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();
    });

    it('calls deleteFixedSchedule and shows success notification on confirm', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteFixedScheduleMutationMock.mockReturnValue({ mutateAsync });

        render(<IndexPage />);

        const deleteBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'red')!;
        fireEvent.click(deleteBtn);
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith('1');
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('shows an error notification when deleteFixedSchedule fails', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(new Error('server error'));
        useDeleteFixedScheduleMutationMock.mockReturnValue({ mutateAsync });

        render(<IndexPage />);

        const deleteBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'red')!;
        fireEvent.click(deleteBtn);
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'red' }));
        });
    });

    it('closes the confirm modal when cancel is clicked', () => {
        render(<IndexPage />);

        const deleteBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'red')!;
        fireEvent.click(deleteBtn);
        expect(screen.getByTestId('confirm-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('cancel-btn'));
        expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
    });

    it('closes the confirm modal after a successful delete', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useDeleteFixedScheduleMutationMock.mockReturnValue({ mutateAsync });

        render(<IndexPage />);

        const deleteBtn = screen.getAllByTestId('action-icon').find((el) => el.getAttribute('data-color') === 'red')!;
        fireEvent.click(deleteBtn);
        fireEvent.click(screen.getByTestId('confirm-btn'));

        await waitFor(() => {
            expect(screen.queryByTestId('confirm-modal')).not.toBeInTheDocument();
        });
    });
});
