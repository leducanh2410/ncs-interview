import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import IndexPage from '@/pages/admin/fixed-schedule/change-requests/index.page';

const {
    dataTableSpy,
    commonSearchBoxSpy,
    useGetFixedScheduleChangeRequestsQueryMock,
    useGetFixedScheduleChangeConfigsQueryMock,
    useApproveFixedScheduleChangeRequestMutationMock,
    useRejectFixedScheduleChangeRequestMutationMock,
    useBulkResolveFixedScheduleChangeRequestsMutationMock,
    exportFixedScheduleChangeRequestsMock,
    usePermissionsMock,
    notificationsMock,
    routerMock,
    filterWorkplacesByAccessMock,
} = vi.hoisted(() => ({
    dataTableSpy: vi.fn(),
    commonSearchBoxSpy: vi.fn(),
    useGetFixedScheduleChangeRequestsQueryMock: vi.fn(),
    useGetFixedScheduleChangeConfigsQueryMock: vi.fn(),
    useApproveFixedScheduleChangeRequestMutationMock: vi.fn(),
    useRejectFixedScheduleChangeRequestMutationMock: vi.fn(),
    useBulkResolveFixedScheduleChangeRequestsMutationMock: vi.fn(),
    exportFixedScheduleChangeRequestsMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    routerMock: { query: {} as Record<string, any>, push: vi.fn(), replace: vi.fn() },
    filterWorkplacesByAccessMock: vi.fn((..._args: unknown[]): any[] => []),
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
    useTranslation: () => ({
        t: (key: string, opts?: any) => (opts?.count !== undefined ? `${key}:${opts.count}` : key),
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, onClick, href }: any) => React.createElement('a', { onClick, href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'status-badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Button: ({ children, onClick }: any) => React.createElement('button', { onClick }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 10, name: 'Tokyo HQ' }], workplace_access: {} }, vi.fn()],
    atom: (init: any) => ({ init }),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/fixed-schedule/change-config/api', () => ({
    useGetFixedScheduleChangeConfigsQuery: (...args: unknown[]) => useGetFixedScheduleChangeConfigsQueryMock(...args),
}));

vi.mock('@/pages/admin/fixed-schedule/change-requests/api', () => ({
    useGetFixedScheduleChangeRequestsQuery: (...args: unknown[]) => useGetFixedScheduleChangeRequestsQueryMock(...args),
    useApproveFixedScheduleChangeRequestMutation: () => useApproveFixedScheduleChangeRequestMutationMock(),
    useRejectFixedScheduleChangeRequestMutation: () => useRejectFixedScheduleChangeRequestMutationMock(),
    useBulkResolveFixedScheduleChangeRequestsMutation: () => useBulkResolveFixedScheduleChangeRequestsMutationMock(),
    exportFixedScheduleChangeRequests: (...args: unknown[]) => exportFixedScheduleChangeRequestsMock(...args),
}));

vi.mock('@/pages/admin/fixed-schedule/change-requests/partials/request_detail_modal', () => ({
    default: ({ request, onApprove, onClose }: any) =>
        request
            ? React.createElement(
                  'div',
                  { 'data-testid': 'detail-modal' },
                  React.createElement('span', null, `days:${request.days.length}`),
                  React.createElement('button', { 'onClick': onApprove, 'data-testid': 'modal-approve' }, 'Approve'),
                  React.createElement('button', { 'onClick': onClose, 'data-testid': 'modal-close' }, 'Close'),
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
}));

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel, message }: any) => {
        if (!opened) return null;
        return React.createElement(
            'div',
            { 'data-testid': 'bulk-confirm-modal' },
            React.createElement('span', null, message),
            React.createElement('button', { 'onClick': onConfirm, 'data-testid': 'bulk-confirm-btn' }, 'Confirm'),
            React.createElement('button', { 'onClick': onCancel, 'data-testid': 'bulk-cancel-btn' }, 'Cancel'),
        );
    },
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'div',
            null,
            React.createElement('button', {
                'data-testid': 'select-all',
                'onClick': () => props.onSelectedRecordsChange?.(records),
            }),
            records.map((record: any) =>
                React.createElement(
                    'div',
                    { key: record.id },
                    props.columns.map((col: any) =>
                        React.createElement('div', { key: col.key }, col.render ? col.render(record) : record[col.key]),
                    ),
                ),
            ),
        );
    },
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) => React.createElement('span', null, user?.name ?? ''),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        parseAndFormat: (v: string) => `formatted:${v}`,
        now: () => ({ format: () => '2026-07-16' }),
    },
    DATE_STORE_FORMAT: 'YYYY-MM-DD',
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_e: any, value: string) => value,
        getColor: () => 'gray',
        toSelectArray: () => [
            { value: 'pending', label: 'Pending' },
            { value: 'approved', label: 'Approved' },
            { value: 'rejected', label: 'Rejected' },
        ],
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: {
        toSelectOptions: (workplaces: any) => workplaces.map((w: any) => ({ value: String(w.id), label: w.name })),
        filterWorkplacesByAccess: (...args: unknown[]) => filterWorkplacesByAccessMock(...args),
    },
}));

