import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '@/helpers/api.helper';
import DeleteShiftModal from '@/pages/manage/shift/partials/update/delete_shift_modal';

const {
    useGetShiftDeleteImpactQueryMock,
    useDeleteShiftMutationMock,
    mutateAsyncMock,
    refetchMock,
    showSuccessMock,
    showErrorMock,
} = vi.hoisted(() => ({
    useGetShiftDeleteImpactQueryMock: vi.fn(),
    useDeleteShiftMutationMock: vi.fn(),
    mutateAsyncMock: vi.fn(),
    refetchMock: vi.fn(),
    showSuccessMock: vi.fn(),
    showErrorMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({
        t: (key: string, opts?: Record<string, unknown>) => {
            if (!opts) return key;
            return Object.entries(opts).reduce((r, [k, v]) => r.replace(`{{${k}}}`, String(v)), key);
        },
    }),
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children, title }: any) =>
        React.createElement(
            'div',
            { role: 'alert' },
            title ? React.createElement('strong', null, title) : null,
            React.createElement('div', null, children),
        ),
    Badge: ({ children }: any) => React.createElement('span', null, children),
    Button: ({ children, onClick, disabled, loading }: any) =>
        React.createElement('button', { type: 'button', onClick, disabled: disabled || loading }, children),
    Flex: ({ children }: any) => React.createElement('div', null, children),
    Group: ({ children }: any) => React.createElement('div', null, children),
    Loader: () => React.createElement('div', { 'data-testid': 'loader' }),
    Modal: ({ children, opened, title }: any) =>
        opened ? React.createElement('div', { 'data-testid': 'modal' }, title, children) : null,
    Stack: ({ children }: any) => React.createElement('div', null, children),
    Text: ({ children }: any) => React.createElement('p', null, children),
}));

vi.mock('@/helpers/notification.helper', () => ({
    default: { showSuccess: showSuccessMock, showError: showErrorMock },
}));

vi.mock('@/pages/manage/shift/api', () => ({
    useGetShiftDeleteImpactQuery: (...args: unknown[]) => useGetShiftDeleteImpactQueryMock(...args),
    useDeleteShiftMutation: (...args: unknown[]) => useDeleteShiftMutationMock(...args),
}));

const buildImpact = (overrides: Record<string, unknown> = {}) => ({
    shift_users: {
        total: 3,
        by_status: { pending: 1, approved: 2, rejected: 0 },
        fixed: 1,
        temp: 2,
        users: [
            { id: 11, name: 'TANAKA TARO', status: 'approved', is_fixed: true },
            { id: 12, name: 'SUZUKI HANAKO', status: 'pending', is_fixed: false },
            { id: 13, name: null, status: 'approved', is_fixed: false },
        ],
    },
    check_ins: { total: 0, checked_out: 0 },
    shift_reports: 1,
    shift_absent_notices: 2,
    shift_plan_drafts: 3,
    chat_conversations: 1,
    recruitment_notifications: 4,
    blocked: false,
    block_reason: null,
    ...overrides,
});

const baseProps = {
    isOpen: true,
    onClose: vi.fn(),
    shiftId: '42',
    onDeleted: vi.fn(),
};

