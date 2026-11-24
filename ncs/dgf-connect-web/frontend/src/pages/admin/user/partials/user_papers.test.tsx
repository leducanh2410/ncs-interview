import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { UserPaperItem } from '@/types/models/user_paper';
import { ApiError } from '@/helpers/api.helper';
import UserPapers from './user_papers';

const {
    useGetUserPapersQueryMock,
    approveMutateAsyncMock,
    extractMutateAsyncMock,
    usePermissionsMock,
    notificationsShowMock,
    requestCompletionMutateAsyncMock,
    reopenStepsMutateAsyncMock,
    reopenEmptiedStepsMutateAsyncMock,
    upcomingShiftsRefetchMock,
} = vi.hoisted(() => ({
    useGetUserPapersQueryMock: vi.fn(),
    approveMutateAsyncMock: vi.fn(),
    extractMutateAsyncMock: vi.fn(),
    usePermissionsMock: vi.fn(),
    notificationsShowMock: vi.fn(),
    requestCompletionMutateAsyncMock: vi.fn(),
    reopenStepsMutateAsyncMock: vi.fn(),
    reopenEmptiedStepsMutateAsyncMock: vi.fn(),
    upcomingShiftsRefetchMock: vi.fn(),
}));

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key: string) => key }),
}));

vi.mock('@mantine/hooks', () => ({
    useDisclosure: (initial = false) => {
        const [value, setValue] = React.useState(initial);
        return [value, { open: () => setValue(true), close: () => setValue(false) }];
    },
}));

vi.mock('@mantine/form', () => ({
    useForm: ({ initialValues }: any) => {
        const values = { ...initialValues };
        return {
            values,
            onSubmit: (fn: any) => (e: any) => {
                e?.preventDefault?.();
                return fn(values);
            },
            getInputProps: (field: string, opts?: any) => {
                if (opts?.type === 'checkbox') return { checked: values[field] };
                return {};
            },
            reset: vi.fn(),
            setValues: (v: any) => Object.assign(values, typeof v === 'function' ? v(values) : v),
        };
    },
}));

vi.mock('@mantine/core', () => {
    const ScrollArea: any = ({ children }: any) => React.createElement('div', null, children);
    ScrollArea.Autosize = ({ children }: any) => React.createElement('div', null, children);

    const Table: any = ({ children }: any) => React.createElement('table', null, children);
    Table.Thead = ({ children }: any) => React.createElement('thead', null, children);
    Table.Tbody = ({ children }: any) => React.createElement('tbody', null, children);
    Table.Tr = ({ children }: any) => React.createElement('tr', null, children);
    Table.Th = ({ children }: any) => React.createElement('th', null, children);
    Table.Td = ({ children }: any) => React.createElement('td', null, children);

    const Checkbox: any = ({ label, checked, onChange }: any) =>
        React.createElement('input', { 'type': 'checkbox', 'aria-label': label, checked, onChange });
    Checkbox.Group = ({ children }: any) => React.createElement('div', null, children);

    const Tabs: any = ({ children }: any) => React.createElement('div', null, children);
    Tabs.List = ({ children }: any) => React.createElement('div', null, children);
    Tabs.Tab = ({ children }: any) => React.createElement('button', null, children);
    Tabs.Panel = ({ children }: any) => React.createElement('div', null, children);

    return {
        ActionIcon: ({ children, onClick, disabled }: any) =>
            React.createElement('button', { type: 'button', onClick, disabled }, children),
        Anchor: ({ children, href }: any) => React.createElement('a', { href }, children),
        Badge: ({ children, color }: any) => React.createElement('span', { 'data-color': color }, children),
        Button: ({ children, type, onClick, disabled, loading }: any) =>
            React.createElement('button', { type: type ?? 'button', onClick, disabled: disabled || loading }, children),
        Checkbox,
        Flex: ({ children }: any) => React.createElement('div', null, children),
        Image: ({ src, alt }: any) => React.createElement('img', { src, alt }),
        Modal: ({ children, opened, title }: any) =>
            opened ? React.createElement('div', { 'data-testid': 'modal' }, children) : null,
        Paper: ({ children }: any) => React.createElement('div', null, children),
        ScrollArea,
        Stack: ({ children }: any) => React.createElement('div', null, children),
        Switch: ({ label, checked, onChange }: any) =>
            React.createElement('input', { 'type': 'checkbox', 'aria-label': label, checked, onChange }),
        Table,
        Tabs,
        Textarea: ({ label }: any) => React.createElement('textarea', { 'aria-label': label }),
        Text: ({ children }: any) => React.createElement('span', null, children),
        Title: ({ children }: any) => React.createElement('h3', null, children),
    };
});