const makeRequest = (overrides: Record<string, any> = {}) => ({
    id: 1,
    fixed_schedule_id: 5,
    user: { id: 20, name: 'Alice', staff_no: null },
    workplace: { id: 10, name: 'Tokyo HQ' },
    status: 'pending',
    processed_by: null,
    processed_at: null,
    created_at: '2026-07-01T00:00:00Z',
    days: [
        {
            id: 100,
            day: 1,
            current_workplace_shift: { id: 1, start_time: '09:00:00', end_time: '18:00:00' },
            requested_workplace_shift: { id: 2, start_time: '13:00:00', end_time: '21:00:00' },
        },
    ],
    ...overrides,
});

const allPerms = {
    FIXED_SCHEDULE_CHANGE_REQUEST_LIST: true,
    FIXED_SCHEDULE_CHANGE_REQUEST_PROCESS: true,
};

describe('Fixed schedule change requests page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue(allPerms);
        filterWorkplacesByAccessMock.mockReturnValue([{ id: 10, name: 'Tokyo HQ' }]);
        useGetFixedScheduleChangeRequestsQueryMock.mockReturnValue({
            data: { data: [makeRequest()], total: 1, current_page: 1, last_page: 1, per_page: 15 },
            isPending: false,
        });
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({ data: { data: [] } });
        useApproveFixedScheduleChangeRequestMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useRejectFixedScheduleChangeRequestMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useBulkResolveFixedScheduleChangeRequestsMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('defaults the status filter to pending', () => {
        render(<IndexPage />);

        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({ initialValues: expect.objectContaining({ status: 'pending' }) }),
        );
    });

    it('scopes the Workplace filter via the fixed_schedule_change_request access group', () => {
        render(<IndexPage />);

        expect(filterWorkplacesByAccessMock).toHaveBeenCalledWith(
            expect.anything(),
            expect.anything(),
            'fixed_schedule_change_request',
        );
    });

    it('includes an employee_name text filter element', () => {
        render(<IndexPage />);

        const call = commonSearchBoxSpy.mock.calls[0][0];
        expect(call.elements.find((el: any) => el.id === 'employee_name')).toBeTruthy();
    });

    it('includes a status select filter element', () => {
        render(<IndexPage />);

        const call = commonSearchBoxSpy.mock.calls[0][0];
        expect(call.elements.find((el: any) => el.id === 'status')).toBeTruthy();
    });

    it('renders the changed-days count and status badge per row', () => {
        render(<IndexPage />);

        expect(screen.getByText('{{count}} days:1')).toBeInTheDocument();
        expect(screen.getByTestId('status-badge')).toHaveTextContent('pending');
    });

    it('computes a default date range when exactly one workplace is selected and a config exists', () => {
        routerMock.query = { workplace_id: '10' };
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: {
                data: [
                    {
                        workplace_id: 10,
                        updated_at: '2026-02-01T00:00:00Z',
                        deadline: '2026-02-15',
                    },
                ],
            },
        });

        render(<IndexPage />);

        expect(routerMock.replace).toHaveBeenCalledWith(
            expect.objectContaining({
                query: expect.objectContaining({ dates: ['formatted:2026-02-01T00:00:00Z', '2026-02-15'] }),
            }),
            undefined,
            { shallow: true },
        );
    });

    it('does not compute a default date range when no single workplace is selected', () => {
        routerMock.query = {};
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [{ workplace_id: 10, updated_at: '2026-02-01T00:00:00Z', deadline: '2026-02-15' }] },
        });

        render(<IndexPage />);

        expect(routerMock.replace).not.toHaveBeenCalled();
    });

    it('does not compute a default date range when the selected workplace has no config', () => {
        routerMock.query = { workplace_id: '10' };
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({ data: { data: [] } });

        render(<IndexPage />);

        expect(routerMock.replace).not.toHaveBeenCalled();
    });

    it('does not override an already-present (manually edited) date range', () => {
        routerMock.query = { workplace_id: '10', dates: ['2026-01-01', '2026-01-31'] };
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [{ workplace_id: 10, updated_at: '2026-02-01T00:00:00Z', deadline: '2026-02-15' }] },
        });

        render(<IndexPage />);

        expect(routerMock.replace).not.toHaveBeenCalled();
    });

    it('opens the detail modal showing changed days when a row is opened', () => {
        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));

        expect(screen.getByTestId('detail-modal')).toHaveTextContent('days:1');
    });

    it('approving from the detail modal calls the approve mutation', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useApproveFixedScheduleChangeRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-approve'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(1);
        });
    });

    it('bulk toolbar only appears once requests are selected', () => {
        render(<IndexPage />);

        expect(screen.queryByText(/Approve .* requests/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('select-all'));

        expect(screen.getByText(/Approve .* requests/)).toBeInTheDocument();
    });

    it('bulk approve calls the bulk-resolve mutation with all selected ids', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useBulkResolveFixedScheduleChangeRequestsMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetFixedScheduleChangeRequestsQueryMock.mockReturnValue({
            data: {
                data: [makeRequest({ id: 1 }), makeRequest({ id: 2 })],
                total: 2,
                current_page: 1,
                last_page: 1,
                per_page: 15,
            },
            isPending: false,
        });

        render(<IndexPage />);

        fireEvent.click(screen.getByTestId('select-all'));
        fireEvent.click(screen.getByText(/Approve .* requests/));
        fireEvent.click(screen.getByTestId('bulk-confirm-btn'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({ ids: [1, 2], action: 'approve' });
        });
    });

    it('export button submits current filters', async () => {
        routerMock.query = { status: 'approved' };

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Export excel'));

        await waitFor(() => {
            expect(exportFixedScheduleChangeRequestsMock).toHaveBeenCalledWith(routerMock.query);
        });
    });

    it('shows the workplace registration deadline in its own column', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [{ workplace_id: 10, deadline: '2026-08-01' }] },
        });

        render(<IndexPage />);

        expect(screen.getByText('formatted:2026-08-01')).toBeInTheDocument();
    });

    it('highlights a pending request whose workplace deadline has already passed', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [{ workplace_id: 10, deadline: '2026-07-01' }] },
        });
        useGetFixedScheduleChangeRequestsQueryMock.mockReturnValue({
            data: { data: [makeRequest({ status: 'pending' })], total: 1, current_page: 1, last_page: 1, per_page: 15 },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByText('Overdue')).toBeInTheDocument();
        expect(dataTableSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                rowStyle: expect.any(Function),
            }),
        );
        const { rowStyle } = dataTableSpy.mock.calls[0][0];
        expect(rowStyle(makeRequest({ status: 'pending' }))).toEqual({
            backgroundColor: 'var(--mantine-color-red-0)',
        });
    });

    it('does not highlight a pending request whose workplace deadline has not passed', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [{ workplace_id: 10, deadline: '2026-08-01' }] },
        });

        render(<IndexPage />);

        expect(screen.queryByText('Overdue')).not.toBeInTheDocument();
        const { rowStyle } = dataTableSpy.mock.calls[0][0];
        expect(rowStyle(makeRequest({ status: 'pending' }))).toBeUndefined();
    });

    it('does not highlight an already-resolved request even if the workplace deadline has passed', () => {
        useGetFixedScheduleChangeConfigsQueryMock.mockReturnValue({
            data: { data: [{ workplace_id: 10, deadline: '2026-07-01' }] },
        });
        useGetFixedScheduleChangeRequestsQueryMock.mockReturnValue({
            data: {
                data: [makeRequest({ status: 'approved' })],
                total: 1,
                current_page: 1,
                last_page: 1,
                per_page: 15,
            },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.queryByText('Overdue')).not.toBeInTheDocument();
        const { rowStyle } = dataTableSpy.mock.calls[0][0];
        expect(rowStyle(makeRequest({ status: 'approved' }))).toBeUndefined();
    });
});
