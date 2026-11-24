import React from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { CheckIn } from '@/types/models/check_in';
import { ApiError } from '@/helpers/api.helper';
import ApproveEditRequestModal from '@/pages/manage/work-history/partials/approve_edit_request_modal';

const { approveMutateAsync, rejectMutateAsync, showSuccessMock, showErrorMock, invalidateQueriesMock } = vi.hoisted(
    () => ({
        approveMutateAsync: vi.fn(),
        rejectMutateAsync: vi.fn(),
        showSuccessMock: vi.fn(),
        showErrorMock: vi.fn(),
        invalidateQueriesMock: vi.fn(),
    }),
);

vi.mock('@tanstack/react-query', () => ({
    useQueryClient: () => ({ invalidateQueries: invalidateQueriesMock }),
}));

vi.mock('react-i18next', () => ({
    initReactI18next: { type: '3rdParty', init: () => undefined },
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@/locales/i18n', () => ({
    default: { t: (key: string) => key },
}));

vi.mock('@mantine/core', () => ({
    Alert: ({ children }: any) => React.createElement('div', { role: 'alert' }, children),
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

vi.mock('@/helpers/notification.helper', () => ({
    default: {
        showSuccess: (...args: unknown[]) => showSuccessMock(...args),
        showError: (...args: unknown[]) => showErrorMock(...args),
    },
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/edit_request_items', () => ({
    default: ({ items }: any) =>
        React.createElement(
            'ul',
            { 'data-testid': 'items' },
            items.map((item: any, i: number) => React.createElement('li', { key: i }, `${item.field}:${item.reason}`)),
        ),
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/edit_request_conflicts', () => ({
    default: ({ conflicts }: any) =>
        React.createElement(
            'ul',
            { 'data-testid': 'conflicts' },
            conflicts.map((conflict: any, i: number) =>
                React.createElement('li', { key: i }, `${conflict.field}:${conflict.current_value}`),
            ),
        ),
}));

vi.mock('@/pages/manage/work-history/api', () => ({
    useApproveWorkHistoryEditRequestMutation: () => ({ mutateAsync: approveMutateAsync, isPending: false }),
}));

vi.mock('@/pages/manage/work-history/edit-requests/api', () => ({
    useRejectWorkHistoryEditRequestMutation: () => ({ mutateAsync: rejectMutateAsync, isPending: false }),
}));

vi.mock('@/pages/manage/work-history/edit-requests/partials/reject_request_modal', () => ({
    default: ({ opened, onSubmit }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'reject-modal' },
                  React.createElement(
                      'button',
                      {
                          // Mirrors the real RejectRequestModal's handleSubmit, which already
                          // catches a rejected onSubmit so the caller's rethrow doesn't surface
                          // as an unhandled rejection here.
                          'onClick': () => {
                              onSubmit('Not enough evidence').catch(() => undefined);
                          },
                          'data-testid': 'reject-submit',
                      },
                      'Submit reject',
                  ),
              )
            : null,
}));

const makeCheckIn = (overrides: Record<string, any> = {}) =>
    ({
        id: 5,
        user: { name: 'Test User' },
        shift: { workplace: { name: 'Test Workplace' } },
        latest_edit_request: {
            id: 42,
            status: 'pending',
            items: [
                { id: 1, field: 'check_in_time', old_value: '09:00:00', new_value: '09:30:00', reason: 'Late tap' },
            ],
        },
        ...overrides,
    }) as unknown as CheckIn;

describe('ApproveEditRequestModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        approveMutateAsync.mockResolvedValue({ data: { id: 42, status: 'approved' } });
        rejectMutateAsync.mockResolvedValue({ data: { id: 42, status: 'rejected' } });
    });

    it('renders nothing when checkIn is null', () => {
        const { container } = render(<ApproveEditRequestModal checkIn={null} onClose={vi.fn()} />);
        expect(container).toBeEmptyDOMElement();
    });

    it('renders nothing when the checkIn has no latest_edit_request', () => {
        const { container } = render(
            <ApproveEditRequestModal checkIn={makeCheckIn({ latest_edit_request: undefined })} onClose={vi.fn()} />,
        );
        expect(container).toBeEmptyDOMElement();
    });

    it('shows the changed fields and reasons before the approve action can be confirmed', () => {
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={vi.fn()} />);

        expect(screen.getByTestId('items')).toHaveTextContent('check_in_time:Late tap');
    });

    it('calls the approve mutation with the request id and closes on success', async () => {
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Approve'));

        await waitFor(() => {
            expect(approveMutateAsync).toHaveBeenCalledWith({ id: 42, force: false });
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully approved request');
        expect(onClose).toHaveBeenCalled();
    });

    it('shows an error notification and keeps the modal open when the approve call fails', async () => {
        approveMutateAsync.mockRejectedValue(new Error('conflict'));
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Approve'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not approve request');
        });
        expect(onClose).not.toHaveBeenCalled();
    });

    it('shows a distinct message, refetches work history, and closes when the approve call fails with a data-changed conflict', async () => {
        approveMutateAsync.mockRejectedValue(
            new ApiError({ status: 409, message: 'WORK_HISTORY_EDIT_REQUEST_DATA_CHANGED', data: {} }),
        );
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Approve'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith(
                'error|The check-in data has changed since this request was submitted',
            );
        });
        expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['work-history'] });
        expect(onClose).toHaveBeenCalled();
    });

    it('shows the conflicting values and lets the approver apply the request anyway', async () => {
        approveMutateAsync.mockRejectedValueOnce(
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
        );
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Approve'));

        await waitFor(() => {
            expect(screen.getByTestId('conflicts')).toHaveTextContent('check_out_time:2026-05-10 17:50:00');
        });
        expect(showErrorMock).not.toHaveBeenCalled();
        expect(onClose).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('Apply requested values anyway'));

        await waitFor(() => {
            expect(approveMutateAsync).toHaveBeenLastCalledWith({ id: 42, force: true });
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully approved request');
        expect(onClose).toHaveBeenCalled();
    });

    it('keeps the modal open so the request can be rejected when applying it would invert the check-in and check-out', async () => {
        approveMutateAsync.mockRejectedValue(
            new ApiError({ status: 409, message: 'WORK_HISTORY_EDIT_REQUEST_INVALID_TIME_ORDER', data: {} }),
        );
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Approve'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith(
                'error|Applying this request would put the check-out before the check-in. Please reject it and submit a new one',
            );
        });
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByText('Reject')).toBeInTheDocument();
    });

    it('tells the approver another admin already decided the request and refreshes the list', async () => {
        approveMutateAsync.mockRejectedValue(
            new ApiError({ status: 409, message: 'WORK_HISTORY_EDIT_REQUEST_NOT_PENDING', data: {} }),
        );
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Approve'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|This request was already decided by someone else');
        });
        expect(invalidateQueriesMock).toHaveBeenCalledWith({ queryKey: ['work-history'] });
        expect(onClose).toHaveBeenCalled();
    });

    it('closes without approving when Cancel is clicked', () => {
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Cancel'));

        expect(onClose).toHaveBeenCalled();
        expect(approveMutateAsync).not.toHaveBeenCalled();
    });

    it('opens the reject dialog and hides the approve modal when Reject is clicked', () => {
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={vi.fn()} />);

        fireEvent.click(screen.getByText('Reject'));

        expect(screen.getByTestId('reject-modal')).toBeInTheDocument();
        expect(screen.queryByTestId('modal')).not.toBeInTheDocument();
    });

    it('calls the reject mutation with the request id and reason and closes on success', async () => {
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Reject'));
        fireEvent.click(screen.getByTestId('reject-submit'));

        await waitFor(() => {
            expect(rejectMutateAsync).toHaveBeenCalledWith({ id: 42, reject_reason: 'Not enough evidence' });
        });
        expect(showSuccessMock).toHaveBeenCalledWith('Successfully rejected request');
        expect(onClose).toHaveBeenCalled();
    });

    it('shows an error notification and keeps the reject dialog open when the reject call fails', async () => {
        rejectMutateAsync.mockRejectedValue(new Error('conflict'));
        const onClose = vi.fn();
        render(<ApproveEditRequestModal checkIn={makeCheckIn()} onClose={onClose} />);

        fireEvent.click(screen.getByText('Reject'));
        fireEvent.click(screen.getByTestId('reject-submit'));

        await waitFor(() => {
            expect(showErrorMock).toHaveBeenCalledWith('error|Could not reject request');
        });
        expect(onClose).not.toHaveBeenCalled();
        expect(screen.getByTestId('reject-modal')).toBeInTheDocument();
    });
});