vi.mock('@mantine/notifications', () => ({
    notifications: { show: (...args: unknown[]) => notificationsShowMock(...args) },
}));

vi.mock('@/helpers/api.helper', () => ({
    ApiError: class ApiError extends Error {
        constructor({ message }: { status: number; message: string; data: any }) {
            super(message);
        }
    },
}));

vi.mock('@/helpers/date.helper', () => ({
    default: { parseAndFormat: (d: string) => d ?? '' },
}));

vi.mock('@/helpers/enum.helper', () => ({
    default: { getText: (_: any, val: string) => val },
}));

vi.mock('@/helpers/form.helper', () => ({
    default: { setFormError: vi.fn() },
}));

vi.mock('@/helpers/url.helper', () => ({
    default: { getStaticUrl: (p: string) => `/static/${p}` },
}));

vi.mock('@/enums/error_code.enum', () => ({
    ErrorCodeEnum: {
        USER_FACE_APPROVAL_FAILED: { value: 'USER_FACE_APPROVAL_FAILED' },
        USER_PAPER_STUDENT_CARD_EXPIRY_MISSING: { value: 'USER_PAPER_STUDENT_CARD_EXPIRY_MISSING' },
    },
}));

vi.mock('@/enums/user_paper_status.enum', () => ({
    UserPaperStatusEnum: {
        WAIT_FOR_APPROVAL: { value: 'wait_for_approval', text: 'Wait for approval' },
        APPROVED: { value: 'approved', text: 'Approved' },
        REJECTED: { value: 'rejected', text: 'Rejected' },
        PENDING: { value: 'pending', text: 'Pending' },
    },
    getColor: () => 'teal',
}));

vi.mock('@/validator', () => {
    const chain: any = {};
    ['required', 'requiredIf', 'label'].forEach((m) => {
        chain[m] = () => chain;
    });
    return { Validator: { array: () => chain, string: () => chain }, validatorResolver: () => () => ({}) };
});

vi.mock('@/components/DConfirmModal', () => ({
    default: ({ opened, onConfirm, onCancel, title }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'confirm-modal' },
                  React.createElement('button', { type: 'button', onClick: onConfirm }, 'Confirm'),
                  React.createElement('button', { type: 'button', onClick: onCancel }, 'Cancel'),
              )
            : null,
}));

vi.mock('@/components/DDataTable', () => ({
    default: ({ columns, data }: any) =>
        React.createElement(
            'table',
            null,
            React.createElement(
                'tbody',
                null,
                (data ?? []).map((row: any, ri: number) =>
                    React.createElement(
                        'tr',
                        { key: ri },
                        (columns ?? []).map((col: any, ci: number) =>
                            React.createElement('td', { key: ci }, col.render ? col.render(row) : row[col.key]),
                        ),
                    ),
                ),
            ),
        ),
}));

vi.mock('./paper_missing_documents_alert', () => ({
    default: ({
        missingSteps,
        canRequestCompletion,
        onRequestCompletion,
        canReopenSteps,
        onReopenSteps,
        awaitingSteps,
    }: any) =>
        missingSteps?.length || awaitingSteps?.length
            ? React.createElement(
                  'div',
                  { 'data-testid': 'missing-documents-alert' },
                  canRequestCompletion
                      ? React.createElement('button', { onClick: onRequestCompletion }, 'Request document completion')
                      : null,
                  canReopenSteps ? React.createElement('button', { onClick: onReopenSteps }, 'reopen steps') : null,
                  missingSteps?.length
                      ? React.createElement('span', null, `not on file: ${missingSteps.join(',')}`)
                      : null,
                  awaitingSteps?.length
                      ? React.createElement('span', null, `awaiting: ${awaitingSteps.join(',')}`)
                      : null,
              )
            : null,
}));

