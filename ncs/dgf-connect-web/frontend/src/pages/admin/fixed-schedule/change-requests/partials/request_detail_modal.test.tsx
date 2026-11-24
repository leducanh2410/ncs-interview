import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { FixedScheduleChangeRequest } from '@/types/models/fixed_schedule_change_request';
import RequestDetailModal from './request_detail_modal';

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({
        t: (key: string, opts?: any) => (opts?.count !== undefined ? `${key}:${opts.count}` : key),
    }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { 'data-testid': 'warning-alert' }, children),
    Badge: ({ children }: any) => React.createElement('span', { 'data-testid': 'badge' }, children),
    Box: ({ children }: any) => React.createElement('div', null, children),
    Button: ({ children, onClick, loading }: any) =>
        React.createElement('button', { onClick, disabled: loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Modal: ({ children, title }: any) => React.createElement('div', { 'data-testid': 'modal' }, [title, children]),
    Text: ({ children }: any) => React.createElement('span', null, children),
}));

vi.mock('@/helpers/date.helper', () => ({
    default: {
        getDayOfWeek: (day: number) => `day${day}`,
        formatWorkHour: (start: string, end: string) => ({ startTime: start, endTime: end }),
    },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: {
        getText: (_enumObj: any, value: string) => value,
        getColor: () => 'gray',
    },
}));

const makeRequest = (overrides: Partial<FixedScheduleChangeRequest> = {}): FixedScheduleChangeRequest => ({
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
            current_workplace_position: { id: 31, name: 'Picking' },
            requested_workplace_shift: { id: 2, start_time: '13:00:00', end_time: '21:00:00' },
        },
        {
            id: 101,
            day: 3,
            current_workplace_shift: { id: 1, start_time: '09:00:00', end_time: '18:00:00' },
            current_workplace_position: null,
            requested_workplace_shift: null,
        },
    ],
    ...overrides,
});

describe('RequestDetailModal', () => {
    it('renders nothing when request is null', () => {
        const { container } = render(
            <RequestDetailModal request={null} onClose={vi.fn()} onApprove={vi.fn()} onReject={vi.fn()} canProcess />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows every changed day carried by the request', () => {
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.getByText(/day1/)).toBeInTheDocument();
        expect(screen.getByText(/day3/)).toBeInTheDocument();
        expect(screen.getByText('Day off')).toBeInTheDocument();
    });

    it('shows the current position of each day, with a dash when there is none', () => {
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.getByText('Picking')).toBeInTheDocument();
        expect(screen.getByText('-')).toBeInTheDocument();
    });

    it('labels the columns so the position is read as the current schedule, not the request time', () => {
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.getByText('Shift at request time')).toBeInTheDocument();
        expect(screen.getByText('Current position in schedule')).toBeInTheDocument();
        expect(screen.getByText('Requested shift')).toBeInTheDocument();
    });

    it('shows the immediate-effect warning before approve/reject actions', () => {
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.getByTestId('warning-alert')).toBeInTheDocument();
    });

    it('calls onApprove when Approve is clicked', () => {
        const onApprove = vi.fn();
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={onApprove}
                onReject={vi.fn()}
                canProcess
            />,
        );

        fireEvent.click(screen.getByText('Approve'));

        expect(onApprove).toHaveBeenCalled();
    });

    it('calls onReject when Reject is clicked', () => {
        const onReject = vi.fn();
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={onReject}
                canProcess
            />,
        );

        fireEvent.click(screen.getByText('Reject'));

        expect(onReject).toHaveBeenCalled();
    });

    it('hides approve/reject actions when the request is not pending', () => {
        render(
            <RequestDetailModal
                request={makeRequest({ status: 'approved' })}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess
            />,
        );

        expect(screen.queryByText('Approve')).not.toBeInTheDocument();
        expect(screen.queryByText('Reject')).not.toBeInTheDocument();
    });

    it('hides approve/reject actions when the admin lacks process permission', () => {
        render(
            <RequestDetailModal
                request={makeRequest()}
                onClose={vi.fn()}
                onApprove={vi.fn()}
                onReject={vi.fn()}
                canProcess={false}
            />,
        );

        expect(screen.queryByText('Approve')).not.toBeInTheDocument();
    });
});
