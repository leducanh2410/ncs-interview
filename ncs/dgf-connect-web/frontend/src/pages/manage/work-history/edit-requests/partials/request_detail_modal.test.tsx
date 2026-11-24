import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { WorkHistoryEditRequest } from '@/types/models/work_history_edit_request';
import RequestDetailModal from '@/pages/manage/work-history/edit-requests/partials/request_detail_modal';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/core', () => ({
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'status-badge' }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, loading }: any) =>
        React.createElement('button', { onClick, 'aria-busy': loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, opened, title }: any) =>
        opened
            ? React.createElement('div', { 'data-testid': 'modal' }, React.createElement('h2', null, title), children)
            : null,
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_e: any, value: string) => value,
        getColor: () => 'gray',
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (v: string) => `formatted:${v}` },
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/edit_request_conflicts', () => ({
    default: ({ conflicts }: any) =>
        React.createElement('div', { 'data-testid': 'conflicts' }, conflicts.map((c: any) => c.field).join(',')),
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/edit_request_items', () => ({
    default: ({ items }: any) =>
        React.createElement(
            'ul',
            { 'data-testid': 'items' },
            items.map((item: any, i: number) => React.createElement('li', { key: i }, item.field)),
        ),
}));

const makeRequest = (overrides: Record<string, any> = {}) =>
    ({
        id: 1,
        status: 'pending',
        check_in: { id: 5, user: { name: 'Alice' } },
        workplace: { name: 'Tokyo HQ' },
        items: [{ id: 1, field: 'check_in_time', old_value: '09:00:00', new_value: '09:30:00', reason: 'Late tap' }],
        processed_by: null,
        processed_at: null,
        reject_reason: null,
        ...overrides,
    }) as unknown as WorkHistoryEditRequest;

describe('RequestDetailModal (work history edit requests)', () => {
    it('renders nothing when there is no request', () => {
        const { container } = render(
            <RequestDetailModal request={null} onClose={vi.fn()} onApprove={vi.fn()} onReject={vi.fn()} canProcess />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows every changed item before approve/reject are actionable', () => {
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.getByTestId('items')).toHaveTextContent('check_in_time');
        expect(screen.getByRole('button', { name: 'Approve' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Reject' })).toBeInTheDocument();
    });

    it('hides approve/reject actions when the viewer lacks process permission', () => {
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess={false}
            />,
        );

        expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Reject' })).not.toBeInTheDocument();
    });

    it('hides approve/reject actions once the request is already resolved', () => {
        render(
            <RequestDetailModal
                request={makeRequest({ status: 'approved', processed_by: { name: 'Bob' }, processed_at: '2026-08-22' })}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();
        expect(screen.getByText('Bob')).toBeInTheDocument();
    });

    it('shows the rejection reason for a rejected request', () => {
        render(
            <RequestDetailModal
                request={makeRequest({ status: 'rejected', reject_reason: 'Không khớp camera' })}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.getByText('Không khớp camera')).toBeInTheDocument();
    });

    it('calls onApprove/onReject when the actions are clicked', () => {
        const onApprove = vi.fn();
        const onReject = vi.fn();
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={onApprove}
                onReject={onReject}
                canProcess
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Approve' }));
        fireEvent.click(screen.getByRole('button', { name: 'Reject' }));

        expect(onApprove).toHaveBeenCalled();
        expect(onReject).toHaveBeenCalled();
    });

    it('shows the conflicting values and swaps Approve for an apply-anyway action', () => {
        const onApprove = vi.fn();
        const onApproveAnyway = vi.fn();
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={onApprove}
                onApproveAnyway={onApproveAnyway}
                onReject={vi.fn()}
                canProcess
                conflicts={[
                    {
                        field: 'check_out_time',
                        old_value: '2026-05-10 17:30:00',
                        current_value: '2026-05-10 17:50:00',
                        new_value: '2026-05-10 18:00:00',
                    },
                ]}
            />,
        );

        expect(screen.getByTestId('conflicts')).toHaveTextContent('check_out_time');
        expect(screen.queryByRole('button', { name: 'Approve' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Apply requested values anyway' }));

        expect(onApproveAnyway).toHaveBeenCalled();
        expect(onApprove).not.toHaveBeenCalled();
    });
});