vi.mock('./paper_emptied_steps_alert', () => ({
    default: ({ emptiedSteps, canReopen, onReopen }: any) =>
        emptiedSteps?.length
            ? React.createElement(
                  'div',
                  { 'data-testid': 'emptied-steps-alert' },
                  React.createElement('span', null, `emptied: ${emptiedSteps.join(',')}`),
                  canReopen ? React.createElement('button', { onClick: onReopen }, 'reopen emptied') : null,
              )
            : null,
}));

vi.mock('./paper_mismatch_alert', () => ({
    default: ({ mismatches }: any) =>
        mismatches?.length ? React.createElement('div', { 'data-testid': 'mismatch-alert' }, 'mismatch alert') : null,
}));

vi.mock('./paper_student_card_expiry_alert', () => ({
    default: ({ missing, canRequestCompletion, onRequestCompletion }: any) =>
        missing
            ? React.createElement(
                  'div',
                  { 'data-testid': 'student-card-expiry-alert' },
                  canRequestCompletion
                      ? React.createElement(
                            'button',
                            { onClick: onRequestCompletion },
                            'Ask for the student card again',
                        )
                      : null,
              )
            : null,
}));

vi.mock('./paper_completion_request_modal', () => ({
    COMPLETION_SHIFT_WINDOW_DAYS: 7,
    default: ({ opened, onConfirm, message }: any) =>
        opened
            ? React.createElement(
                  'div',
                  { 'data-testid': 'completion-request-modal' },
                  React.createElement('span', null, message ?? 'default message'),
                  React.createElement('button', { onClick: onConfirm }, 'confirm completion'),
              )
            : null,
}));

vi.mock('./paper_folders', () => ({
    default: ({ title, confirmedApprovalAt }: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'paper-folders', 'data-confirmed-approval-at': confirmedApprovalAt ?? '' },
            title,
        ),
}));

vi.mock('@/components/DAlert', () => ({
    default: ({ children }: any) => React.createElement('div', { 'data-testid': 'alert' }, children),
}));

vi.mock('@/pages/admin/user/partials/paper_ocr_result_view', () => ({
    default: ({ results, onRerun }: any) =>
        React.createElement(
            'div',
            { 'data-testid': 'ocr-result-view', 'data-groups': results.map((r: any) => r.group).join(',') },
            React.createElement('button', { type: 'button', onClick: onRerun }, 'Re-run'),
        ),
    OCR_ELIGIBLE_TYPES: [
        'foreigner_card_front',
        'foreigner_card_back',
        'foreigner_card_scan',
        'passport',
        'student_card_front',
        'student_card_back',
    ],
    // The real PaperOcrModal renders in the same tree and needs these named exports
    TYPE_SECTIONS: [],
    buildImageByType: () => new Map(),
}));

vi.mock('@/hooks/usePermission', () => ({
    usePermissions: (...args: unknown[]) => usePermissionsMock(...args),
}));

vi.mock('@/pages/admin/user/api', () => ({
    useGetUserPapersQuery: (...args: unknown[]) => useGetUserPapersQueryMock(...args),
    useApproveUserPaperMutation: () => ({ mutateAsync: approveMutateAsyncMock, isPending: false }),
    useGetUserQuery: () => ({ data: { data: {} } }),
    useExtractUserPapersOcrMutation: () => ({ mutateAsync: extractMutateAsyncMock, isPending: false }),
    useApplyUserPapersOcrMutation: () => ({ mutateAsync: vi.fn(), isPending: false }),
    useRequestPaperCompletionMutation: () => ({
        mutateAsync: requestCompletionMutateAsyncMock,
        isPending: false,
    }),
    useReopenPaperStepsMutation: () => ({
        mutateAsync: reopenStepsMutateAsyncMock,
        isPending: false,
    }),
    useReopenEmptiedPaperStepsMutation: () => ({
        mutateAsync: reopenEmptiedStepsMutateAsyncMock,
        isPending: false,
    }),
    useGetUserUpcomingShiftsQuery: () => ({
        data: { data: [] },
        isFetching: false,
        refetch: upcomingShiftsRefetchMock,
    }),
}));

