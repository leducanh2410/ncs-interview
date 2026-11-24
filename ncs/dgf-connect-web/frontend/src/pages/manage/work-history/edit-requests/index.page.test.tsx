import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import IndexPage from '@/pages/manage/work-history/edit-requests/index.page';

const {
    dataTableSpy,
    commonSearchBoxSpy,
    useGetWorkHistoryEditRequestsQueryMock,
    useApproveWorkHistoryEditRequestMutationMock,
    useRejectWorkHistoryEditRequestMutationMock,
    useBulkResolveWorkHistoryEditRequestsMutationMock,
    usePermissionsMock,
    showSuccessMock,
    showErrorMock,
    routerMock,
    filterWorkplacesByAccessMock,
    invalidateQueriesMock,
} = vi.hoisted(() => ({
    dataTableSpy: vi.fn(),
    commonSearchBoxSpy: vi.fn(),
    useGetWorkHistoryEditRequestsQueryMock: vi.fn(),
    useApproveWorkHistoryEditRequestMutationMock: vi.fn(),
    useRejectWorkHistoryEditRequestMutationMock: vi.fn(),
    useBulkResolveWorkHistoryEditRequestsMutationMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
    routerMock: { query: {} as Record<string, any>, push: vi.fn() },
    filterWorkplacesByAccessMock: vi.fn((..._args: unknown[]): any[] => []),
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

vi.mock('jotai', () => ({
    useAtom: () => [{ workplaces: [{ id: 10, name: 'Tokyo HQ' }], workplace_access: {} }, vi.fn()],
    atom: (init: any) => ({ init }),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/manage/work-history/api', () => ({
    useApproveWorkHistoryEditRequestMutation: () => useApproveWorkHistoryEditRequestMutationMock(),
}));

vi.mock('@/pages/manage/work-history/edit-requests/api', () => ({
    EDIT_REQUESTS_QUERY_KEY: ['work-history-edit-requests'],
    useGetWorkHistoryEditRequestsQuery: (...args: unknown[]) => useGetWorkHistoryEditRequestsQueryMock(...args),
    useRejectWorkHistoryEditRequestMutation: () => useRejectWorkHistoryEditRequestMutationMock(),
    useBulkResolveWorkHistoryEditRequestsMutation: () => useBulkResolveWorkHistoryEditRequestsMutationMock(),
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/request_detail_modal', () => ({
    default: ({ request, onApprove, onApproveAnyway, onReject, onClose, conflicts }: any) =>
        request
            ? React.createElement(
                  'div',
                  { 'data-testid': 'detail-modal' },
                  React.createElement('span', null, `items:${request.items.length}`),
                  conflicts &&
                      React.createElement(
                          'span',
                          { 'data-testid': 'detail-conflicts' },
                          conflicts.map((conflict: any) => `${conflict.field}:${conflict.current_value}`).join(','),
                      ),
                  conflicts &&
                      React.createElement(
                          'button',
                          { 'onClick': onApproveAnyway, 'data-testid': 'modal-approve-anyway' },
                          'Apply anyway',
                      ),
                  React.createElement('button', { 'onClick': onApprove, 'data-testid': 'modal-approve' }, 'Approve'),
                  React.createElement('button', { 'onClick': onReject, 'data-testid': 'modal-reject' }, 'Reject'),
                  React.createElement('button', { 'onClick': onClose, 'data-testid': 'modal-close' }, 'Close'),
              )
            : null,
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/reject_request_modal', () => ({
    default: ({ opened, onSubmit }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'reject-modal' },
                  React.createElement(
                      'button',
                      { 'onClick': () => onSubmit('Không khớp camera'), 'data-testid': 'reject-submit' },
                      'Submit reject',
                  ),
              )
            : null,
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/bulk_reject_modal', () => ({
    default: ({ opened, count, onSubmit }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'bulk-reject-modal' },
                  React.createElement('span', null, `count:${count}`),
                  React.createElement(
                      'button',
                      { 'onClick': () => onSubmit('Thiếu bằng chứng'), 'data-testid': 'bulk-reject-submit' },
                      'Submit bulk reject',
                  ),
              )
            : null,
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/import_modal', () => ({
    default: ({ opened, onClose, onPreviewReady }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'import-modal' },
                  React.createElement(
                      'button',
                      {
                          'onClick': () => onPreviewReady([{ check_in_id: 1, employee_name: 'Yamada', items: [] }]),
                          'data-testid': 'import-preview-ready',
                      },
                      'Preview ready',
                  ),
                  React.createElement('button', { 'onClick': onClose, 'data-testid': 'import-modal-close' }, 'Close'),
              )
            : null,
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/import_review_modal', () => ({
    default: ({ rows, onClose, onConfirmed }: any) =>
        rows.length > 0
            ? React.createElement(
                  'div',
                  { 'data-testid': 'import-review-modal' },
                  React.createElement('span', null, `rows:${rows.length}`),
                  React.createElement(
                      'button',
                      { 'onClick': onConfirmed, 'data-testid': 'import-review-confirmed' },
                      'Confirmed',
                  ),
                  React.createElement('button', { 'onClick': onClose, 'data-testid': 'import-review-close' }, 'Close'),
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
            { 'data-testid': 'bulk-approve-modal' },
            React.createElement('span', null, message),
            React.createElement('button', { 'onClick': onConfirm, 'data-testid': 'bulk-approve-confirm' }, 'Confirm'),
            React.createElement('button', { 'onClick': onCancel, 'data-testid': 'bulk-approve-cancel' }, 'Cancel'),
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

vi.mock('@/pages/manage/work-history/edit-requests/partials/edit_request_items', () => ({
    fieldLabel: (_t: any, field: string) => field,
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (v: string) => `formatted:${v}` },
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

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/helpers/workplace.helper', () => ({
    default: {
        toSelectOptions: (workplaces: any) => workplaces.map((w: any) => ({ value: String(w.id), label: w.name })),
        filterWorkplacesByAccess: (...args: unknown[]) => filterWorkplacesByAccessMock(...args),
    },
}));

vi.mock('@/configs/route.config', () => ({
    default: { HOME: { path: '/' } },
}));

const makeRequest = (overrides: Record<string, any> = {}) => ({
    id: 1,
    status: 'pending',
    check_in: { id: 5, user: { name: 'Alice' }, shift: { date: '2026-08-20' } },
    workplace: { id: 10, name: 'Tokyo HQ' },
    items: [{ id: 1, field: 'check_in_time', old_value: '09:00:00', new_value: '09:30:00', reason: 'Late tap' }],
    created_at: '2026-08-21T00:00:00Z',
    processed_by: null,
    processed_at: null,
    reject_reason: null,
    ...overrides,
});

const allPerms = { WORK_HISTORY_EDIT_REQUEST_PROCESS: true };

describe('Work history edit requests page', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        routerMock.query = {};

        usePermissionsMock.mockReturnValue(allPerms);
        filterWorkplacesByAccessMock.mockReturnValue([{ id: 10, name: 'Tokyo HQ' }]);
        useGetWorkHistoryEditRequestsQueryMock.mockReturnValue({
            data: { data: [makeRequest()], total: 1, current_page: 1, last_page: 1, per_page: 15 },
            isPending: false,
        });
        useApproveWorkHistoryEditRequestMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useRejectWorkHistoryEditRequestMutationMock.mockReturnValue({
            mutateAsync: vi.fn().mockResolvedValue(undefined),
            isPending: false,
        });
        useBulkResolveWorkHistoryEditRequestsMutationMock.mockReturnValue({
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

    it('scopes the Workplace filter via the work_history access group', () => {
        render(<IndexPage />);

        expect(filterWorkplacesByAccessMock).toHaveBeenCalledWith(expect.anything(), expect.anything(), 'work_history');
    });

    it('includes workplace, employee, status, and submitted-date-range filters', () => {
        render(<IndexPage />);

        const call = commonSearchBoxSpy.mock.calls[0][0];
        expect(call.elements.map((el: any) => el.id)).toEqual(
            expect.arrayContaining(['workplace_id', 'employee_name', 'status', 'dates']),
        );
    });

    it('passes noPermission=true to the table without process permission', () => {
        usePermissionsMock.mockReturnValue({ WORK_HISTORY_EDIT_REQUEST_PROCESS: false });

        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(expect.objectContaining({ noPermission: true }));
    });

    it('only allows selecting pending rows', () => {
        render(<IndexPage />);

        expect(dataTableSpy).toHaveBeenCalledWith(
            expect.objectContaining({ isRecordSelectable: expect.any(Function) }),
        );
        const { isRecordSelectable } = dataTableSpy.mock.calls[0][0];
        expect(isRecordSelectable(makeRequest({ status: 'pending' }))).toBe(true);
        expect(isRecordSelectable(makeRequest({ status: 'approved' }))).toBe(false);
    });

    it('opens the detail modal showing every changed item', () => {
        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));

        expect(screen.getByTestId('detail-modal')).toHaveTextContent('items:1');
    });

    it('approving from the detail modal calls the approve mutation', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useApproveWorkHistoryEditRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-approve'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({ id: 1, force: false });
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully approved request');
    });

    it('keeps the detail modal open with the conflicting values and can force-approve from there', async () => {
        const mutateAsync = vi
            .fn()
            .mockRejectedValueOnce(
                new ApiError({
                    status: 409,
                    message: 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED',
                    data: {
                        conflicts: [
                            {
                                field: 'check_out_time',
                                old_value: '2026-05-10 17:30:00',
                                current_value: '2026-05-10 17:50:00',
                                new_value: '2026-05-10 18:00:00',
                            },
                        ],
                    },
                }),
            )
            .mockResolvedValueOnce(undefined);
        useApproveWorkHistoryEditRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-approve'));

        await waitFor(() => {
            expect(screen.getByTestId('detail-conflicts')).toHaveTextContent('check_out_time:2026-05-10 17:50:00');
        });
        expect(showErrorMock).not.toHaveBeenCalled();

        fireEvent.click(screen.getByTestId('modal-approve-anyway'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenLastCalledWith({ id: 1, force: true });
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully approved request');
        expect(screen.queryByTestId('detail-modal')).not.toBeInTheDocument();
    });

    it('shows a distinct message and refetches the list when approve fails with a data-changed conflict', async () => {
        const mutateAsync = vi
            .fn()
            .mockRejectedValue(
                new ApiError({ status: 409, message: 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED', data: {} }),
            );
        useApproveWorkHistoryEditRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-approve'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith(
                'error|The check-in data has changed since this request was submitted',
            );
        });
        expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['work-history-edit-requests'] });
        // The detail modal closes rather than keep the approver retrying against the same stale
        // request.
        expect(screen.queryByTestId('detail-modal')).not.toBeInTheDocument();
    });

    it('rejecting from the detail modal requires a reason and calls the reject mutation with it', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useRejectWorkHistoryEditRequestMutationMock.mockReturnValue({ mutateAsync, isPending: false });

        render(<IndexPage />);

        fireEvent.click(screen.getByText('Detail'));
        fireEvent.click(screen.getByTestId('modal-reject'));
        expect(screen.getByTestId('reject-modal')).toBeInTheDocument();

        fireEvent.click(screen.getByTestId('reject-submit'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({ id: 1, reject_reason: 'Không khớp camera' });
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
        useBulkResolveWorkHistoryEditRequestsMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetWorkHistoryEditRequestsQueryMock.mockReturnValue({
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
        fireEvent.click(screen.getByTestId('bulk-approve-confirm'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({ ids: [1, 2], action: 'approve' });
        });
    });

    it('shows a distinct message and refetches the list when bulk approve fails with a data-changed conflict', async () => {
        const mutateAsync = vi
            .fn()
            .mockRejectedValue(
                new ApiError({ status: 409, message: 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED', data: {} }),
            );
        useBulkResolveWorkHistoryEditRequestsMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetWorkHistoryEditRequestsQueryMock.mockReturnValue({
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
        fireEvent.click(screen.getByTestId('bulk-approve-confirm'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith(
                'error|The check-in data has changed since this request was submitted',
            );
        });
        expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['work-history-edit-requests'] });
    });

    it('names the employee and date of the request that aborted a bulk approve', async () => {
        const mutateAsync = vi.fn().mockRejectedValue(
            new ApiError({
                status: 409,
                message: 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED',
                data: { request_id: 2, employee_name: 'Tanaka', date: '2026/5/10', conflicts: [] },
            }),
        );
        useBulkResolveWorkHistoryEditRequestsMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetWorkHistoryEditRequestsQueryMock.mockReturnValue({
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
        fireEvent.click(screen.getByTestId('bulk-approve-confirm'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Request for {{employee}} on {{date}}: {{reason}}');
        });
    });

    it('bulk reject requires a shared reason and calls the bulk-resolve mutation with it for every selected id', async () => {
        const mutateAsync = vi.fn().mockResolvedValue(undefined);
        useBulkResolveWorkHistoryEditRequestsMutationMock.mockReturnValue({ mutateAsync, isPending: false });
        useGetWorkHistoryEditRequestsQueryMock.mockReturnValue({
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
        fireEvent.click(screen.getByText(/Reject .* requests/));
        expect(screen.getByTestId('bulk-reject-modal')).toHaveTextContent('count:2');

        fireEvent.click(screen.getByTestId('bulk-reject-submit'));

        await waitFor(() => {
            expect(mutateAsync).toHaveBeenCalledWith({
                ids: [1, 2],
                action: 'reject',
                reject_reason: 'Thiếu bằng chứng',
            });
        });
    });
});
