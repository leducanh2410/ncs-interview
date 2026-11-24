import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import IndexPage from '@/pages/admin/admin-leave/index.page';

const {
    commonSearchBoxSpy,
    dataTableSpy,
    useGetAdminLeaveRequestsQueryMock,
    useGetAdminLeaveRequestDetailQueryMock,
    useApproveAdminLeaveRequestMutationMock,
    useRejectAdminLeaveRequestMutationMock,
    usePermissionsMock,
    notificationsMock,
    routerMock,
    invalidateQueriesMock,
} = vi.hoisted(() => ({
    commonSearchBoxSpy: vi.fn(),
    dataTableSpy: vi.fn(),
    useGetAdminLeaveRequestsQueryMock: vi.fn(),
    useGetAdminLeaveRequestDetailQueryMock: vi.fn(),
    useApproveAdminLeaveRequestMutationMock: vi.fn(),
    useRejectAdminLeaveRequestMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsMock: { show: vi.fn() },
    routerMock: { query: {} as Record<string, unknown>, push: vi.fn() },
    invalidateQueriesMock: vi.fn(),
}));

vi.mock('@tanstack/react-query', () => ({
    useQueryClient: () => ({ invalidateQueries: invalidateQueriesMock }),
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
        t: (key: string, options?: Record<string, unknown>) => {
            if (!options) return key;
            return Object.entries(options).reduce((acc, [k, v]) => acc.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Anchor: ({ children, onClick, href }: any) => React.createElement('a', { onClick, href }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Breadcrumbs: ({ children }: any) => React.createElement('nav', null, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Paper: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('span', null, children),
    Title: ({ children }: any) => React.createElement('h1', null, children),
    Tooltip: ({ children }: any) => children,
}));

vi.mock('@mantine/notifications', () => ({
    notifications: notificationsMock,
}));

vi.mock('@/components/UserWithAvatar', () => ({
    default: ({ user }: any) =>
        React.createElement('span', null, user?.staff_no ? `${user.name} (${user.staff_no})` : (user?.name ?? '')),
}));

vi.mock('@/helpers/route.helper', () => ({
    default: {
        getPath: (_routeName: string, params: Record<string, unknown>) => `/admin/user/${params.id}`,
    },
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        now: () => ({
            startOf: () => ({ toDate: () => new Date('2026-08-01T00:00:00Z') }),
            endOf: () => ({ toDate: () => new Date('2026-08-31T00:00:00Z') }),
        }),
        parseAndFormat: (value: string | null) => (value ? `formatted:${value}` : '-'),
    },
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

vi.mock('@/enums/admin_leave_type.enum', () => ({
    adminLeaveTypeSelectOptions: () => [
        { value: 'paid_leave', label: 'Paid leave (有休)' },
        { value: 'expense_claim', label: 'Expense claim (経費申請)' },
    ],
    getAdminLeaveTypeLabel: (value: string) => `type:${value}`,
}));

vi.mock('@/pages/admin/admin-leave/api', () => ({
    QUERY_KEY: ['admin-leave-requests'],
    DETAIL_QUERY_KEY: ['admin-leave-request-detail'],
    useGetAdminLeaveRequestsQuery: (...args: unknown[]) => useGetAdminLeaveRequestsQueryMock(...args),
    useGetAdminLeaveRequestDetailQuery: (...args: unknown[]) => useGetAdminLeaveRequestDetailQueryMock(...args),
    useApproveAdminLeaveRequestMutation: () => useApproveAdminLeaveRequestMutationMock(),
    useRejectAdminLeaveRequestMutation: () => useRejectAdminLeaveRequestMutationMock(),
}));

vi.mock('@/pages/admin/admin-leave/partials/request_detail_modal', () => ({
    default: ({ requestId, onApprove, onReject, onClose }: any) =>
        requestId !== null
            ? React.createElement(
                  'div',
                  { 'data-testid': 'detail-modal' },
                  React.createElement('span', null, `id:${requestId}`),
                  React.createElement('button', { 'onClick': onApprove, 'data-testid': 'modal-approve' }, 'Approve'),
                  React.createElement('button', { 'onClick': onReject, 'data-testid': 'modal-open-reject' }, 'Reject'),
                  React.createElement('button', { 'onClick': onClose, 'data-testid': 'modal-close' }, 'Close'),
              )
            : null,
}));

vi.mock('@/pages/admin/admin-leave/partials/reject_request_modal', () => ({
    default: ({ opened, onSubmit, onClose }: any) =>
        opened
            ? React.createElement(
                  'div',
                  null,
                  React.createElement(
                      'button',
                      {
                          'data-testid': 'modal-reject-submit',
                          'onClick': () => onSubmit('Coverage already thin'),
                      },
                      'Submit reject',
                  ),
                  React.createElement('button', { 'onClick': onClose, 'data-testid': 'modal-reject-cancel' }, 'Cancel'),
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
    userRenderItem: (user: any) => ({ value: String(user.id), label: user.name }),
}));

vi.mock('@/components/DDataTable', () => ({
    default: (props: any) => {
        dataTableSpy(props);
        const records = Array.isArray(props.data) ? props.data : (props.data?.data ?? []);
        return React.createElement(
            'table',
            { 'data-no-permission': props.noPermission ? 'true' : 'false' },
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

const makeRequest = (overrides: Record<string, any> = {}) => ({
    id: 1,
    requester_id: 20,
    requester_name: 'Alice',
    requester_staff_no: 'A-100',
    requester_profile_picture: '',
    type: 'paid_leave',
    start_date: '2026-08-06',
    end_date: '2026-08-10',
    leave_days: 5,
    reason: 'Family trip',
    status: 'pending',
    attachments_count: 0,
    has_overlap: false,
    created_at: '2026-08-01T09:00:00+09:00',
    decided_by_id: null,
    decided_by_name: null,
    decided_at: null,
    reject_reason: null,
    ...overrides,
});

describe('Admin leave request list page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue({ ADMIN_LEAVE_MANAGE: true });
        useGetAdminLeaveRequestsQueryMock.mockReturnValue({
            data: { data: [makeRequest()], total: 1, current_page: 1, last_page: 1, per_page: 15 },
            isPending: false,
        });
        useGetAdminLeaveRequestDetailQueryMock.mockReturnValue({ data: null, isPending: false });
        useApproveAdminLeaveRequestMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useRejectAdminLeaveRequestMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
    });

    it('defaults the date range to the current month and the other filters to all', () => {
        render(<IndexPage />);

        expect(commonSearchBoxSpy).toHaveBeenCalledWith(
            expect.objectContaining({
                initialValues: expect.objectContaining({
                    dates: [new Date('2026-08-01T00:00:00Z'), new Date('2026-08-31T00:00:00Z')],
                    requester_id: null,
                    type: 'all',
                    status: 'all',
                }),
            }),
        );
    });

    it('configures the requester filter as a remote picker constrained to manager.auth', () => {
        render(<IndexPage />);

        const call = commonSearchBoxSpy.mock.calls[0][0];
        const requesterElement = call.elements.find((el: any) => el.id === 'requester_id');
        expect(requesterElement).toMatchObject({
            type: 'select-remote',
            extraParams: { permission: 'manager.auth' },
        });
    });

    it('includes type and status select filters', () => {
        render(<IndexPage />);

        const call = commonSearchBoxSpy.mock.calls[0][0];
        expect(call.elements.find((el: any) => el.id === 'type')).toBeTruthy();
        expect(call.elements.find((el: any) => el.id === 'status')).toBeTruthy();
    });

    it('renders requester, type, dates, and day count columns for a row', () => {
        render(<IndexPage />);

        expect(screen.getByText('Alice (A-100)')).toBeInTheDocument();
        expect(screen.getByText('type:paid_leave')).toBeInTheDocument();
        expect(screen.getByText('formatted:2026-08-06 ~ formatted:2026-08-10')).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument();
    });

    it('shows a dash for decided_by and decided_at on a pending request', () => {
        render(<IndexPage />);

        const dashes = screen.getAllByText('-');
        expect(dashes.length).toBeGreaterThanOrEqual(2);
    });

    it('links the decided_by name to the decider on a decided request', () => {
        useGetAdminLeaveRequestsQueryMock.mockReturnValue({
            data: {
                data: [
                    makeRequest({
                        status: 'rejected',
                        decided_by_id: 55,
                        decided_by_name: 'Manager Tanaka',
                        decided_at: '2026-08-02T09:00:00+09:00',
                        reject_reason: 'Coverage already thin',
                    }),
                ],
                total: 1,
                current_page: 1,
                last_page: 1,
                per_page: 15,
            },
            isPending: false,
        });

        render(<IndexPage />);

        const link = screen.getByText('Manager Tanaka');
        expect(link).toHaveAttribute('href', '/admin/user/55');
    });

    it('shows an overlap badge when the row is flagged', () => {
        useGetAdminLeaveRequestsQueryMock.mockReturnValue({
            data: { data: [makeRequest({ has_overlap: true })], total: 1, current_page: 1, last_page: 1, per_page: 15 },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getByText('Overlap')).toBeInTheDocument();
    });

    it('does not show an overlap badge when the row is not flagged', () => {
        render(<IndexPage />);

        expect(screen.queryByText('Overlap')).not.toBeInTheDocument();
    });

    it('shows -- for the day count on a dateless expense claim', () => {
        useGetAdminLeaveRequestsQueryMock.mockReturnValue({
            data: {
                data: [
                    makeRequest({
                        type: 'expense_claim',
                        start_date: null,
                        end_date: null,
                        leave_days: null,
                    }),
                ],
                total: 1,
                current_page: 1,
                last_page: 1,
                per_page: 15,
            },
            isPending: false,
        });

        render(<IndexPage />);

        expect(screen.getAllByText('-').length).toBeGreaterThan(0);
    });

    it('passes noPermission true to the data table and skips the query when the permission is absent', () => {
        usePermissionsMock.mockReturnValue({ ADMIN_LEAVE_MANAGE: false });

        render(<IndexPage />);

        expect(useGetAdminLeaveRequestsQueryMock).toHaveBeenCalledWith(routerMock.query, false);
        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
    });

    it('opens the detail modal when Detail is clicked', () => {
        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));

        expect(screen.getByTestId('detail-modal')).toHaveTextContent('id:1');
    });

    it('approving from the detail modal calls the approve mutation and notifies success', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useApproveAdminLeaveRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-approve'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith(1);
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });

    it('reject opens the reason modal and sends nothing until a reason is submitted', () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useRejectAdminLeaveRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        expect(screen.queryByTestId('modal-reject-submit')).not.toBeInTheDocument();

        fireEvent.click(screen.getByTestId('modal-open-reject'));

        expect(screen.getByTestId('modal-reject-submit')).toBeInTheDocument();
        expect(mutateAsync).not.toHaveBeenCalled();
    });

    it('hides the detail modal while the reject dialog is open and restores it on cancel', () => {
        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        expect(screen.getByTestId('detail-modal')).toHaveTextContent('id:1');

        fireEvent.click(screen.getByTestId('modal-open-reject'));

        // Only one of the two modals may be mounted at a time, otherwise the stacked
        // Mantine Modals leave the underlying one unclickable (the DGFC-133 bug).
        expect(screen.queryByTestId('detail-modal')).not.toBeInTheDocument();
        expect(screen.getByTestId('modal-reject-submit')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('modal-reject-cancel'));

        expect(screen.queryByTestId('modal-reject-submit')).not.toBeInTheDocument();
        expect(screen.getByTestId('detail-modal')).toHaveTextContent('id:1');
    });

    it('invalidates the list and detail queries and shows a specific message when approval hits a 409 conflict', async () => {
        const mutateAsync = vi
            .fn()
            .mockRejectedValue(new ApiError({ status: 409, message: 'ADMIN_LEAVE_REQUEST_NOT_PENDING', data: {} }));
        useApproveAdminLeaveRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-approve'));

        await waitFor(() => {
            expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['admin-leave-requests'] });
            expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['admin-leave-request-detail'] });
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({
                    color: 'red',
                    message: 'error|This request was already decided by someone else',
                }),
            );
        });
    });

    it('invalidates queries on a generic approve failure and shows the generic error message', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(new Error('network down'));
        useApproveAdminLeaveRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-approve'));

        await waitFor(() => {
            expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['admin-leave-requests'] });
            expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['admin-leave-request-detail'] });
            expect(notificationsMock.show).toHaveBeenCalledWith(
                expect.objectContaining({ color: 'red', message: 'error|Could not approve request' }),
            );
        });
    });

    it('submitting the reject modal calls the reject mutation with the id and reason', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useRejectAdminLeaveRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-open-reject'));
        fireEvent.click(screen.getByTestId('modal-reject-submit'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({ id: 1, reject_reason: 'Coverage already thin' });
            expect(notificationsMock.show).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' }));
        });
    });
});