vi.mock('react-icons/lu', () => ({
    LuCheck: () => React.createElement('span', null, '✓'),
    LuTrash2: () => React.createElement('span', null, '🗑'),
    LuScanText: () => React.createElement('span', null, 'LuScanText'),
    LuArrowLeft: () => React.createElement('span', null, 'LuArrowLeft'),
    LuRefreshCw: () => React.createElement('span', null, 'LuRefreshCw'),
    LuInfo: () => React.createElement('span', null, 'LuInfo'),
    LuTriangleAlert: () => React.createElement('span', null, 'LuTriangleAlert'),
}));

const makePaperItem = (id: number, overrides: Partial<UserPaperItem> = {}): UserPaperItem =>
    ({
        id,
        user_id: 1,
        type: 'passport',
        file_name: `file${id}.pdf`,
        file_path: `uploads/file${id}.pdf`,
        folder: 'current',
        approved_at: null,
        rejected_at: null,
        created_at: '2024-01-01T00:00:00Z',
        ...overrides,
    }) as UserPaperItem;

const makePaperData = (items: UserPaperItem[]) => ({
    items,
    rejected_note: null,
});

describe('UserPapers', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        usePermissionsMock.mockReturnValue({
            USER_PAPER_LIST: true,
            USER_PAPER_APPROVE: true,
            USER_PAPER_REJECT: true,
        });
        useGetUserPapersQueryMock.mockReturnValue({ data: { data: makePaperData([]) }, isPending: false });
        extractMutateAsyncMock.mockResolvedValue({ data: { results: [] } });
    });

    it('offers the completion request on an approved file that owes documents', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), missing_required_steps: ['work_permit'] } },
            isPending: false,
        });
        render(<UserPapers id="42" status="approved" />);

        screen.getByRole('button', { name: 'Request document completion' }).click();

        // Shifts are only read when the admin is about to act, so the tab stays cheap
        expect(upcomingShiftsRefetchMock).toHaveBeenCalledOnce();
        expect(await screen.findByTestId('completion-request-modal')).toBeInTheDocument();

        screen.getByRole('button', { name: 'confirm completion' }).click();
        await waitFor(() => expect(requestCompletionMutateAsyncMock).toHaveBeenCalledOnce());
    });

    it('reports a refused completion request instead of letting it escape', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), missing_required_steps: ['work_permit'] } },
            isPending: false,
        });
        // The file moved on since the tab loaded — someone else decided it first
        requestCompletionMutateAsyncMock.mockRejectedValueOnce(new Error('E_UNPROCESSABLE_ENTITY'));
        render(<UserPapers id="42" status="approved" />);

        screen.getByRole('button', { name: 'Request document completion' }).click();
        (await screen.findByRole('button', { name: 'confirm completion' })).click();

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith(expect.objectContaining({ color: 'red' })),
        );
        await waitFor(() => expect(screen.queryByTestId('completion-request-modal')).not.toBeInTheDocument());
    });

    it('asks the employee to resubmit documents whose images went missing', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), emptied_steps: ['work_permit'] } },
            isPending: false,
        });
        reopenEmptiedStepsMutateAsyncMock.mockResolvedValueOnce({
            data: { reopened_steps: ['work_permit'], cleared_steps: [] },
        });
        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.getByText('emptied: work_permit')).toBeInTheDocument();
        screen.getByRole('button', { name: 'reopen emptied' }).click();

        await waitFor(() => expect(reopenEmptiedStepsMutateAsyncMock).toHaveBeenCalledOnce());
        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith({
                color: 'teal',
                message: 'The employee was asked to resubmit the documents',
            }),
        );
    });

    it('tells the admin when the emptied documents were only unlocked', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), emptied_steps: ['residence_card'] } },
            isPending: false,
        });
        reopenEmptiedStepsMutateAsyncMock.mockResolvedValueOnce({
            data: { reopened_steps: [], cleared_steps: ['residence_card'] },
        });
        render(<UserPapers id="42" status="approved" />);

        screen.getByRole('button', { name: 'reopen emptied' }).click();

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith({
                color: 'teal',
                message: 'The documents were unlocked for the employee to upload again',
            }),
        );
    });

    it('reports a refused reopen of emptied documents', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), emptied_steps: ['work_permit'] } },
            isPending: false,
        });
        reopenEmptiedStepsMutateAsyncMock.mockRejectedValueOnce(new Error('E_UNPROCESSABLE_ENTITY'));
        render(<UserPapers id="42" status="pending" />);

        screen.getByRole('button', { name: 'reopen emptied' }).click();

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith(expect.objectContaining({ color: 'red' })),
        );
    });

    it('hides the emptied documents button from an admin who cannot approve', () => {
        usePermissionsMock.mockReturnValue({ USER_PAPER_LIST: true, USER_PAPER_APPROVE: false });
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), emptied_steps: ['work_permit'] } },
            isPending: false,
        });
        render(<UserPapers id="42" status="pending" />);

        expect(screen.getByText('emptied: work_permit')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'reopen emptied' })).not.toBeInTheDocument();
    });

    it('reopens the documents of a pending file stuck on images already on file', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: {
                    ...makePaperData([]),
                    missing_required_steps: ['student_card'],
                    reopenable_steps: ['student_card'],
                },
            },
            isPending: false,
        });
        render(<UserPapers id="42" status="pending" />);

        expect(screen.queryByRole('button', { name: 'Request document completion' })).not.toBeInTheDocument();
        screen.getByRole('button', { name: 'reopen steps' }).click();

        await waitFor(() => expect(reopenStepsMutateAsyncMock).toHaveBeenCalledOnce());
        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith(expect.objectContaining({ color: 'teal' })),
        );
    });

    it('reports a refused reopen instead of letting it escape', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: {
                    ...makePaperData([]),
                    missing_required_steps: ['student_card'],
                    reopenable_steps: ['student_card'],
                },
            },
            isPending: false,
        });
        reopenStepsMutateAsyncMock.mockRejectedValueOnce(new Error('E_UNPROCESSABLE_ENTITY'));
        render(<UserPapers id="42" status="pending" />);

        screen.getByRole('button', { name: 'reopen steps' }).click();

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith(expect.objectContaining({ color: 'red' })),
        );
    });

    it('splits a pending file into documents to reopen and documents already requested', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: {
                    ...makePaperData([]),
                    missing_required_steps: ['student_card', 'work_permit'],
                    reopenable_steps: ['work_permit'],
                },
            },
            isPending: false,
        });
        render(<UserPapers id="42" status="pending" />);

        expect(screen.getByText('not on file: work_permit')).toBeInTheDocument();
        expect(screen.getByText('awaiting: student_card')).toBeInTheDocument();
    });

    it('lists every missing document as not on file for an approved file', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: { ...makePaperData([]), missing_required_steps: ['student_card'], reopenable_steps: [] },
            },
            isPending: false,
        });
        render(<UserPapers id="42" status="approved" />);

        expect(screen.getByText('not on file: student_card')).toBeInTheDocument();
        expect(screen.queryByText(/awaiting/)).not.toBeInTheDocument();
    });

    it('shows only the waiting notice once every missing document is requested', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), missing_required_steps: ['work_permit'], reopenable_steps: [] } },
            isPending: false,
        });
        render(<UserPapers id="42" status="pending" />);

        expect(screen.queryByRole('button', { name: 'reopen steps' })).not.toBeInTheDocument();
        expect(screen.queryByText(/not on file/)).not.toBeInTheDocument();
        expect(screen.getByText('awaiting: work_permit')).toBeInTheDocument();
    });

    it('trusts the papers response over a stale pending status from the detail page', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: {
                    ...makePaperData([]),
                    status: 'wait_for_approval',
                    missing_required_steps: ['student_card'],
                    reopenable_steps: [],
                },
            },
            isPending: false,
        });
        render(<UserPapers id="42" status="pending" />);

        expect(screen.queryByRole('button', { name: 'reopen steps' })).not.toBeInTheDocument();
        expect(screen.queryByText(/awaiting/)).not.toBeInTheDocument();
        expect(screen.getByText('not on file: student_card')).toBeInTheDocument();
    });

    it('does not offer the reopen without the approve permission', () => {
        usePermissionsMock.mockReturnValue({ USER_PAPER_LIST: true, USER_PAPER_APPROVE: false });
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: {
                    ...makePaperData([]),
                    missing_required_steps: ['student_card'],
                    reopenable_steps: ['student_card'],
                },
            },
            isPending: false,
        });
        render(<UserPapers id="42" status="pending" />);

        expect(screen.queryByRole('button', { name: 'reopen steps' })).not.toBeInTheDocument();
    });

    it('does not offer the completion request while the file is waiting for review', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), missing_required_steps: ['work_permit'] } },
            isPending: false,
        });
        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.queryByRole('button', { name: 'Request document completion' })).not.toBeInTheDocument();
    });

    it('warns about an expired residence card on the papers tab too', () => {
        const { rerender } = render(<UserPapers id="42" status="pending" />);

        expect(
            screen.queryByText('Residence card has expired or is about to expire, please update'),
        ).not.toBeInTheDocument();

        rerender(<UserPapers id="42" status="pending" residenceCardExpiryAlert />);

        expect(screen.getByText('Residence card has expired or is about to expire, please update')).toBeInTheDocument();
    });

    it('shows Paper status badge', () => {
        render(<UserPapers id="42" status="approved" />);

        expect(screen.getByText('Paper status')).toBeInTheDocument();
        expect(screen.getByText('approved')).toBeInTheDocument();
    });

    it('shows "Waiting for approval papers" section', () => {
        render(<UserPapers id="42" status="approved" />);

        expect(screen.getByText('Waiting for approval papers')).toBeInTheDocument();
    });

    it('renders pending items in the table', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1), makePaperItem(2)]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.getByText('file1.pdf')).toBeInTheDocument();
        expect(screen.getByText('file2.pdf')).toBeInTheDocument();
    });

    it('passes the last decision time as a confirmed approval while the file is approved', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), approved_at: '2026-09-01T09:00:00Z' } },
            isPending: false,
        });

        render(<UserPapers id="42" status="approved" />);

        expect(screen.getByTestId('paper-folders')).toHaveAttribute(
            'data-confirmed-approval-at',
            '2026-09-01T09:00:00Z',
        );
    });

    it('does not confirm the last decision as an approval while a new submission is under review', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), approved_at: '2026-09-01T09:00:00Z' } },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.getByTestId('paper-folders')).toHaveAttribute('data-confirmed-approval-at', '');
    });

    it('"Process papers" button hidden when no pending items', () => {
        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.queryByRole('button', { name: 'Process papers' })).not.toBeInTheDocument();
    });

    it('"Process papers" button disabled when status is not wait_for_approval', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1)]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="approved" />);

        expect(screen.getByRole('button', { name: 'Process papers' })).toBeDisabled();
    });

    it('"Process papers" button enabled when status is wait_for_approval and approved', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1)]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.getByRole('button', { name: 'Process papers' })).not.toBeDisabled();
    });

    it('drops the standing mismatch warning for an admin account but keeps it in the approve modal', async () => {
        const user = userEvent.setup();
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: {
                    ...makePaperData([makePaperItem(1)]),
                    document_mismatches: [{ field: 'name', values: [] }],
                    is_web_admin: true,
                },
            },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        // The account files nothing, so the tab does not stand there warning about it
        expect(screen.queryByTestId('mismatch-alert')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Process papers' }));

        // But the admin about to approve those documents still has to be told they disagree
        expect(screen.getByTestId('mismatch-alert')).toBeInTheDocument();
    });

    it('shows the mismatch warning on the tab for an ordinary employee', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: { ...makePaperData([]), document_mismatches: [{ field: 'name', values: [] }] },
            },
            isPending: false,
        });

        render(<UserPapers id="42" status="approved" />);

        expect(screen.getByTestId('mismatch-alert')).toBeInTheDocument();
    });

    it('clicking "Process papers" opens modal', async () => {
        const user = userEvent.setup();
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1)]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        await user.click(screen.getByRole('button', { name: 'Process papers' }));

        expect(screen.getByTestId('modal')).toBeInTheDocument();
    });

    it('opening "Process papers" extracts OCR for the pending OCR-able types and shows the result view', async () => {
        const user = userEvent.setup();
        extractMutateAsyncMock.mockResolvedValue({
            data: { results: [{ group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] }] },
        });
        useGetUserPapersQueryMock.mockReturnValue({
            data: {
                data: makePaperData([
                    makePaperItem(1, { type: 'passport' }),
                    makePaperItem(2, { type: 'other' }),
                    // Already-processed papers must not be sent for extraction again
                    makePaperItem(3, { type: 'foreigner_card_front', approved_at: '2024-01-02T00:00:00Z' }),
                ]),
            },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        await user.click(screen.getByRole('button', { name: 'Process papers' }));

        await waitFor(() => expect(extractMutateAsyncMock).toHaveBeenCalledWith({ types: ['passport'], force: false }));
        expect(await screen.findByTestId('ocr-result-view')).toHaveAttribute('data-groups', 'passport');
    });

    it('does not extract OCR when no pending paper type is OCR-able', async () => {
        const user = userEvent.setup();
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1, { type: 'other' })]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        await user.click(screen.getByRole('button', { name: 'Process papers' }));

        expect(extractMutateAsyncMock).not.toHaveBeenCalled();
        expect(screen.queryByTestId('ocr-result-view')).not.toBeInTheDocument();
        expect(screen.queryByText('Extracted information')).not.toBeInTheDocument();
    });

    it('shows an error but keeps the approval form usable when OCR extraction fails', async () => {
        const user = userEvent.setup();
        extractMutateAsyncMock.mockRejectedValue(new Error('boom'));
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1, { type: 'passport' })]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        await user.click(screen.getByRole('button', { name: 'Process papers' }));

        expect(await screen.findByTestId('alert')).toHaveTextContent('error|Could not read from image');
        expect(screen.getByRole('button', { name: 'Submit' })).toBeInTheDocument();
    });

    it('re-runs OCR with the force flag from the result view', async () => {
        const user = userEvent.setup();
        extractMutateAsyncMock.mockResolvedValue({
            data: { results: [{ group: 'passport', types: ['passport'], status: 'ok', fields: {}, warnings: [] }] },
        });
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1, { type: 'passport' })]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        await user.click(screen.getByRole('button', { name: 'Process papers' }));
        await screen.findByTestId('ocr-result-view');
        extractMutateAsyncMock.mockClear();

        await user.click(screen.getByRole('button', { name: 'Re-run' }));

        await waitFor(() => expect(extractMutateAsyncMock).toHaveBeenCalledWith({ types: ['passport'], force: true }));
    });

    it('shows no delete action for papers waiting for approval', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(5)]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.queryByRole('button', { name: '🗑' })).not.toBeInTheDocument();
        expect(screen.queryByText('Action')).not.toBeInTheDocument();
    });

    /**
     * The date lives on the information tab, so the papers tab has to say so before the
     * admin commits to a decision the backend would only refuse at the last moment.
     */
    it('blocks the approval of a study abroad file with no student card expiry date', async () => {
        const user = userEvent.setup();
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([makePaperItem(1)]), student_card_expiry_missing: true } },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.getAllByTestId('student-card-expiry-alert').length).toBeGreaterThan(0);

        await user.click(screen.getByRole('button', { name: 'Process papers' }));

        expect(screen.getByRole('button', { name: 'Submit' })).toBeDisabled();
    });

    it('leaves the approval alone when the student card expiry date is on file', async () => {
        const user = userEvent.setup();
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([makePaperItem(1)]), student_card_expiry_missing: false } },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.queryByTestId('student-card-expiry-alert')).not.toBeInTheDocument();

        await user.click(screen.getByRole('button', { name: 'Process papers' }));

        expect(screen.getByRole('button', { name: 'Submit' })).not.toBeDisabled();
    });

    /**
     * The flag can go stale — someone cleared the date on another screen after this tab
     * loaded — so the refusal that comes back has to name the fix, not just fail.
     */
    it('names the missing student card expiry date when the backend refuses the approval', async () => {
        const user = userEvent.setup();
        approveMutateAsyncMock.mockRejectedValueOnce(
            new ApiError({ status: 422, message: 'USER_PAPER_STUDENT_CARD_EXPIRY_MISSING', data: {} }),
        );
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: makePaperData([makePaperItem(1)]) },
            isPending: false,
        });

        render(<UserPapers id="42" status="wait_for_approval" />);

        await user.click(screen.getByRole('button', { name: 'Process papers' }));
        await user.click(screen.getByRole('button', { name: 'Submit' }));

        await waitFor(() =>
            expect(notificationsShowMock).toHaveBeenCalledWith({
                color: 'red',
                message: 'error|Cannot approve: the student card has no expiry date',
            }),
        );
    });

    /**
     * The same escape hatch the missing-documents alert offers, for a date the admin cannot
     * read off the images. The confirm modal has to name what the employee is being asked
     * for: "file the missing documents" is wrong for someone whose documents are all there.
     */
    it('asks the employee for the student card again through the same confirm modal', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), student_card_expiry_missing: true } },
            isPending: false,
        });
        render(<UserPapers id="42" status="approved" />);

        screen.getByRole('button', { name: 'Ask for the student card again' }).click();

        expect(upcomingShiftsRefetchMock).toHaveBeenCalledOnce();
        expect(await screen.findByTestId('completion-request-modal')).toHaveTextContent(
            'The employee will be asked to submit their student card again',
        );

        screen.getByRole('button', { name: 'confirm completion' }).click();
        await waitFor(() => expect(requestCompletionMutateAsyncMock).toHaveBeenCalledOnce());
    });

    it('keeps the missing-documents wording when nothing but documents are missing', async () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), missing_required_steps: ['work_permit'] } },
            isPending: false,
        });
        render(<UserPapers id="42" status="approved" />);

        screen.getByRole('button', { name: 'Request document completion' }).click();

        expect(await screen.findByTestId('completion-request-modal')).toHaveTextContent('default message');
    });

    /**
     * The file the feature is really aimed at: approved before 資格外活動許可 became
     * mandatory, with a student card that has been on file all along and no date on it. One
     * request reopens both, so the confirm modal has to name both — whichever button opened
     * it. Reading the wording off the button would have the admin confirm the narrower of
     * the two and get the wider one.
     */
    it.each([['Request document completion'], ['Ask for the student card again']])(
        'names both reasons when both are true, opened from %s',
        async (button) => {
            useGetUserPapersQueryMock.mockReturnValue({
                data: {
                    data: {
                        ...makePaperData([]),
                        missing_required_steps: ['work_permit'],
                        student_card_expiry_missing: true,
                    },
                },
                isPending: false,
            });
            render(<UserPapers id="42" status="approved" />);

            screen.getByRole('button', { name: button }).click();

            const modal = await screen.findByTestId('completion-request-modal');
            expect(modal).toHaveTextContent('The employee will be asked to file the missing documents, and to submit');
            expect(modal).toHaveTextContent('their student card again');
        },
    );

    it('does not offer the student card request while the file is waiting for review', () => {
        useGetUserPapersQueryMock.mockReturnValue({
            data: { data: { ...makePaperData([]), student_card_expiry_missing: true } },
            isPending: false,
        });
        render(<UserPapers id="42" status="wait_for_approval" />);

        expect(screen.queryByRole('button', { name: 'Ask for the student card again' })).not.toBeInTheDocument();
    });

    it('renders PaperFolders for processed items', () => {
        render(<UserPapers id="42" status="approved" />);

        expect(screen.getByTestId('paper-folders')).toBeInTheDocument();
    });
});