describe('DeleteShiftModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mutateAsyncMock.mockResolvedValue({ data: { id: 42 } });
        refetchMock.mockResolvedValue(undefined);
        useDeleteShiftMutationMock.mockReturnValue({ mutateAsync: mutateAsyncMock, isPending: false });
        useGetShiftDeleteImpactQueryMock.mockReturnValue({
            data: { data: buildImpact() },
            isLoading: false,
            isError: false,
            refetch: refetchMock,
        });
    });

    it('only fetches the impact while open, for the given shift', () => {
        render(<DeleteShiftModal {...baseProps} isOpen={false} />);

        expect(useGetShiftDeleteImpactQueryMock).toHaveBeenCalledWith('42', false);
        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('renders every impact figure and the affected employees', () => {
        render(<DeleteShiftModal {...baseProps} />);

        expect(screen.getByText('Registrations: 3 (pending 1, approved 2, rejected 0)')).toBeInTheDocument();
        expect(screen.getByText('Fixed staff: 1, temporary staff: 2')).toBeInTheDocument();
        expect(screen.getByText('Attendance records: 0 (0 already checked out)')).toBeInTheDocument();
        expect(screen.getByText('Shift reports: 1')).toBeInTheDocument();
        expect(screen.getByText('Absent notices: 2')).toBeInTheDocument();
        expect(screen.getByText('Shift planner drafts that will be discarded: 3')).toBeInTheDocument();
        expect(screen.getByText('Chat conversations that will be closed: 1')).toBeInTheDocument();
        expect(
            screen.getByText('Sent recruitment notifications that will point to a deleted shift: 4'),
        ).toBeInTheDocument();
        expect(screen.getByText('TANAKA TARO')).toBeInTheDocument();
        expect(screen.getByText('SUZUKI HANAKO')).toBeInTheDocument();
        // A user without a resolvable name still gets a stable label.
        expect(screen.getByText('#13')).toBeInTheDocument();
        expect(screen.getByText('This action cannot be undone')).toBeInTheDocument();
        expect(screen.queryByText(/and \d+ more/)).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeEnabled();
    });

    it('shows how many employees were left out of the capped list', () => {
        useGetShiftDeleteImpactQueryMock.mockReturnValue({
            data: {
                data: buildImpact({
                    shift_users: {
                        total: 25,
                        by_status: { pending: 25, approved: 0, rejected: 0 },
                        fixed: 0,
                        temp: 25,
                        users: [{ id: 1, name: 'ONLY ONE', status: 'pending', is_fixed: false }],
                    },
                }),
            },
            isLoading: false,
            isError: false,
            refetch: refetchMock,
        });

        render(<DeleteShiftModal {...baseProps} />);

        expect(screen.getByText('and 24 more')).toBeInTheDocument();
    });

    it('deletes on confirm, notifies and hands navigation back to the page', async () => {
        const user = userEvent.setup();
        render(<DeleteShiftModal {...baseProps} />);

        await user.click(screen.getByRole('button', { name: 'Delete' }));

        await waitFor(() => expect(mutateAsyncMock).toHaveBeenCalledTimes(1));
        expect(useDeleteShiftMutationMock).toHaveBeenCalledWith('42');
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully deleted shift');
        expect(baseProps.onDeleted).toHaveBeenCalledTimes(1);
    });

    it('disables confirm and explains the reason when the delete is blocked by attendance', async () => {
        const user = userEvent.setup();
        useGetShiftDeleteImpactQueryMock.mockReturnValue({
            data: {
                data: buildImpact({
                    check_ins: { total: 2, checked_out: 1 },
                    blocked: true,
                    block_reason: 'has_check_ins',
                }),
            },
            isLoading: false,
            isError: false,
            refetch: refetchMock,
        });

        render(<DeleteShiftModal {...baseProps} />);

        expect(
            screen.getByText('This shift already has attendance (check-in) records, so it cannot be deleted.'),
        ).toBeInTheDocument();
        expect(screen.getByText('Attendance records: 2 (1 already checked out)')).toBeInTheDocument();
        const confirm = screen.getByRole('button', { name: 'Delete' });
        expect(confirm).toBeDisabled();
        await user.click(confirm);
        expect(mutateAsyncMock).not.toHaveBeenCalled();
    });

    it('explains the ended-with-approved-staff block reason', () => {
        useGetShiftDeleteImpactQueryMock.mockReturnValue({
            data: { data: buildImpact({ blocked: true, block_reason: 'ended_with_approved' }) },
            isLoading: false,
            isError: false,
            refetch: refetchMock,
        });

        render(<DeleteShiftModal {...baseProps} />);

        expect(
            screen.getByText('This shift has already ended with approved staff, so it cannot be deleted.'),
        ).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();
    });

    it('keeps confirm disabled while the impact is still loading', () => {
        useGetShiftDeleteImpactQueryMock.mockReturnValue({
            data: undefined,
            isLoading: true,
            isError: false,
            refetch: refetchMock,
        });

        render(<DeleteShiftModal {...baseProps} />);

        expect(screen.getByTestId('loader')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();
    });

    it('shows the attendance error and re-checks the impact when the API refuses the delete', async () => {
        const user = userEvent.setup();
        mutateAsyncMock.mockRejectedValue(
            new ApiError({
                status: 400,
                message: 'SHIFT_CANNOT_DELETE_HAS_ATTENDANCE',
                data: { block_reason: 'has_check_ins' },
            }),
        );

        render(<DeleteShiftModal {...baseProps} />);

        await user.click(screen.getByRole('button', { name: 'Delete' }));

        await waitFor(() =>
            expect(showErrorMock).toHaveBeenCalledWith(
                'error|Shift cannot be deleted because it already has attendance data',
            ),
        );
        expect(refetchMock).toHaveBeenCalledTimes(1);
        expect(baseProps.onDeleted).not.toHaveBeenCalled();
    });

    it('falls back to a generic error for other failures', async () => {
        const user = userEvent.setup();
        mutateAsyncMock.mockRejectedValue(new ApiError({ status: 500, message: 'E_INTERNAL', data: {} }));

        render(<DeleteShiftModal {...baseProps} />);

        await user.click(screen.getByRole('button', { name: 'Delete' }));

        await waitFor(() => expect(showErrorMock).toHaveBeenCalledWith('error|Could not delete shift'));
    });

    it('closes without deleting on cancel', async () => {
        const user = userEvent.setup();
        render(<DeleteShiftModal {...baseProps} />);

        await user.click(screen.getByRole('button', { name: 'Cancel' }));

        expect(baseProps.onClose).toHaveBeenCalledTimes(1);
        expect(mutateAsyncMock).not.toHaveBeenCalled();
    });
});
